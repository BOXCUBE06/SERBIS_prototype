<?php

namespace App\Http\Controllers;

use App\Mail\ResidentLoginCode;
use App\Mail\ResidentVerificationCode;
use App\Models\User; // Represents Admins/Staff
use App\Models\Resident;
use App\Services\PhilSms;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    // Resident self-registration for the mobile app. Admins live in tbl_user and
    // are deliberately not creatable here — there is no public route that writes
    // to that table.
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name'    => 'required|string|max:255',
            'middle_name'   => 'nullable|string|max:255',
            'last_name'     => 'required|string|max:255',
            'barangay_id'   => 'required|integer|exists:tbl_barangay,barangay_id',
            'phone_number'  => 'required|string|max:20',
            'email_address' => 'required|email|unique:tbl_residents,email_address',
            'password'      => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Every column is assigned explicitly rather than splatting $validated, so
        // no extra key in the payload can reach a column. Two matter in particular:
        // `status` (see below) and `role`, which the mobile client currently sends
        // and which must never be client-settable.
        $resident = Resident::create([
            'barangay_id'   => $validated['barangay_id'],
            'first_name'    => $validated['first_name'],
            'middle_name'   => $validated['middle_name'] ?? null,
            'last_name'     => $validated['last_name'],
            'phone_number'  => $validated['phone_number'],
            'email_address' => $validated['email_address'],
            'password'      => Hash::make($validated['password']),
            // Starts Inactive on purpose. SmsController only blasts residents with
            // status 'Active', and PhilSMS bills per real send with no sandbox, so a
            // self-registered account must not opt an unverified phone number into
            // paid SMS until an admin activates it from the Users view.
            'status'        => 'Inactive',
        ]);

        $channel = $this->sendVerificationCode($resident);

        // Still no token and still no 'message' key — the mobile client reads any
        // `message` on this response as an error to show the resident. A token is
        // withheld for a second reason now: the account is not usable until the
        // emailed code comes back, so there is nothing for a token to authorise.
        // `verification_required` is what sends the client to the code screen.
        return response()->json([
            'resident' => $resident,
            'verification_required' => true,
            'email_address' => $resident->email_address,
        ] + $this->deliveryPayload($resident, $channel), 201);
    }

    /**
     * Second half of registration: the code from the email comes back here and
     * the account becomes usable. A token is issued on success so the resident
     * lands signed in rather than being handed straight to a login form.
     */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
            'code' => 'required|string',
        ]);

        $resident = Resident::where('email_address', $request->email_address)->first();

        if (!$resident) {
            return response()->json([
                'message' => 'We could not find an account for that email address.',
                'code' => 'not_found',
            ], 404);
        }

        if ($resident->hasVerifiedEmail()) {
            // Deliberately not a success. Returning a token here would mean any
            // string verifies an already-verified account.
            return response()->json([
                'message' => 'This email address is already verified. Please log in.',
                'code' => 'already_verified',
            ], 422);
        }

        if (!$resident->verificationCodeMatches($request->code)) {
            // One message for a wrong code and for an expired one. Separating
            // them tells someone guessing which half they got right.
            return response()->json([
                'message' => 'That code is not right, or it has expired. Ask for a new one.',
                'code' => 'invalid_code',
            ], 422);
        }

        $resident->markEmailAsVerified();

        return response()->json([
            'token' => $resident->createToken(
                'resident-token',
                ['*'],
                now()->addMinutes(config('sanctum.resident_expiration')),
            )->plainTextToken,
            'role' => 'resident',
            'user' => $resident->load('barangay'),
        ]);
    }

    /**
     * Issues a replacement code. Two limits apply: the route's rate limiter, and
     * a per-account cooldown so one account cannot be used to send mail on a
     * timer from many addresses.
     */
    public function resendVerificationCode(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
        ]);

        $resident = Resident::where('email_address', $request->email_address)->first();

        // A 404 here reveals only what registration already reveals: the address
        // is unique-validated at signup, so existence is discoverable there too.
        // Staying silent instead would leave a resident who mistyped their own
        // address waiting for mail that is never coming.
        if (!$resident) {
            return response()->json([
                'message' => 'We could not find an account for that email address.',
                'code' => 'not_found',
            ], 404);
        }

        if ($resident->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email address is already verified. Please log in.',
                'code' => 'already_verified',
            ], 422);
        }

        if (($wait = $resident->secondsUntilResendAllowed()) > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before asking for another code.",
                'code' => 'resend_too_soon',
                'retry_after' => $wait,
            ], 429);
        }

        $channel = $this->sendVerificationCode($resident);

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
        ] + $this->deliveryPayload($resident, $channel));
    }

    /**
     * The plain code exists only between these two lines. Everything stored is
     * hashed, so this is the single point where it can be sent.
     */
    private function sendVerificationCode(Resident $resident): string
    {
        $code = $resident->issueVerificationCode();

        // SMS is the primary channel: a resident registering on a phone reads the
        // code without leaving the handset, and the deployment has an SMS vendor
        // configured before it has a mail one. Email stays as the fallback for a
        // number the vendor cannot dial — the column is still email_verified_at
        // either way, because what is being proven is ownership of the account,
        // not of a particular channel.
        if ($this->smsIsUsableFor($resident)) {
            try {
                $response = app(PhilSms::class)->send(
                    [$resident->phone_number],
                    "Your SERBIS verification code is {$code}. It expires in ".Resident::CODE_TTL_MINUTES.' minutes.',
                );

                if (PhilSms::accepted($response)) {
                    return 'sms';
                }

                Log::warning('OTP SMS failed, falling back to email', [
                    'resident_id' => $resident->resident_id,
                    'status'      => $response->status(),
                ]);
            } catch (\Throwable $e) {
                // See sendLoginCode() for why this is treated as delivered rather
                // than falling to the (production-dead, MAIL_MAILER=log) email
                // path: the request timing out does not mean PhilSMS never sent it.
                Log::warning('OTP SMS threw, treating as delivered', [
                    'resident_id' => $resident->resident_id,
                    'error'       => $e->getMessage(),
                ]);

                return 'sms';
            }
        }

        Mail::to($resident->email_address)->send(
            new ResidentVerificationCode($resident, $code)
        );

        return 'email';
    }

    /**
     * Tells a client where the code it is waiting for actually went, and how
     * long before another can be asked for.
     *
     * The channel is not knowable in advance — sendVerificationCode falls back
     * to mail when the vendor cannot dial the number — so the screen that says
     * "check your messages" has to be told after the fact rather than assuming.
     *
     * `sent_to` is the last four digits for SMS and the full address for mail:
     * enough for a resident to recognise which of their own contacts it is,
     * without writing a whole phone number into a response body.
     */
    /**
     * Whether the vendor could carry a code to this resident at all. Only a
     * send can prove it — the vendor can still reject a dialable number — so
     * this is the question asked before trying, not a promise it worked.
     */
    private function smsIsUsableFor(Resident $resident): bool
    {
        return PhilSms::configured()
            && PhilSms::normalize((string) $resident->phone_number) !== '';
    }

    private function deliveryPayload(Resident $resident, string $channel): array
    {
        $digits = preg_replace('/\D/', '', (string) $resident->phone_number);

        return [
            'channel' => $channel,
            'sent_to' => $channel === 'sms'
                ? substr($digits, -4)
                : $resident->email_address,
            'retry_after' => $resident->secondsUntilResendAllowed(),
        ];
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
    public function updateMe(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        $validated = $request->validate([
            'first_name'    => 'sometimes|required|string|max:255',
            'middle_name'   => 'nullable|string|max:255',
            'last_name'     => 'sometimes|required|string|max:255',
            'phone_number'  => 'sometimes|required|string|max:20',
            'email_address' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('tbl_residents', 'email_address')->ignore($user->getKey(), 'resident_id'),
            ],
            // The resident's own notification preference. Writable here — unlike
            // the four columns below — because it decides only what this account
            // receives, and there is nobody else who should be deciding it.
            'sms_opt_in'    => 'sometimes|required|boolean',
        ]);

        // Assigned key by key, never a splat of $validated. Four columns are
        // absent from the rules above and must stay that way:
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
        foreach (['first_name', 'middle_name', 'last_name', 'phone_number', 'email_address'] as $field) {
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

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Unauthorized. MDRRMO Admin access only.'
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
                'message' => 'This account has been deactivated. Contact another MDRRMO admin.'
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
            'challenge_id' => 'required|string',
            'code' => 'required|string',
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

        if (!$admin || !app(Totp::class)->verify($admin->admin_id, (string) $request->code)) {
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

        if (!is_array($challenge) || ($challenge['type'] ?? null) !== $type) {
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
        $request->validate([
            'email_address' => 'required|email',
            'password' => 'required',
        ]);

        $resident = Resident::where('email_address', $request->email_address)->first();

        if (!$resident || !Hash::check($request->password, $resident->password)) {
            return response()->json([
                'message' => 'Invalid resident credentials.'
            ], 401);
        }

        // Only an abandoned registration reaches this. Verification happens as
        // the last step of signing up, so a resident who finished it never sees
        // this refusal — and one who closed the app halfway can resume from the
        // code screen instead of being told their password is wrong.
        //
        // Checked after the password on purpose: answering before it would turn
        // this route into an oracle for which addresses have accounts.
        if (!$resident->hasVerifiedEmail()) {
            // The client answers this by opening the code screen, so a code has
            // to be in flight by the time it gets there. Without this the
            // resident waited on a message nobody had sent and only got one by
            // tapping Resend.
            //
            // Guarded by the same per-account cooldown the resend route uses,
            // and for the same reason: PhilSMS bills every send and has no
            // sandbox. Login is retried far more often than Resend is tapped,
            // so an unguarded send here would be the most expensive line in
            // the app. Inside the cooldown the outstanding code is still valid
            // and still has most of its 15 minutes left, so there is nothing
            // to reissue.
            $channel = $resident->secondsUntilResendAllowed() === 0
                ? $this->sendVerificationCode($resident)
                : ($this->smsIsUsableFor($resident) ? 'sms' : 'email');

            return response()->json([
                'message' => 'Please enter the code we just sent to finish creating your account.',
                'code' => 'email_unverified',
                'email_address' => $resident->email_address,
            ] + $this->deliveryPayload($resident, $channel), 403);
        }

        // There is deliberately NO `status` check here, unlike adminLogin above.
        // This is quality-check finding 5 (2026-08-05), and it was decided on
        // 2026-08-08 to leave it open rather than fixed. Recorded here because
        // the asymmetry with adminLogin reads like an oversight and has now
        // been re-raised more than once.
        //
        // An `Inactive` resident can log in and file service requests. The only
        // thing activation gates is who receives an SMS blast. That is the
        // intended behaviour for now:
        //
        //  - There is no self-serve activation or reactivation flow anywhere.
        //    Blocking the login makes the MDRRMO office the only way back in,
        //    for an app whose whole purpose is the hour when nobody can reach
        //    the office.
        //  - `tbl_residents.status` is a plain varchar, NOT NULL but with no
        //    default and nothing constraining its values. Every insert has to
        //    name a status, and nothing stops one naming 'pending' or 'active'
        //    in the wrong case. A fail-closed `=== 'Active'` test would lock
        //    out every such row. (This differs from tbl_user.status, which
        //    defaults to 'Active' — do not carry the reasoning across.)
        //  - Filing a request is not the risk. A request is triaged by a human
        //    before a unit moves, so an unactivated account costs the office a
        //    dispatch decision it was already making, not an automatic response.
        //
        // If this is ever closed, mirror isDeactivated() rather than testing
        // for 'Active', and give the mobile login screen a 403 branch first —
        // without one the resident sees a generic failure and retries forever.
        // The onboarding copy implying a gate should change at the same time.

        // Password proven and the account is a real one. A token is not issued
        // yet — a code goes to the resident's phone (or mail, same fallback
        // sendVerificationCode uses) and has to come back to /resident/login/verify
        // first. This is deliberately a *different* code from the signup one:
        // it never touches verification_code on the model, so a login attempt
        // can never spend or clobber an in-flight signup code and vice versa.
        ['channel' => $channel, 'challenge_id' => $challengeId] = $this->sendLoginCode($resident, null);

        return response()->json([
            'message' => 'Enter the code we just sent to finish signing in.',
            'code' => 'mfa_required',
            'challenge_id' => $challengeId,
        ] + $this->deliveryPayloadFor($resident, $channel, $challengeId), 403);
    }

    /**
     * Second half of resident login: the SMS (or email-fallback) code comes
     * back here.
     */
    public function residentLoginVerify(Request $request)
    {
        $request->validate([
            'challenge_id' => 'required|string',
            'code' => 'required|string',
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

        if (!$resident || !Hash::check((string) $request->code, $challenge['code_hash'] ?? '')) {
            return response()->json([
                'message' => 'That code is not right, or it has expired. Ask for a new one.',
                'code' => 'invalid_code',
            ], 422);
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
     * challenge id, not the email/password — the resident already proved the
     * password once to get this challenge, and resend must not ask again.
     */
    public function resendLoginCode(Request $request)
    {
        $request->validate([
            'challenge_id' => 'required|string',
        ]);

        $challenge = Cache::get("mfa:challenge:{$request->challenge_id}");

        if (!is_array($challenge) || ($challenge['type'] ?? null) !== 'resident') {
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

        if (!$resident) {
            return response()->json([
                'message' => 'That login attempt has expired. Please log in again.',
                'code' => 'mfa_challenge_expired',
            ], 422);
        }

        ['channel' => $channel, 'challenge_id' => $challengeId] = $this->sendLoginCode($resident, $request->challenge_id);

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
        ] + $this->deliveryPayloadFor($resident, $channel, $challengeId), 200);
    }

    /**
     * Sends (or resends, against an existing challenge id) a login code for a
     * resident and stores its hash in the challenge — never in
     * Resident::verification_code, which belongs to the signup gate alone.
     *
     * @return array{channel: string, challenge_id: string}
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

        if ($this->smsIsUsableFor($resident)) {
            try {
                $response = app(PhilSms::class)->send(
                    [$resident->phone_number],
                    "Your SERBIS login code is {$code}. It expires in 5 minutes.",
                );

                if (PhilSms::accepted($response)) {
                    return ['channel' => 'sms', 'challenge_id' => $challengeId];
                }

                Log::warning('Login OTP SMS failed, falling back to email', [
                    'resident_id' => $resident->resident_id,
                ]);
            } catch (\Throwable $e) {
                // Not a rejection — the request itself never completed (timeout,
                // dropped connection), which on this vendor almost always means
                // PhilSMS received and sent the text before the response leg
                // failed. MAIL_MAILER=log in production (render.yaml) makes the
                // email fallback below a dead end — it writes to a log file, not
                // an inbox — so treating this as a real rejection would tell a
                // resident who already has the code on their phone to go check
                // an email that will never arrive. Report it delivered instead;
                // the code sent is the same one this response's challenge checks
                // against either way.
                Log::warning('Login OTP SMS threw, treating as delivered', [
                    'resident_id' => $resident->resident_id,
                    'error'       => $e->getMessage(),
                ]);

                return ['channel' => 'sms', 'challenge_id' => $challengeId];
            }
        }

        Mail::to($resident->email_address)->send(
            new ResidentLoginCode($resident, $code)
        );

        return ['channel' => 'email', 'challenge_id' => $challengeId];
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

        if (!is_int($sentAt)) {
            return 0;
        }

        return max(0, Resident::RESEND_COOLDOWN_SECONDS - (now()->getTimestamp() - $sentAt));
    }

    private function deliveryPayloadFor(Resident $resident, string $channel, string $challengeId): array
    {
        $digits = preg_replace('/\D/', '', (string) $resident->phone_number);
        $wait = $this->mfaResendWait(Cache::get("mfa:challenge:{$challengeId}"));

        return [
            'channel' => $channel,
            'sent_to' => $channel === 'sms'
                ? substr($digits, -4)
                : $resident->email_address,
            'retry_after' => $wait,
        ];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }
}