<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SendsResidentCodes;
use App\Models\Resident;
use App\Models\User; // Represents Admins/Staff
use App\Rules\PhoneAvailable;
use App\Services\Totp;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    use SendsResidentCodes;

    /**
     * How long an unfinished sign-up survives. Two clocks run on a pending
     * sign-up and they are not the same one: the code inside it expires after
     * Resident::CODE_TTL_MINUTES, while the sign-up itself — the name, the
     * barangay, the hashed password — stays put for a day so a resident who put
     * the phone down can come back and tap Resend rather than retype the form.
     * Holding it costs nothing: a pending sign-up never blocks the address, so
     * only the person who started it is affected by how long it lasts.
     */
    private const SIGNUP_WINDOW_HOURS = 24;

    /**
     * How many wrong guesses one sign-up code tolerates before it is retired.
     * The same figure the login challenge uses (consumeMfaChallengeAttempt),
     * and for the same reason: without it the only brake on guessing a
     * six-digit code was the route's rate limiter, whose tight tier keys on
     * the IP as well as the address — so the ceiling was per source address
     * rather than per account, and distributed guessing scaled with the number
     * of addresses an attacker had.
     */
    private const MAX_SIGNUP_ATTEMPTS = 5;

    /**
     * What an old app is told, in both languages, because the old app shows the
     * server's `message` as-is on any failed request. The app that ships with
     * phone login never sends an email address, so an email in the body is the
     * tell that this is the old one.
     */
    private const APP_UPDATE_MESSAGE = 'Please update the SERBIS app to continue. / Paki-update ang SERBIS app para magpatuloy.';

    /**
     * 410 for a client that predates phone login. Sent by the routes that used
     * to take an email address, and removed in a later release once no such app
     * is still in use.
     */
    private function appUpdateRequired(): JsonResponse
    {
        return response()->json([
            'message' => self::APP_UPDATE_MESSAGE,
            'code' => 'app_update_required',
        ], 410);
    }

    /**
     * The two email-verification routes the old app called. The new app uses
     * /resident/verify-phone; nothing sensible remains for these to do.
     */
    public function emailVerificationRemoved(): JsonResponse
    {
        return $this->appUpdateRequired();
    }

    // Resident self-registration for the mobile app. Admins live in tbl_user and
    // are deliberately not creatable here — there is no public route that writes
    // to that table.
    //
    // A phone number is the login, so it is what a sign-up is keyed on and what
    // the code is texted to. Nothing is written to tbl_residents here: a sign-up
    // that has not proved it controls the number lives entirely in the cache
    // until the code comes back, and only verifyPhone() inserts the row. An
    // abandoned or hostile sign-up therefore never holds a number — the unique
    // index only ever sees accounts that finished — and it lapses on its own.
    //
    // The SMS spend: this route sends exactly one code per call and the
    // 'register' limiter (AppServiceProvider) caps it at 5/minute and 15/hour per
    // IP. Registering the same number again is allowed and simply issues a fresh
    // code, retiring the outstanding one.
    public function register(Request $request)
    {
        if ($request->has('email_address')) {
            return $this->appUpdateRequired();
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'barangay_id' => 'required|integer|exists:tbl_barangay,barangay_id',
            // Purok/street — the one thing barangay_id cannot answer (MDRRMO
            // feedback, 2026-09-19). Optional at registration, same as every
            // other field a resident might not have on hand yet; editable
            // later from the profile either way.
            'street_address' => 'nullable|string|max:255',
            // Refused here when a real account already holds the number, in any
            // spelling. That does tell a stranger the number is registered; the
            // route's per-IP limiter is what bounds using it to enumerate.
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX, new PhoneAvailable],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            // An individual (head of the family, the default) or an organization.
            // Barangay accounts are made by MDRRMO staff and are refused here, so
            // nobody can sign themselves up as one.
            'account_type' => ['sometimes', Rule::in([Resident::TYPE_HEAD_OF_FAMILY, Resident::TYPE_ORGANIZATION])],
            'organization_name' => 'required_if:account_type,'.Resident::TYPE_ORGANIZATION.'|nullable|string|max:150',
        ]);

        $accountType = $validated['account_type'] ?? Resident::TYPE_HEAD_OF_FAMILY;
        $phone = PhoneNumber::normalize($validated['phone_number']);

        // Every column is assigned explicitly rather than splatting $validated, so
        // no extra key in the payload can reach a column. Two matter in particular:
        // `status` (see below) and `role`, which a client must never set.
        $entry = $this->issueSignupCode([
            'attributes' => [
                'barangay_id' => $validated['barangay_id'],
                'street_address' => $validated['street_address'] ?? null,
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'phone_number' => $phone,
                // Hashed here rather than at insert time: the plain password must
                // not sit in the cache store for the life of the pending sign-up.
                'password' => Hash::make($validated['password']),
                // Starts Inactive on purpose. SmsController only blasts residents
                // with status 'Active', and SkySMS bills per real send with no
                // sandbox, so a self-registered account must not opt an unverified
                // phone number into paid SMS until an admin activates it from the
                // Users view.
                'status' => 'Inactive',
                // Inactive is also what keeps an organization from filing: the
                // filing endpoints refuse an organization that is not Active
                // (Resident::isAwaitingApproval), so it waits for an admin to
                // activate it. An individual is Inactive too and files as before.
                'account_type' => $accountType,
                'organization_name' => $accountType === Resident::TYPE_ORGANIZATION
                    ? $validated['organization_name']
                    : null,
            ],
        ]);

        if ($entry['delivery'] === 'failed') {
            return $this->smsUnavailable();
        }

        // Still no token and still no 'message' key — the mobile client reads any
        // `message` on this response as an error to show the resident.
        // `verification_required` is what sends it to the code screen, and
        // `phone_number` is what that screen verifies against.
        return response()->json([
            'verification_required' => true,
            'phone_number' => $phone,
        ] + $this->signupDeliveryPayload($entry), 201);
    }

    /**
     * Second half of registration: the code from the text comes back here and
     * the account is created, already verified. A token is issued on success so
     * the resident lands signed in rather than being handed to a login form.
     */
    public function verifyPhone(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string|max:20',
            'code' => 'required|string|size:6',
        ]);

        $phone = PhoneNumber::normalize($request->phone_number);

        // Pulled, not read: a code is single-use, and two taps of Submit that
        // arrive together must not both go on to insert a row. The loser of that
        // race finds no entry and falls through to a branch below rather than
        // hitting the unique index. A wrong guess must not spend the entry, so
        // that path puts it straight back.
        $entry = $phone === '' ? null : Cache::pull($this->pendingSignupKey($phone));

        if ($phone !== '' && Resident::where('phone_number', $phone)->exists()) {
            // Deliberately not a success. Returning a token here would mean any
            // string verifies an already-registered number.
            return response()->json([
                'message' => 'This number is already registered. Please log in.',
                'code' => 'already_verified',
            ], 422);
        }

        if (! is_array($entry)) {
            return response()->json([
                'message' => 'We could not find a sign-up for that number. It may have expired — please register again.',
                'code' => 'not_found',
            ], 404);
        }

        $bypassed = $this->otpBypassMatches((string) $request->code);

        if (! $bypassed && ! $this->signupCodeMatches($entry, (string) $request->code)) {
            if (! $this->spendSignupAttempt($phone, $entry)) {
                return response()->json([
                    'message' => 'Too many wrong codes. Ask for a new one.',
                    'code' => 'too_many_attempts',
                ], 429);
            }

            // One message for a wrong code and for an expired one. Separating
            // them tells someone guessing which half they got right.
            return response()->json([
                'message' => 'That code is not right, or it has expired. Ask for a new one.',
                'code' => 'invalid_code',
            ], 422);
        }

        try {
            $resident = $this->completeSignup($entry);
        } catch (UniqueConstraintViolationException) {
            // Someone else finished registering this number between the
            // register call and now. The unique index is what decides.
            return response()->json([
                'message' => 'This number is already registered. Please log in.',
                'code' => 'already_verified',
            ], 422);
        }

        if ($bypassed) {
            $this->logOtpBypassUse($resident);
        }

        return response()->json([
            'token' => $this->issueResidentToken($resident),
            'role' => 'resident',
            'user' => $resident->load('barangay'),
        ]);
    }

    /**
     * Issues a replacement code. Two limits apply: the route's rate limiter, and
     * a per-sign-up cooldown so one number cannot be used to send messages on a
     * timer.
     */
    public function resendVerificationCode(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string|max:20',
        ]);

        $phone = PhoneNumber::normalize($request->phone_number);

        if ($phone !== '' && Resident::where('phone_number', $phone)->exists()) {
            return response()->json([
                'message' => 'This number is already registered. Please log in.',
                'code' => 'already_verified',
            ], 422);
        }

        $entry = $phone === '' ? null : $this->pendingSignup($phone);

        // A 404 here says only that no sign-up is in flight for the number,
        // which registering would reveal anyway. Staying silent instead would
        // leave a resident who mistyped their own number waiting for a text that
        // is never coming.
        if ($entry === null) {
            return response()->json([
                'message' => 'We could not find a sign-up for that number. It may have expired — please register again.',
                'code' => 'not_found',
            ], 404);
        }

        if (($wait = $this->signupResendWait($entry)) > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before asking for another code.",
                'code' => 'resend_too_soon',
                'retry_after' => $wait,
            ], 429);
        }

        $entry = $this->issueSignupCode($entry);

        if ($entry['delivery'] === 'failed') {
            return $this->smsUnavailable();
        }

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
        ] + $this->signupDeliveryPayload($entry));
    }

    /**
     * Where a pending sign-up lives. Keyed by a hash of the number rather than
     * the number itself: the cache store is a file tree in production and a
     * table in development, and neither is a place to leave a list of every
     * phone number that has ever started registering.
     */
    private function pendingSignupKey(string $phone): string
    {
        return 'signup:pending:'.hash('sha256', $phone);
    }

    private function pendingSignup(string $phone): ?array
    {
        $entry = Cache::get($this->pendingSignupKey($phone));

        return is_array($entry) ? $entry : null;
    }

    /**
     * Writes a pending sign-up back with the time it has left, never a fresh
     * window. Re-storing an entry — after a wrong guess, say — must move
     * nothing, or handling a sign-up would keep it alive indefinitely.
     */
    private function putPendingSignup(string $phone, array $entry): void
    {
        $seconds = ($entry['signup_expires_at'] ?? 0) - now()->getTimestamp();

        if ($seconds <= 0) {
            Cache::forget($this->pendingSignupKey($phone));

            return;
        }

        Cache::put($this->pendingSignupKey($phone), $entry, $seconds);
    }

    /**
     * The plain code exists only inside this method. Everything stored is
     * hashed, so this is the single point where it can be sent.
     *
     * Issuing replaces any outstanding code rather than adding a second valid
     * one — otherwise every resend widens the window instead of moving it.
     *
     * Every value written here is a scalar. config/cache.php sets
     * 'serializable_classes' => false, so a stored object comes back as
     * __PHP_Incomplete_Class — the trap sendLoginCode() documents at length.
     * Times are Unix timestamps for that reason, never Carbon.
     *
     * Returns the stored entry plus `delivery` (accepted | unknown | failed).
     * A failed send leaves the entry — and the code, which nobody has — in place
     * so Resend can issue another straight away, and starts no cooldown: nothing
     * was billed, so there is nothing to protect.
     */
    private function issueSignupCode(array $entry): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $phone = (string) ($entry['attributes']['phone_number'] ?? '');

        $entry['code_hash'] = Hash::make($code);
        $entry['expires_at'] = now()->addMinutes(Resident::CODE_TTL_MINUTES)->getTimestamp();
        // A new code is a new secret, so it gets a fresh budget of guesses —
        // the same reset sendLoginCode() performs on a resent login code. What
        // stops that being a way around MAX_SIGNUP_ATTEMPTS is the resend
        // cooldown (Resident::RESEND_COOLDOWN_SECONDS) plus the route limiter:
        // buying another five guesses costs a minute's wait and a real text to
        // the number being attacked.
        $entry['attempts'] = 0;
        // Set once and carried through every reissue: resending moves the code's
        // clock, not the sign-up's, so a resident cannot hold an unfinished
        // sign-up open forever by tapping Resend.
        $entry['signup_expires_at'] ??= now()->addHours(self::SIGNUP_WINDOW_HOURS)->getTimestamp();

        $delivery = $this->sendOtpText(
            $phone,
            "Your SERBIS verification code is {$code}. It expires in ".Resident::CODE_TTL_MINUTES.' minutes.',
        );

        if ($delivery === 'failed') {
            unset($entry['sent_at']);
        } else {
            $entry['sent_at'] = now()->getTimestamp();
        }

        // Stored so a screen shown inside the cooldown still knows how the
        // outstanding code went out.
        $entry['sent_delivery'] = $delivery;

        $this->putPendingSignup($phone, $entry);

        $entry['delivery'] = $delivery;

        return $entry;
    }

    /**
     * Records a wrong guess against a pending sign-up, and says whether the
     * code survived it. False means this guess spent the last attempt.
     *
     * The counterpart of consumeMfaChallengeAttempt(), with one deliberate
     * difference. That method destroys the challenge outright, which is right
     * there: a challenge is one attempt at signing in, and the client answers
     * `too_many_attempts` by sending the resident back to the login form to
     * make another.
     *
     * This entry is not only a credential. It also holds the registration
     * itself — the name, the barangay, the hashed password — for
     * SIGNUP_WINDOW_HOURS, and there is no tbl_residents row behind it. Destroying
     * it would leave the code screen with a Resend button that answers 404, and
     * the resident retyping the whole form. So the CODE is retired and the draft
     * is kept: Resend issues a new one against the same sign-up.
     *
     * Retiring rather than merely counting also means the spent code cannot be
     * guessed after the cap — the hash is dropped from the cache store, not
     * just marked.
     */
    private function spendSignupAttempt(string $phone, array $entry): bool
    {
        $attempts = (int) ($entry['attempts'] ?? 0) + 1;
        $entry['attempts'] = $attempts;
        $survived = $attempts < self::MAX_SIGNUP_ATTEMPTS;

        if (! $survived) {
            // Both, deliberately. Dropping the hash is what actually ends the
            // guessing; zeroing the clock is what signupCodeMatches() checks
            // first, so the entry reads as expired to every path rather than
            // as one with an empty hash.
            unset($entry['code_hash']);
            $entry['expires_at'] = 0;
        }

        // Written back either way, and putPendingSignup() re-stores it with the
        // time the SIGN-UP has left rather than a fresh window — so a run of
        // wrong guesses cannot hold the draft open past its own day.
        $this->putPendingSignup($phone, $entry);

        return $survived;
    }

    private function signupCodeMatches(array $entry, string $code): bool
    {
        $expiresAt = $entry['expires_at'] ?? null;

        if (! is_int($expiresAt) || $expiresAt <= now()->getTimestamp()) {
            return false;
        }

        return Hash::check($code, (string) ($entry['code_hash'] ?? ''));
    }

    /** Seconds left on a pending sign-up's resend cooldown. */
    private function signupResendWait(?array $entry): int
    {
        $sentAt = $entry['sent_at'] ?? null;

        if (! is_int($sentAt)) {
            return 0;
        }

        return max(0, Resident::RESEND_COOLDOWN_SECONDS - (now()->getTimestamp() - $sentAt));
    }

    /**
     * Turns a verified pending sign-up into a usable account: the row is
     * inserted already verified — its first appearance in the table is as a
     * claimed account, so the system log records one `created`.
     *
     * forceFill, not create(): `phone_verified_at` is deliberately absent from
     * the model's Fillable so no request payload can ever reach it. `password`
     * in the entry is already hashed. There is no email; the column is null.
     */
    private function completeSignup(array $entry): Resident
    {
        $new = new Resident;
        $new->forceFill($entry['attributes'] + ['phone_verified_at' => now()])->save();

        return $new;
    }

    /**
     * Tells a client where the code it is waiting for actually went, and how
     * long before another can be asked for.
     *
     * `sent_to` is the last four digits: enough for a resident to recognise
     * their own number, without writing a whole phone number into a response.
     * `delivery` is 'accepted' or 'unknown' — unknown means the request timed
     * out and the text may or may not arrive, which the new app answers with a
     * "Didn't get a text?" hint. `channel` stays for older screens.
     */
    private function signupDeliveryPayload(array $entry): array
    {
        return $this->deliveryFields(
            (string) ($entry['attributes']['phone_number'] ?? ''),
            (string) ($entry['delivery'] ?? $entry['sent_delivery'] ?? 'accepted'),
            $this->signupResendWait($entry),
        );
    }

    // Lets a client rebuild the signed-in user from a stored token. Without this,
    // restoring a session yields a valid token attached to an empty profile,
    // because login is the only place the user object is ever returned.
    public function me(Request $request)
    {
        $user = $request->user();

        if ($user instanceof Resident) {
            return response()->json([
                'role' => 'resident',
                // Residents have no address column; location is the barangay relation.
                'user' => $user->load('barangay'),
            ]);
        }

        return response()->json([
            'role' => 'admin',
            'user' => $user,
        ]);
    }

    // Resident-scoped profile update. Deliberately not part of the admin
    // ResidentController::update(), which accepts status and barangay_id and is
    // reachable only behind is.admin — opening that to residents would hand every
    // resident the admin's own write surface.
    //
    // The phone number is the login now, so it is NOT editable here: moving it
    // takes a code sent to the new number (POST /me/phone). Email is no longer
    // collected at all. An old app that still sends either key gets the same 410
    // the other retired routes answer, so it says why instead of silently
    // dropping the edit.
    public function updateMe(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        if ($request->hasAny(['email_address', 'phone_number'])) {
            return $this->appUpdateRequired();
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            // Purok/street. Unlike barangay_id below, this is exactly the kind
            // of self-correctable detail a profile edit is for — MDRRMO
            // dispatches on the barangay relation, not on this string.
            'street_address' => 'sometimes|nullable|string|max:255',
            // The resident's own notification preference. Writable here — unlike
            // the columns below — because it decides only what this account
            // receives, and there is nobody else who should be deciding it.
            'sms_opt_in' => 'sometimes|required|boolean',
        ]);

        // Assigned key by key, never a splat of $validated. Columns absent from
        // the rules above and that must stay that way:
        //
        //   barangay_id  every service request is dispatched on it, so a resident
        //                who could move themselves could redirect their own
        //                dispatch. Changing barangay is an MDRRMO operation.
        //   status       an Inactive account could otherwise activate itself and
        //                opt an unverified number into billed SMS.
        //   photo        it is a storage path now, not a value anyone types.
        //                POST /me/photo owns it — see ResidentController.
        //   password     a change needs the current password, which is a separate
        //                endpoint, not a field on a profile PATCH.
        //   phone_number the login; see the note above.
        foreach (['first_name', 'middle_name', 'last_name', 'street_address'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $validated[$field];
            }
        }

        // Handled apart from the loop because validate() returns what was sent,
        // not a cast of it — the 'boolean' rule passes the strings "1" and "0"
        // through unchanged. Assigning "0" happens to be safe today (PHP reads
        // it as falsy and the column is a tinyint), so this line is defence in
        // depth: no test distinguishes it from the raw assignment. It earns its
        // place if the rule is ever loosened to accept "true"/"false", where the
        // raw string would land in the column as 1 either way.
        if (array_key_exists('sms_opt_in', $validated)) {
            $user->sms_opt_in = $request->boolean('sms_opt_in');
        }

        $user->save();

        return response()->json([
            'role' => 'resident',
            'user' => $user->load('barangay'),
        ]);
    }

    // Both logins issue a token with an explicit expiry (audit #30). Before this,
    // a token was valid forever: logout revoked the one in hand, but any copy
    // taken beforehand — from localStorage via XSS, a shared machine, a proxy log
    // — stayed usable indefinitely. The two lifetimes differ on purpose; see
    // config/sanctum.php for why. The client-side expiry in the Vue app is
    // browser hygiene and is not what enforces this.

    // Endpoint specifically for the Web Frontend
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
            'password' => 'required',
        ]);

        $admin = User::where('email_address', $request->email_address)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Unauthorized. MDRRMO Admin access only.',
            ], 401);
        }

        // A closed account (audit #29). Named rather than folded into the line
        // above on purpose: the caller has already proved the password, so this
        // discloses nothing they did not know, and "wrong credentials" would
        // send a former employee to reset a password that was never the
        // problem. Deactivation is how a departing employee's access ends —
        // the row cannot be deleted while the audit trail points at it.
        if ($admin->isDeactivated()) {
            return response()->json([
                'message' => 'This account has been deactivated. Contact another MDRRMO admin.',
            ], 403);
        }

        // MFA disabled for admin for now (2026-08-30) — the TOTP QR-enrollment
        // step locked an admin out with no recovery path. adminLoginVerify and
        // the Totp service are left in place, unused, so this is cheap to turn
        // back on once a replacement (email/SMS code, or TOTP + recovery codes)
        // is decided. See serbis-status memory for the options considered.
        return response()->json([
            'token' => $this->issueAdminToken($admin),
            'role' => 'admin',
            'user' => $admin,
        ]);
    }

    /**
     * Second half of admin login: the TOTP code comes back here. The first
     * code an admin ever submits both signs them in and marks them enrolled —
     * there is no separate "confirm enrollment" step, because a correct code
     * is already proof the authenticator app was set up correctly.
     */
    public function adminLoginVerify(Request $request)
    {
        $request->validate([
            'challenge_id' => 'required|string|max:64',
            'code' => 'required|string|size:6',
        ]);

        $challenge = $this->consumeMfaChallengeAttempt('admin', $request->challenge_id);

        if ($challenge === null) {
            return response()->json([
                'message' => 'That login attempt has expired. Please log in again.',
                'code' => 'mfa_challenge_expired',
            ], 422);
        }

        if ($challenge === false) {
            return response()->json([
                'message' => 'Too many wrong codes. Please log in again.',
                'code' => 'too_many_attempts',
            ], 429);
        }

        $admin = User::find($challenge['id']);

        if (! $admin || ! app(Totp::class)->verify($admin->admin_id, (string) $request->code)) {
            return response()->json([
                'message' => 'That code is not right. Check your authenticator app and try again.',
                'code' => 'invalid_code',
            ], 422);
        }

        Cache::forget("mfa:challenge:{$request->challenge_id}");
        app(Totp::class)->markEnrolled($admin->admin_id);

        return response()->json([
            'token' => $this->issueAdminToken($admin),
            'role' => 'admin',
            'user' => $admin,
        ], 200);
    }

    /**
     * A staff member replaces their own password.
     *
     * This is the one thing an account flagged `must_change_password` can do
     * besides read /me and log out (IsAdmin blocks the rest), so it sits outside
     * the is.admin group and does its own checks. It asks for the current
     * password even though the caller holds a token: the token may have come
     * from a temporary password an admin read aloud.
     */
    public function adminChangePassword(Request $request)
    {
        $admin = $request->user();

        if (! $admin instanceof User || ! $admin->isAdmin() || $admin->isDeactivated()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Hash::check against the row, not the `current_password` rule, which
        // resolves the user from the default guard rather than sanctum — see
        // PhoneChangeController::start().
        if (! Hash::check($validated['current_password'], (string) $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $admin->password = $validated['password'];
        $admin->must_change_password = false;
        $admin->save();

        // Every other session ends; this one stays, so the panel does not throw
        // them back to the login form the moment they finish.
        $current = $admin->currentAccessToken();
        $others = $admin->tokens();

        if ($current instanceof PersonalAccessToken) {
            $others->where('id', '!=', $current->getKey());
        }

        $others->delete();

        return response()->json(['user' => $admin]);
    }

    private function issueAdminToken(User $admin): string
    {
        return $admin->createToken(
            'admin-token',
            ['*'],
            now()->addMinutes(config('sanctum.admin_expiration')),
        )->plainTextToken;
    }

    private function issueResidentToken(Resident $resident): string
    {
        return $resident->createToken(
            'resident-token',
            ['*'],
            now()->addMinutes(config('sanctum.resident_expiration')),
        )->plainTextToken;
    }

    /**
     * Creates a short-lived, single-use login challenge. Kept in cache rather
     * than a table — nothing about a challenge needs to survive a restart or
     * be queried, and it's gone in 5 minutes either way.
     */
    private function issueMfaChallenge(string $type, int $id, array $extra = []): string
    {
        $challengeId = Str::random(64);

        Cache::put(
            "mfa:challenge:{$challengeId}",
            array_merge(['type' => $type, 'id' => $id, 'attempts' => 0], $extra),
            now()->addMinutes(5),
        );

        return $challengeId;
    }

    /**
     * Looks up a challenge and checks it is still alive and of the expected
     * type, without spending an attempt — a wrong `challenge_id` or expired
     * challenge is a client-side problem, not a guess against a code.
     *
     * Returns null when the challenge is missing/expired/wrong-type, false
     * when the attempt cap has already been hit (and invalidates it), or the
     * challenge array — with `attempts` already incremented in the cache —
     * when the caller should go on to check the code itself.
     */
    private function consumeMfaChallengeAttempt(string $type, string $challengeId): array|false|null
    {
        $challenge = Cache::get("mfa:challenge:{$challengeId}");

        if (! is_array($challenge) || ($challenge['type'] ?? null) !== $type) {
            return null;
        }

        if (($challenge['attempts'] ?? 0) >= 5) {
            Cache::forget("mfa:challenge:{$challengeId}");

            return false;
        }

        $challenge['attempts'] = ($challenge['attempts'] ?? 0) + 1;
        Cache::put("mfa:challenge:{$challengeId}", $challenge, now()->addMinutes(5));

        return $challenge;
    }

    // Endpoint specifically for the Mobile App
    public function residentLogin(Request $request)
    {
        // The app that shipped before phone login sends an email address and no
        // number. Told to update, in both languages, rather than "wrong
        // credentials".
        if ($request->has('email_address') && ! $request->filled('phone_number')) {
            return $this->appUpdateRequired();
        }

        $request->validate([
            'phone_number' => 'required|string|max:20',
            'password' => 'required',
        ]);

        // A number that is not dialable cannot match anything. It is answered
        // exactly like a wrong password below, so the route says nothing about
        // which numbers have accounts.
        $phone = PhoneNumber::normalize((string) $request->phone_number);
        $resident = $phone === '' ? null : Resident::where('phone_number', $phone)->first();

        // A registration that has not been verified has no row, so a resident
        // who closed the app on the code screen would otherwise be told their
        // own password is wrong. The pending sign-up carries the same hash the
        // row would have, and answers the same way.
        $pending = ($resident || $phone === '') ? null : $this->pendingSignup($phone);
        $hash = $resident->password ?? ($pending['attributes']['password'] ?? null);

        if ($hash === null || ! Hash::check($request->password, (string) $hash)) {
            return response()->json([
                'message' => 'Invalid resident credentials.',
            ], 401);
        }

        // 2026-08-05 quality-check finding 5 asked why residentLogin has no
        // `status` check when adminLogin has one. On 2026-08-08 that was
        // answered deliberately — leave it open — and REVERSED 2026-09-03, for
        // something that argument never addressed: the admin panel has a
        // "Deactivate account" button, and it did nothing a person would call
        // deactivation. The resident stayed signed in, kept filing requests, and
        // merely stopped receiving text blasts.
        //
        // What the 2026-08-08 reasoning got right is kept, and it is exactly
        // why this tests one named value instead of `!== 'Active'`:
        //
        //  - The column is an unconstrained varchar, so 'active' in the wrong
        //    case or 'pending' are both storable. A fail-closed check would
        //    lock those rows out, and there is no self-serve way back in.
        //  - 'Inactive' still signs in. It means "self-registered, awaiting
        //    activation", not "closed" — the account an admin has yet to get
        //    to, which is the worst one to lock out.
        //
        // Placed above the code branches, and not after them: SkySMS bills
        // every send with no sandbox, so gating afterwards would let repeated
        // logins against a closed account cost real money.
        if ($resident && $resident->isDeactivated()) {
            return response()->json([
                'message' => 'This account has been deactivated. Please visit the MDRRMO office.',
                'code' => 'account_deactivated',
            ], 403);
        }

        // Only an abandoned registration reaches this: the sign-up proved
        // nothing yet, so the resident resumes at the code screen instead of
        // being told their password is wrong. Checked after the password on
        // purpose — answering before it would turn this route into an oracle for
        // which numbers are mid-registration.
        if ($pending) {
            // The client answers this by opening the code screen, so a code has
            // to be in flight by the time it gets there. Guarded by the same
            // per-sign-up cooldown the resend route uses, and for the same
            // reason: SkySMS bills every send and has no sandbox. Login is
            // retried far more often than Resend is tapped, so an unguarded send
            // here would be the most expensive line in the app.
            if ($this->signupResendWait($pending) === 0) {
                $pending = $this->issueSignupCode($pending);

                if ($pending['delivery'] === 'failed') {
                    return $this->smsUnavailable();
                }
            }

            return response()->json([
                'message' => 'Please enter the code we just sent to finish creating your account.',
                'code' => 'phone_unverified',
                'phone_number' => $phone,
            ] + $this->signupDeliveryPayload($pending), 403);
        }

        // Password proven and the account is a real one. A token is not issued
        // yet — a code goes to the resident's phone and has to come back to
        // /resident/login/verify first. This is deliberately a *different* code
        // from the signup one: it lives under its own cache key, so a login
        // attempt can never spend or clobber an in-flight signup code and vice
        // versa.
        $login = $this->sendLoginCode($resident, null);

        if ($login['delivery'] === 'failed') {
            return $this->smsUnavailable();
        }

        return response()->json([
            'message' => 'Enter the code we just sent to finish signing in.',
            'code' => 'mfa_required',
            'challenge_id' => $login['challenge_id'],
        ] + $this->loginDeliveryFields($resident, $login), 403);
    }

    /**
     * Second half of resident login: the code texted to the resident's number
     * comes back here.
     */
    public function residentLoginVerify(Request $request)
    {
        $request->validate([
            'challenge_id' => 'required|string|max:64',
            'code' => 'required|string|size:6',
        ]);

        $challenge = $this->consumeMfaChallengeAttempt('resident', $request->challenge_id);

        if ($challenge === null) {
            return response()->json([
                'message' => 'That login attempt has expired. Please log in again.',
                'code' => 'mfa_challenge_expired',
            ], 422);
        }

        if ($challenge === false) {
            return response()->json([
                'message' => 'Too many wrong codes. Please log in again.',
                'code' => 'too_many_attempts',
            ], 429);
        }

        $resident = Resident::find($challenge['id']);

        if (! $resident) {
            return response()->json([
                'message' => 'That code is not right, or it has expired. Ask for a new one.',
                'code' => 'invalid_code',
            ], 422);
        }

        $bypassed = $this->otpBypassMatches((string) $request->code);

        if (! $bypassed && ! Hash::check((string) $request->code, $challenge['code_hash'] ?? '')) {
            return response()->json([
                'message' => 'That code is not right, or it has expired. Ask for a new one.',
                'code' => 'invalid_code',
            ], 422);
        }

        if ($bypassed) {
            $this->logOtpBypassUse($resident);
        }

        Cache::forget("mfa:challenge:{$request->challenge_id}");

        return response()->json([
            'token' => $this->issueResidentToken($resident),
            'role' => 'resident',
            'user' => $resident->load('barangay'),
        ], 200);
    }

    /**
     * Issues a replacement login code against an existing challenge. Takes the
     * challenge id, not the number/password — the resident already proved the
     * password once to get this challenge, and resend must not ask again.
     */
    public function resendLoginCode(Request $request)
    {
        $request->validate([
            'challenge_id' => 'required|string|max:64',
        ]);

        $challenge = Cache::get("mfa:challenge:{$request->challenge_id}");

        if (! is_array($challenge) || ($challenge['type'] ?? null) !== 'resident') {
            return response()->json([
                'message' => 'That login attempt has expired. Please log in again.',
                'code' => 'mfa_challenge_expired',
            ], 422);
        }

        $wait = $this->mfaResendWait($challenge);

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before asking for another code.",
                'code' => 'resend_too_soon',
                'retry_after' => $wait,
            ], 429);
        }

        $resident = Resident::find($challenge['id']);

        if (! $resident) {
            return response()->json([
                'message' => 'That login attempt has expired. Please log in again.',
                'code' => 'mfa_challenge_expired',
            ], 422);
        }

        $login = $this->sendLoginCode($resident, $request->challenge_id);

        if ($login['delivery'] === 'failed') {
            return $this->smsUnavailable();
        }

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
        ] + $this->loginDeliveryFields($resident, $login), 200);
    }

    /**
     * Sends (or resends, against an existing challenge id) a login code for a
     * resident and stores its hash in the challenge — never in the pending
     * sign-up entry, which belongs to the signup gate alone.
     *
     * @return array{delivery: string, challenge_id: string}
     */
    private function sendLoginCode(Resident $resident, ?string $challengeId): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // sent_at is a Unix timestamp and NOT a Carbon. config/cache.php sets
        // 'serializable_classes' => false, so FileStore::get() unserializes with
        // allowed_classes => false and hands back __PHP_Incomplete_Class for any
        // object it stored. A Carbon written here came back as one and killed
        // the login response with a TypeError inside Carbon's diffInSeconds().
        // Same trap AnalyticsController.php works around with a json round-trip;
        // an int needs no round-trip and survives every cache store.
        $extra = ['code_hash' => Hash::make($code), 'sent_at' => now()->getTimestamp()];

        if ($challengeId === null) {
            $challengeId = $this->issueMfaChallenge('resident', $resident->resident_id, $extra);
        } else {
            $challenge = Cache::get("mfa:challenge:{$challengeId}", []);
            Cache::put(
                "mfa:challenge:{$challengeId}",
                array_merge($challenge, $extra, ['attempts' => 0]),
                now()->addMinutes(5),
            );
        }

        $delivery = $this->sendOtpText(
            (string) $resident->phone_number,
            "Your SERBIS login code is {$code}. It expires in 5 minutes.",
        );

        if ($delivery === 'failed') {
            // No cooldown for a text that never went out, so the resident can
            // try again straight away; the challenge itself lapses in five
            // minutes.
            Cache::put(
                "mfa:challenge:{$challengeId}",
                array_merge(Cache::get("mfa:challenge:{$challengeId}", []), ['sent_at' => null]),
                now()->addMinutes(5),
            );
        }

        return ['delivery' => $delivery, 'challenge_id' => $challengeId];
    }

    /**
     * Seconds left on an MFA challenge's resend cooldown.
     *
     * Anything that is not an int is treated as "no cooldown": a challenge
     * written before sent_at became a timestamp, a cache miss, and the
     * __PHP_Incomplete_Class a restored object would be all land here. None of
     * them is worth a 500 — the worst case is one extra code, which the route's
     * own rate limiter still caps.
     */
    private function mfaResendWait(mixed $challenge): int
    {
        $sentAt = is_array($challenge) ? ($challenge['sent_at'] ?? null) : null;

        if (! is_int($sentAt)) {
            return 0;
        }

        return max(0, Resident::RESEND_COOLDOWN_SECONDS - (now()->getTimestamp() - $sentAt));
    }

    /**
     * @param  array{delivery: string, challenge_id: string}  $login
     */
    private function loginDeliveryFields(Resident $resident, array $login): array
    {
        return $this->deliveryFields(
            (string) $resident->phone_number,
            $login['delivery'],
            $this->mfaResendWait(Cache::get("mfa:challenge:{$login['challenge_id']}")),
        );
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }
}
