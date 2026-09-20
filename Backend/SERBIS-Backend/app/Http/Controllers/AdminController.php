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
 * There is no emailed reset: staff addresses are made-up @serbis.com usernames
 * and no mail is sent. Recovery is another admin choosing "Reset password" in
 * the panel (resetPassword()), which hands back a temporary password and forces
 * its owner to replace it. When no admin can sign in at all, the
 * `staff:reset-password` command does the same from the server.
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
    /**
     * Staff sign in with a made-up address on the office's own domain, not a
     * mailbox anyone reads — no mail is ever sent to it. Only the name part is
     * free; the suffix is fixed. Enforced on create, and on edit only when the
     * address actually changes: accounts made before this rule keep their
     * existing address and must still be savable.
     */
    private const STAFF_EMAIL_REGEX = '/^[a-z0-9]+(\.[a-z0-9]+)*@serbis\.com$/';

    private const STAFF_EMAIL_MESSAGE = 'Use lowercase letters, digits and dots only, ending in @serbis.com.';

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
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email_address' => ['required', 'email', 'max:255', 'regex:'.self::STAFF_EMAIL_REGEX, 'unique:tbl_user,email_address'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ], [
            'email_address.regex' => self::STAFF_EMAIL_MESSAGE,
        ]);

        // Assigned key by key, and `role` is not among the rules. It is set
        // here, never taken from the request: a client-settable role is how an
        // account ends up with a value the is.admin middleware does not
        // recognise, locking the account out of the panel it was made for.
        $admin = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email_address' => $validated['email_address'],
            'password' => $validated['password'],
            'role' => 'Admin',
            'status' => 'Active',
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

        $emailRules = [
            'required',
            'email',
            'max:255',
            Rule::unique('tbl_user', 'email_address')->ignore($admin->getKey(), 'admin_id'),
        ];

        if ((string) $request->input('email_address') !== (string) $admin->email_address) {
            $emailRules[] = 'regex:'.self::STAFF_EMAIL_REGEX;
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email_address' => $emailRules,
            // Blank leaves the stored hash alone. Assigning null would lock the
            // account out of its own panel.
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ], [
            'email_address.regex' => self::STAFF_EMAIL_MESSAGE,
        ]);

        $admin->first_name = $validated['first_name'];
        $admin->last_name = $validated['last_name'];
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
     * Hands a staff member a temporary password when they cannot sign in.
     *
     * No mail is involved: the password comes back in this one response, for
     * the admin to pass on in person, and is never stored or logged in the
     * clear. The account is flagged so its owner must replace it before the
     * panel lets them do anything (IsAdmin), and every session it held ends,
     * since a reset is what you do when a credential may have leaked.
     *
     * Refused for yourself: your own password changes through
     * /admin/change-password, which asks for the current one. Otherwise a
     * hijacked session could reset its way to a password the owner never saw.
     */
    public function resetPassword(Request $request, $id)
    {
        $admin = User::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        if ((int) $admin->getKey() === (int) $request->user()->getKey()) {
            return response()->json([
                'message' => 'You cannot reset your own password this way. Ask another admin to do it.',
            ], 422);
        }

        if ($admin->isDeactivated()) {
            return response()->json([
                'message' => 'This account is deactivated. Reactivate it before resetting its password.',
            ], 422);
        }

        $temporary = User::generateTemporaryPassword();

        // Assigned key by key: neither column is mass-assignable. The model
        // casts `password`, so it is hashed on save.
        $admin->password = $temporary;
        $admin->must_change_password = true;
        $admin->save();
        $admin->tokens()->delete();

        return response()->json([
            'temporary_password' => $temporary,
        ])->header('Cache-Control', 'no-store');
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
     * between deleting and deactivating. SMS blasts count too: they write no
     * audit row but hold their own RESTRICT key on the sender.
     */
    private function hasHistory(User $admin): bool
    {
        return DB::table('tbl_system_logs')->where('admin_id', $admin->getKey())->exists()
            || DB::table('tbl_sms_logs')->where('sender_id', $admin->getKey())->exists();
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
