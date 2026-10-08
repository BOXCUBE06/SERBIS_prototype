<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AdminSections;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * There are two kinds of account. A super admin (`is_super_admin`) sees every
 * section of the panel, is the only kind that can open this page at all, and is
 * the only one that can change anyone's access. Every other admin holds a list
 * of sections (`permissions`, App\Support\AdminSections) and reaches only
 * those. Staff Accounts is deliberately not a section that can be handed out:
 * the page creates accounts, resets passwords and edits access, so an admin
 * holding it could reset a super admin's password and sign in as one.
 *
 * That is why the checks in here on a super-admin target look redundant with
 * the route's own gate. They are. The gate is the reason a non-super caller
 * never gets this far; the checks are what keep that true if the gate is ever
 * loosened.
 *
 * The first super admin is chosen on purpose, per environment, with
 * `php artisan staff:make-super-admin <email>`. Nothing promotes anyone
 * automatically. An account created here starts with no sections at all.
 *
 * There is no emailed reset: staff addresses are made-up @serbis.com usernames
 * and no mail is sent. Recovery is another admin choosing "Reset password" in
 * the panel (resetPassword()), which hands back a temporary password and forces
 * its owner to replace it. When no admin can sign in at all, the
 * `staff:reset-password` command does the same from the server.
 *
 * **Closing an account is deactivation, never deletion.** It ends access, leaves
 * every log row still naming who did it, and can always be undone with
 * Reactivate. Accounts with no history used to be deleted outright, which made
 * the same red button reversible for some accounts and not others; since
 * 2026-10-05 every account is deactivated, a typo included.
 *
 * The bulk routes (bulkClose, bulkReactivate, bulkPermissions) run the very same
 * per-account checks as the single ones, one account at a time, and report
 * which accounts were refused and why instead of failing the whole request.
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
    public function index()
    {
        // Ordered so the list does not reshuffle between edits. `password` is
        // Hidden on the model, so it cannot reach a client from here.
        return response()->json([
            'data' => User::orderBy('last_name')->orderBy('first_name')->get(),
            // So the page can say how urgent a missing phone number is.
            'admin_mfa_enabled' => (bool) config('serbis.admin_mfa_enabled'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => ['required', 'string', 'regex:'.User::USERNAME_REGEX, 'unique:tbl_user,username'],
            // Where the sign-in code goes once ADMIN_MFA_ENABLED is on.
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            // Optional starting access (the panel's access templates), checked
            // exactly as the access endpoints check it.
            ...self::permissionRules(),
        ], [
            'username.regex' => User::USERNAME_MESSAGE,
            ...self::PERMISSION_MESSAGES,
        ]);

        // Assigned key by key, and `role` is not among the rules. It is set
        // here, never taken from the request: a client-settable role is how an
        // account ends up with a value the is.admin middleware does not
        // recognise, locking the account out of the panel it was made for.
        $admin = new User([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'username' => $validated['username'],
            'phone_number' => PhoneNumber::normalize($validated['phone_number']),
            'password' => $validated['password'],
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        // No sections unless the request names some (an access template); a
        // super admin grants more later (updatePermissions). An empty list, not
        // NULL: NULL is the unrestricted value every account that existed
        // before permissions did keeps. Set before the first save so the
        // account is never briefly open to everything, and so the creation is
        // one row in the log rather than two.
        $admin->permissions = array_values(array_intersect(AdminSections::ASSIGNABLE, $validated['permissions'] ?? []));
        // The password was typed by whoever made the account, so it is a
        // temporary one, the same as after resetPassword: the owner replaces it
        // at first sign-in before IsAdmin lets them further.
        $admin->must_change_password = true;
        $admin->save();

        // Re-read, so the response carries the columns the database defaulted
        // (is_super_admin) that the in-memory instance never saw.
        return response()->json($admin->fresh(), 201);
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

        if ($refusal = $this->refuseSuperAdminTarget($request, $admin)) {
            return $refusal;
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => [
                'required',
                'string',
                'regex:'.User::USERNAME_REGEX,
                Rule::unique('tbl_user', 'username')->ignore($admin->getKey(), 'admin_id'),
            ],
            // Checked only when sent, so an account made before staff had
            // numbers can still be edited without one.
            'phone_number' => ['sometimes', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX],
            // Blank leaves the stored hash alone. Assigning null would lock the
            // account out of its own panel.
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ], [
            'username.regex' => User::USERNAME_MESSAGE,
        ]);

        $admin->first_name = $validated['first_name'];
        $admin->last_name = $validated['last_name'];
        $admin->username = $validated['username'];

        if (array_key_exists('phone_number', $validated)) {
            $admin->phone_number = PhoneNumber::normalize($validated['phone_number']);
        }

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
     * Ends an account's access by deactivating it. Never deletes: see the class
     * comment. Reactivate undoes it.
     */
    public function destroy(Request $request, $id)
    {
        $admin = User::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        if ($refusal = $this->closeOne($request, $admin)) {
            return $refusal;
        }

        return response()->json([
            'message' => 'Admin account deactivated. It can be reactivated later.',
            'deactivated' => true,
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

        if ($refusal = $this->reactivateOne($request, $admin)) {
            return $refusal;
        }

        return response()->json($admin);
    }

    /** Closes each listed account in turn, with destroy()'s checks for each. */
    public function bulkClose(Request $request)
    {
        return $this->eachAdmin($request, fn (User $admin) => $this->closeOne($request, $admin));
    }

    /** Reactivates each listed account in turn, with reactivate()'s checks for each. */
    public function bulkReactivate(Request $request)
    {
        return $this->eachAdmin($request, fn (User $admin) => $this->reactivateOne($request, $admin));
    }

    /** Gives every listed account the same access, with updatePermissions()'s checks for each. */
    public function bulkPermissions(Request $request)
    {
        if ($refusal = $this->refuseNonSuperCaller($request)) {
            return $refusal;
        }

        $validated = $this->validatePermissions($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        return $this->eachAdmin($request, fn (User $admin) => $this->permissionsOne($admin, $validated));
    }

    /**
     * Sets which sections an account may open, and whether it is a super admin.
     * Either or both may be sent; what is not sent is left alone.
     *
     * Only a super admin gets here (the route's own gate), and the list is
     * checked against the sections that can be handed out — Staff Accounts is
     * not one of them, so it can be neither granted nor stored by a hand-built
     * request. The account's own next request sees the change: access is read
     * from the row on every call, not from anything in the token.
     *
     * The last active super admin cannot be demoted, whoever asks, themselves
     * included. See isLastActiveSuperAdmin().
     */
    public function updatePermissions(Request $request, $id)
    {
        if ($refusal = $this->refuseNonSuperCaller($request)) {
            return $refusal;
        }

        $admin = User::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        $validated = $this->validatePermissions($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        if ($refusal = $this->permissionsOne($admin, $validated)) {
            return $refusal;
        }

        return response()->json($admin->fresh());
    }

    private function refuseNonSuperCaller(Request $request)
    {
        $caller = $request->user();

        if (! $caller instanceof User || ! $caller->isSuperAdmin()) {
            return response()->json([
                'message' => 'Only a super admin can change who may open what.',
                'code' => 'section_forbidden',
            ], 403);
        }

        return null;
    }

    private const PERMISSION_MESSAGES = [
        'permissions.*.in' => 'That is not a section that can be given to an account.',
    ];

    /** A list of sections that can be given out: Staff Accounts and unknown keys are refused. */
    private static function permissionRules(): array
    {
        return [
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(AdminSections::ASSIGNABLE)],
        ];
    }

    /** The access fields, validated; a 422 response when neither was sent. */
    private function validatePermissions(Request $request)
    {
        $validated = $request->validate([
            'is_super_admin' => ['sometimes', 'boolean'],
            ...self::permissionRules(),
        ], self::PERMISSION_MESSAGES);

        if (! array_key_exists('is_super_admin', $validated) && ! array_key_exists('permissions', $validated)) {
            return response()->json([
                'message' => 'Send is_super_admin, permissions, or both.',
                'errors' => ['permissions' => ['Send is_super_admin, permissions, or both.']],
            ], 422);
        }

        return $validated;
    }

    /** Applies validated access to one account; a refusal response, or null once saved. */
    private function permissionsOne(User $admin, array $validated)
    {
        if (array_key_exists('is_super_admin', $validated)
            && ! $validated['is_super_admin']
            && $this->isLastActiveSuperAdmin($admin)) {
            return response()->json([
                'message' => 'This is the only active super admin. Make another account a super admin before removing this one.',
            ], 422);
        }

        if (array_key_exists('is_super_admin', $validated)) {
            $admin->is_super_admin = (bool) $validated['is_super_admin'];
        }

        if (array_key_exists('permissions', $validated)) {
            // Re-indexed and in sidebar order, so two saves of the same choice
            // are the same row and the log only records real changes.
            $admin->permissions = array_values(array_intersect(AdminSections::ASSIGNABLE, $validated['permissions']));
        }

        $admin->save();

        return null;
    }

    /**
     * Deactivates one account; a refusal response, or null once closed.
     *
     * Three refusals, and all exist because the panel has no other way back in.
     * Closing your own account ends your session mid-click; closing the last
     * active one leaves an office with a running system and no way to sign into
     * it; and closing the last active super admin leaves nobody who can change
     * anyone's access. Checked per account, so a bulk close that would empty the
     * office stops at the account that would have done it.
     */
    private function closeOne(Request $request, User $admin)
    {
        if ($refusal = $this->refuseSuperAdminTarget($request, $admin)) {
            return $refusal;
        }

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

        if ($this->isLastActiveSuperAdmin($admin)) {
            return response()->json([
                'message' => 'This is the only active super admin. Make another account a super admin before closing it.',
            ], 422);
        }

        // Sanctum resolves a token to its owner on every request, so an
        // already-issued one would otherwise keep working for the rest of its
        // 8-hour life against an account that has just been closed.
        $admin->tokens()->delete();
        $admin->status = 'Inactive';
        $admin->save();

        return null;
    }

    private function reactivateOne(Request $request, User $admin)
    {
        if ($refusal = $this->refuseSuperAdminTarget($request, $admin)) {
            return $refusal;
        }

        $admin->status = 'Active';
        $admin->save();

        return null;
    }

    /**
     * Runs $action on every account in `ids`, in the order sent. `done` lists the
     * ids it succeeded on; `failed` names each account it did not, with the same
     * message the single route would have answered.
     */
    private function eachAdmin(Request $request, callable $action)
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            $admin = User::find($id);

            if (! $admin) {
                $failed[] = ['id' => (int) $id, 'name' => null, 'message' => 'Admin not found'];

                continue;
            }

            $refusal = $action($admin);

            if ($refusal === null) {
                $done[] = (int) $id;
            } else {
                $failed[] = [
                    'id' => (int) $id,
                    'name' => trim($admin->first_name.' '.$admin->last_name),
                    'message' => $refusal->getData(true)['message'] ?? 'Refused',
                ];
            }
        }

        return response()->json(['done' => $done, 'failed' => $failed]);
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

        if ($refusal = $this->refuseSuperAdminTarget($request, $admin)) {
            return $refusal;
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
     * True when $admin is a super admin who can still sign in and is the only
     * one. They are the one account that can change anyone's access, so closing
     * or demoting them would leave the panel with no way to fix it short of
     * `staff:make-super-admin` on the server. A deactivated super admin is not
     * counted: they cannot sign in, so they are not a way back in.
     */
    private function isLastActiveSuperAdmin(User $admin): bool
    {
        return $admin->isSuperAdmin()
            && $this->isActive($admin)
            && User::activeSuperAdminCount() <= 1;
    }

    /**
     * Refuses an action on a super admin's account from anyone who is not one.
     *
     * Editing a super admin's address or password, resetting it, or closing and
     * reopening it are all ways to become one. The route's gate already keeps a
     * non-super caller out of this controller; this is what holds if it is ever
     * opened, so it is checked per target rather than assumed.
     */
    private function refuseSuperAdminTarget(Request $request, User $target)
    {
        $caller = $request->user();

        if ($target->isSuperAdmin() && (! $caller instanceof User || ! $caller->isSuperAdmin())) {
            return response()->json([
                'message' => 'Only a super admin can change a super admin\'s account.',
                'code' => 'section_forbidden',
            ], 403);
        }

        return null;
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
