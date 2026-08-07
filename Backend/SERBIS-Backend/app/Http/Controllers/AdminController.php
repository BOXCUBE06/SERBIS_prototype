<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * MDRRMO staff accounts.
 *
 * Audit #29: there was no route here at all. The first admin and every admin
 * after it was a hand-written INSERT, which meant the office could not add or
 * remove its own staff without someone holding database credentials — and a
 * departing employee's account could only be closed the same way.
 *
 * Every admin is trusted equally: any admin may create, edit and remove any
 * other. There is no super-admin tier, deliberately. A role column that only
 * ever holds one value is a tier nobody administers, and the alternative is a
 * permission model this office does not need.
 *
 * There is still no self-serve password reset. `MAIL_MAILER=log` means mail
 * goes to a log file, so a reset link would be sent to nobody. Recovery is
 * another admin setting the password from the panel, which is a real path and
 * needs no mail transport. See the panel's Staff view.
 *
 * **Closing an account is deactivation, not deletion.** `tbl_system_logs`
 * carries a foreign key to this table, so an admin who has ever done anything
 * cannot be deleted at all — the first draft of this controller answered 500
 * on exactly the case the feature exists for, a departing employee. Deleting
 * is kept only for an account with no history, which is what a typo looks
 * like; everything else is deactivated, which ends access and leaves every log
 * row still naming who did it.
 */
class AdminController extends Controller
{
    public function index()
    {
        // Ordered so the list does not reshuffle between edits. `password` is
        // Hidden on the model, so it cannot reach a client from here.
        return response()->json([
            'data' => User::orderBy('last_name')->orderBy('first_name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'email_address' => 'required|email|max:255|unique:tbl_user,email_address',
            'password'      => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Assigned key by key, and `role` is not among the rules. It is set
        // here, never taken from the request: a client-settable role is how an
        // account ends up with a value the is.admin middleware does not
        // recognise, locking the account out of the panel it was made for.
        $admin = User::create([
            'first_name'    => $validated['first_name'],
            'last_name'     => $validated['last_name'],
            'email_address' => $validated['email_address'],
            'password'      => $validated['password'],
            'role'          => 'Admin',
            'status'        => 'Active',
        ]);

        return response()->json($admin, 201);
    }

    public function show($id)
    {
        $admin = User::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        return response()->json($admin);
    }

    public function update(Request $request, $id)
    {
        $admin = User::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        $validated = $request->validate([
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'email_address' => [
                'required',
                'email',
                'max:255',
                Rule::unique('tbl_user', 'email_address')->ignore($admin->getKey(), 'admin_id'),
            ],
            // Blank leaves the stored hash alone. Assigning null would lock the
            // account out of its own panel.
            'password'      => ['nullable', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $admin->first_name    = $validated['first_name'];
        $admin->last_name     = $validated['last_name'];
        $admin->email_address = $validated['email_address'];

        $passwordChanged = ! empty($validated['password']);

        if ($passwordChanged) {
            // The model casts this, so it is never stored in the clear.
            $admin->password = $validated['password'];
        }

        $admin->save();

        if ($passwordChanged) {
            $this->revokeTokens($admin, $request);
        }

        return response()->json($admin);
    }

    /**
     * Ends an account's access.
     *
     * Deactivates by default and deletes only an account with nothing recorded
     * against it. A row with log history cannot be deleted at all — the foreign
     * key refuses — and forcing it would take the name off every action that
     * admin ever took.
     */
    public function destroy(Request $request, $id)
    {
        $admin = User::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        // Two refusals, and both exist because the panel has no other way back
        // in. Closing your own account ends your session mid-click; closing the
        // last active one leaves an office with a running system and no way to
        // sign into it, recoverable only by the hand-written INSERT this whole
        // feature replaced.
        if ((int) $admin->getKey() === (int) $request->user()->getKey()) {
            return response()->json([
                'message' => 'You cannot close your own account. Ask another admin to do it.',
            ], 422);
        }

        if ($this->activeCount() <= 1 && $this->isActive($admin)) {
            return response()->json([
                'message' => 'This is the only active admin account. Create another one before closing it.',
            ], 422);
        }

        // Tokens go either way. Sanctum resolves a token to its owner on every
        // request, so an already-issued one would otherwise keep working for
        // the rest of its 8-hour life against an account that has just been
        // closed.
        $admin->tokens()->delete();

        if ($this->hasHistory($admin)) {
            $admin->status = 'Inactive';
            $admin->save();

            return response()->json([
                'message' => 'Admin account deactivated. Their name stays on the actions they took.',
                'deactivated' => true,
            ]);
        }

        $admin->delete();

        return response()->json([
            'message' => 'Admin account removed',
            'deactivated' => false,
        ]);
    }

    /**
     * Puts a deactivated account back to work, which is the other half of
     * closing one — an employee returning from leave should not need a new
     * account and a new name in the log.
     */
    public function reactivate(Request $request, $id)
    {
        $admin = User::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        $admin->status = 'Active';
        $admin->save();

        return response()->json($admin);
    }

    /**
     * Counts accounts that can still sign in. Mirrors `User::isDeactivated()`:
     * anything not explicitly Inactive counts, including the null a
     * hand-written INSERT leaves behind.
     */
    private function activeCount(): int
    {
        return User::where(function ($query) {
            $query->whereNull('status')->orWhereRaw('LOWER(status) != ?', ['inactive']);
        })->count();
    }

    private function isActive(User $admin): bool
    {
        return ! $admin->isDeactivated();
    }

    /**
     * True when anything in the audit trail points at this account. That
     * foreign key is what makes deletion impossible, so it is also what decides
     * between deleting and deactivating.
     */
    private function hasHistory(User $admin): bool
    {
        return DB::table('tbl_system_logs')->where('admin_id', $admin->getKey())->exists();
    }

    /**
     * Ends every session held on this account.
     *
     * A password change that leaves old tokens valid is not a password change:
     * the case this exists for is an account whose credentials leaked, and the
     * copy of the token taken beforehand would otherwise keep working for the
     * rest of its 8-hour life.
     *
     * The one token spared is the caller's own, and only when they are changing
     * their own password — logging someone out of the click they just made
     * reads as a failure and sends them back to re-enter the password they just
     * set.
     */
    private function revokeTokens(User $admin, Request $request): void
    {
        $current = $request->user()->currentAccessToken();
        $isSelf = (int) $admin->getKey() === (int) $request->user()->getKey();

        $query = $admin->tokens();

        if ($isSelf && $current !== null) {
            $query->where('id', '!=', $current->getKey());
        }

        $query->delete();
    }
}
