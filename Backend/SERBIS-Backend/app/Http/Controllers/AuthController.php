<?php

namespace App\Http\Controllers;

use App\Mail\ResidentLoginCode;
use App\Mail\ResidentVerificationCode;
use App\Models\Resident;
use App\Models\User; // Represents Admins/Staff
use App\Services\PhilSms;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

    // Resident self-registration for the mobile app. Admins live in tbl_user and
    // are deliberately not creatable here — there is no public route that writes
    // to that table.
    //
    // Nothing is written to tbl_residents here. A sign-up that has not proved it
    // controls the address it claimed lives entirely in the cache until the code
    // comes back, and only verifyEmail() inserts the row. The old flow inserted
    // first and verified afterwards, which meant an abandoned or hostile sign-up
    // permanently held an email address and a phone number — the `unique` rule
    // below would then refuse the real owner, with nothing to release it but an
    // admin deleting the row by hand. A pending sign-up instead lapses on its own.
    //
    // This is the same shape the MFA login challenge already uses: short-lived
    // state that nothing needs to query or keep, held in cache and consumed once.
    //
    // The SMS spend is unchanged: this route sends exactly one code per call and
    // the 'register' limiter (AppServiceProvider) caps it at 5/minute and
    // 15/hour per IP. Re-registering the same address is now allowed and simply
    // issues a fresh code, retiring the outstanding one.
    public function register(Request $request)
    {
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
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhilSms::PHONE_REGEX],
            // Still checked against the table, but the table now only holds
            // accounts that finished verifying, so this refuses a real account
            // and never an abandoned attempt.
            'email_address' => 'required|email|unique:tbl_residents,email_address',
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            // An individual (head of the family, the default) or an organization.
            // Barangay accounts are made by MDRRMO staff and are refused here, so
            // nobody can sign themselves up as one.
            'account_type' => ['sometimes', Rule::in([Resident::TYPE_HEAD_OF_FAMILY, Resident::TYPE_ORGANIZATION])],
            'organization_name' => 'required_if:account_type,'.Resident::TYPE_ORGANIZATION.'|nullable|string|max:150',
        ]);

        $accountType = $validated['account_type'] ?? Resident::TYPE_HEAD_OF_FAMILY;

        // Every column is assigned explicitly rather than splatting $validated, so
        // no extra key in the payload can reach a column. Two matter in particular:
        // `status` (see below) and `role`, which the mobile client currently sends
        // and which must never be client-settable.
        $entry = $this->issueSignupCode([
            'attributes' => [
                'barangay_id' => $validated['barangay_id'],
                'street_address' => $validated['street_address'] ?? null,
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'phone_number' => $validated['phone_number'],
                'email_address' => $validated['email_address'],
                // Hashed here rather than at insert time: the plain password must
                // not sit in the cache store for the life of the pending sign-up.
                'password' => Hash::make($validated['password']),
                // Starts Inactive on purpose. SmsController only blasts residents
                // with status 'Active', and PhilSMS bills per real send with no
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
            // Set only when an existing unverified row is being finished off —
            // see adoptUnverifiedResident(). A fresh sign-up has no row yet.
            'resident_id' => null,
        ]);

        // Still no token and still no 'message' key — the mobile client reads any
        // `message` on this response as an error to show the resident. There is
        // no `resident` key any more either: there is no row to return, and the
        // client never read one. `verification_required` is what sends it to the
        // code screen, and `email_address` is what that screen verifies against.
        return response()->json([
            'verification_required' => true,
            'email_address' => $entry['attributes']['email_address'],
        ] + $this->signupDeliveryPayload($entry), 201);
    }

    /**
     * Second half of registration: the code from the message comes back here and
     * the account is created, already verified. A token is issued on success so
     * the resident lands signed in rather than being handed to a login form.
     */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $email = $request->email_address;

        // Pulled, not read: a code is single-use, and two taps of Submit that
        // arrive together must not both go on to insert a row. The loser of that
        // race finds no entry and falls through to a branch below rather than
        // hitting the unique index on email_address. A wrong guess must not spend
        // the entry, so that path puts it straight back.
        $entry = Cache::pull($this->pendingSignupKey($email));
        $resident = Resident::where('email_address', $email)->first();

        if ($resident && $resident->hasVerifiedEmail()) {
            // Deliberately not a success. Returning a token here would mean any
            // string verifies an already-verified account.
            return response()->json([
                'message' => 'This email address is already verified. Please log in.',
                'code' => 'already_verified',
            ], 422);
        }

        if (! is_array($entry)) {
            // An unverified row with no code in flight is a sign-up written
            // before this flow moved into the cache, whose code has since
            // lapsed. It is told what an expired code is told, because that is
            // exactly what happened. No row and no entry means there is nothing
            // here to finish at all.
            return $resident
                ? response()->json([
                    'message' => 'That code is not right, or it has expired. Ask for a new one.',
                    'code' => 'invalid_code',
                ], 422)
                : response()->json([
                    'message' => 'We could not find a sign-up for that email address. It may have expired — please register again.',
                    'code' => 'not_found',
                ], 404);
        }

        $bypassed = $this->otpBypassMatches((string) $request->code);

        if (! $bypassed && ! $this->signupCodeMatches($entry, (string) $request->code)) {
            if (! $this->spendSignupAttempt($email, $entry)) {
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

        $resident = $this->completeSignup($entry, $resident);

        if (! $resident) {
            return response()->json([
                'message' => 'We could not find a sign-up for that email address. It may have expired — please register again.',
                'code' => 'not_found',
            ], 404);
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
     * a per-sign-up cooldown so one address cannot be used to send messages on a
     * timer.
     */
    public function resendVerificationCode(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
        ]);

        $email = $request->email_address;
        $resident = Resident::where('email_address', $email)->first();

        if ($resident && $resident->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email address is already verified. Please log in.',
                'code' => 'already_verified',
            ], 422);
        }

        $entry = $this->pendingSignup($email);

        // A 404 here says only that no sign-up is in flight for the address,
        // which registering would reveal anyway. Staying silent instead would
        // leave a resident who mistyped their own address waiting for a message
        // that is never coming.
        if ($entry === null) {
            if (! $resident) {
                return response()->json([
                    'message' => 'We could not find a sign-up for that email address. It may have expired — please register again.',
                    'code' => 'not_found',
                ], 404);
            }

            $entry = $this->adoptUnverifiedResident($resident);
        }

        if (($wait = $this->signupResendWait($entry)) > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before asking for another code.",
                'code' => 'resend_too_soon',
                'retry_after' => $wait,
            ], 429);
        }

        $entry = $this->issueSignupCode($entry);

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
        ] + $this->signupDeliveryPayload($entry));
    }

    /**
     * Where a pending sign-up lives. Keyed by a hash of the address rather than
     * the address itself: the cache store is a file tree in production and a
     * table in development, and neither is a place to leave a list of every
     * email that has ever started registering.
     */
    private function pendingSignupKey(string $email): string
    {
        return 'signup:pending:'.hash('sha256', mb_strtolower(trim($email)));
    }

    private function pendingSignup(string $email): ?array
    {
        $entry = Cache::get($this->pendingSignupKey($email));

        return is_array($entry) ? $entry : null;
    }

    /**
     * Writes a pending sign-up back with the time it has left, never a fresh
     * window. Re-storing an entry — after a wrong guess, say — must move
     * nothing, or handling a sign-up would keep it alive indefinitely.
     */
    private function putPendingSignup(string $email, array $entry): void
    {
        $seconds = ($entry['signup_expires_at'] ?? 0) - now()->getTimestamp();

        if ($seconds <= 0) {
            Cache::forget($this->pendingSignupKey($email));

            return;
        }

        Cache::put($this->pendingSignupKey($email), $entry, $seconds);
    }

    /**
     * Builds a pending sign-up around an existing unverified row.
     *
     * Two things still produce one. Rows written before this flow moved into
     * the cache, whose owners have no other way to finish; and every resident
     * the admin panel creates — ResidentController::store() writes no
     * `email_verified_at`, so an account made for someone at the MDRRMO office
     * proves the address on its first login, exactly as a self-registration
     * proves it before the row exists.
     *
     * The entry carries the row's id, so verifying marks that row rather than
     * inserting a second one against the unique index on email_address.
     */
    private function adoptUnverifiedResident(Resident $resident): array
    {
        return [
            'attributes' => [
                'first_name' => $resident->first_name,
                'phone_number' => $resident->phone_number,
                'email_address' => $resident->email_address,
            ],
            'resident_id' => $resident->resident_id,
        ];
    }

    /**
     * The plain code exists only inside this method. Everything stored is
     * hashed, so this is the single point where it can be sent.
     *
     * Issuing replaces any outstanding code rather than adding a second valid
     * one — otherwise every resend widens the window instead of moving it.
     *
     * Returns the stored entry, which now knows where the code went. The channel
     * is not knowable in advance, because delivery falls back to mail when the
     * vendor cannot dial the number, so the screen that says "check your
     * messages" has to be told after the fact rather than assuming.
     *
     * Every value written here is a scalar. config/cache.php sets
     * 'serializable_classes' => false, so a stored object comes back as
     * __PHP_Incomplete_Class — the trap sendLoginCode() documents at length.
     * Times are Unix timestamps for that reason, never Carbon.
     */
    private function issueSignupCode(array $entry): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $phone = (string) ($entry['attributes']['phone_number'] ?? '');
        $email = (string) ($entry['attributes']['email_address'] ?? '');

        $entry['code_hash'] = Hash::make($code);
        $entry['sent_at'] = now()->getTimestamp();
        $entry['expires_at'] = now()->addMinutes(Resident::CODE_TTL_MINUTES)->getTimestamp();
        // A new code is a new secret, so it gets a fresh budget of guesses —
        // the same reset sendLoginCode() performs on a resent login code. What
        // stops that being a way around MAX_SIGNUP_ATTEMPTS is the resend
        // cooldown (Resident::RESEND_COOLDOWN_SECONDS) plus the route limiter:
        // buying another five guesses costs a minute's wait and a real message
        // to the address being attacked.
        $entry['attempts'] = 0;
        // Set once and carried through every reissue: resending moves the code's
        // clock, not the sign-up's, so a resident cannot hold an unfinished
        // sign-up open forever by tapping Resend.
        $entry['signup_expires_at'] ??= now()->addHours(self::SIGNUP_WINDOW_HOURS)->getTimestamp();
        $entry['channel'] = $this->textSignupCode($phone, $code) ? 'sms' : 'email';

        // `sent_to` is the last four digits for SMS and the full address for
        // mail: enough for a resident to recognise which of their own contacts
        // it is, without writing a whole phone number into a response body.
        // Recorded now rather than derived later, so a screen shown inside the
        // cooldown names the contact the outstanding code actually went to.
        $entry['sent_to'] = $entry['channel'] === 'sms'
            ? substr(preg_replace('/\D/', '', $phone), -4)
            : $email;

        $this->putPendingSignup($email, $entry);

        if ($entry['channel'] === 'email') {
            Mail::to($email)->send(new ResidentVerificationCode(
                (string) ($entry['attributes']['first_name'] ?? ''),
                $code,
            ));
        }

        return $entry;
    }

    /**
     * The SMS leg of delivery. True when the code is on its way by text, false
     * when the caller should fall back to mail.
     *
     * SMS is the primary channel: a resident registering on a phone reads the
     * code without leaving the handset, and the deployment has an SMS vendor
     * configured before it has a mail one. Email is the fallback for a number
     * the vendor cannot dial — the column is still email_verified_at either way,
     * because what is being proven is ownership of the account, not of a
     * particular channel.
     */
    private function textSignupCode(string $phone, string $code): bool
    {
        if (! $this->smsIsUsable($phone)) {
            return false;
        }

        try {
            $response = app(PhilSms::class)->send(
                [$phone],
                "Your SERBIS verification code is {$code}. It expires in ".Resident::CODE_TTL_MINUTES.' minutes.',
            );

            if (PhilSms::accepted($response)) {
                return true;
            }

            // No resident_id to log: there is no row yet, and the address is not
            // going in a log line to make up for it.
            Log::warning('OTP SMS failed, falling back to email', [
                'status' => $response->status(),
            ]);

            return false;
        } catch (\Throwable $e) {
            // See sendLoginCode() for why this is treated as delivered rather
            // than falling to the (production-dead, MAIL_MAILER=log) email path:
            // the request timing out does not mean PhilSMS never sent it.
            Log::warning('OTP SMS threw, treating as delivered', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
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
     * SIGNUP_WINDOW_HOURS, and for a fresh self-registration there is no
     * tbl_residents row behind it yet. Destroying it would leave the code
     * screen with a Resend button that answers 404, and the resident retyping
     * the whole form. So the CODE is retired and the draft is kept: Resend
     * issues a new one against the same sign-up, which is what
     * verify_email_screen.dart already does with any error it is handed.
     *
     * Retiring rather than merely counting also means the spent code cannot be
     * guessed after the cap — the hash is dropped from the cache store, not
     * just marked.
     */
    private function spendSignupAttempt(string $email, array $entry): bool
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
        $this->putPendingSignup($email, $entry);

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
     * Turns a verified pending sign-up into a usable account.
     *
     * Two shapes reach here. A sign-up carries the whole column set and gets a
     * row inserted, already verified — the row's first appearance in the table
     * is as a claimed account, so the system log records one `created` rather
     * than a create-then-verify pair. An adopted row (see
     * adoptUnverifiedResident) already exists and is only marked.
     */
    private function completeSignup(array $entry, ?Resident $resident): ?Resident
    {
        if ($entry['resident_id'] ?? null) {
            $resident ??= Resident::find($entry['resident_id']);

            if (! $resident) {
                return null;
            }

            $resident->markEmailAsVerified();

            return $resident;
        }

        // forceFill, not create(): `email_verified_at` is deliberately absent
        // from the model's Fillable so no request payload can ever reach it.
        // `password` in the entry is already hashed.
        $new = new Resident;
        $new->forceFill($entry['attributes'] + ['email_verified_at' => now()])->save();

        return $new;
    }

    /**
     * Whether the vendor could carry a code to this number at all. Only a send
     * can prove it — the vendor can still reject a dialable number — so this is
     * the question asked before trying, not a promise it worked.
     */
    private function smsIsUsable(?string $phone): bool
    {
        return PhilSms::configured()
            && PhilSms::normalize((string) $phone) !== '';
    }

    /**
     * Tells a client where the code it is waiting for actually went, and how
     * long before another can be asked for.
     */
    private function signupDeliveryPayload(array $entry): array
    {
        return [
            'channel' => $entry['channel'] ?? 'email',
            'sent_to' => $entry['sent_to'] ?? ($entry['attributes']['email_address'] ?? ''),
            'retry_after' => $this->signupResendWait($entry),
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
            'first_name' => 'sometimes|required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            // Purok/street. Unlike barangay_id below, this is exactly the kind
            // of self-correctable detail a profile edit is for — MDRRMO
            // dispatches on the barangay relation, not on this string.
            'street_address' => 'sometimes|nullable|string|max:255',
            'phone_number' => ['sometimes', 'required', 'string', 'max:20', 'regex:'.PhilSms::PHONE_REGEX],
            'email_address' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('tbl_residents', 'email_address')->ignore($user->getKey(), 'resident_id'),
            ],
            // The resident's own notification preference. Writable here — unlike
            // the four columns below — because it decides only what this account
            // receives, and there is nobody else who should be deciding it.
            'sms_opt_in' => 'sometimes|required|boolean',
            // Not a column. Proof of knowledge, required below only when this
            // call actually moves one of the two contacts a login code is sent
            // to. Left out of the assignment loop for the same reason every
            // other non-column key is.
            'current_password' => 'nullable|string',
        ]);

        // email_address and phone_number are where a login code is delivered:
        // sendLoginCode() texts the number and falls back to the address, so
        // whoever controls them controls every future sign-in. Moving one is a
        // credential change wearing a profile edit's clothes, and a bearer
        // token alone must not be enough to do it — otherwise a token lifted
        // from a shared phone converts into a permanent takeover, with no
        // self-serve reset for the real owner to take the account back.
        //
        // Compared against what is stored, not merely "was the key sent": the
        // mobile client PATCHes only the fields its form actually changed, but
        // a client that sends the whole profile every time must not be asked
        // for a password to save an unchanged one. It is also what keeps
        // resubmitting your own address working, which the unique rule above
        // already goes out of its way to allow.
        $contactChanges = [];

        foreach (['email_address', 'phone_number'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== $user->{$field}) {
                $contactChanges[] = $field;
            }
        }

        if ($contactChanges !== []) {
            $this->assertCurrentPassword($request, $user);
        }

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
        foreach (['first_name', 'middle_name', 'last_name', 'street_address', 'phone_number', 'email_address'] as $field) {
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

        // A new address has not been proved yet, so it does not inherit the old
        // one's verified state. Assigned directly rather than in the loop above
        // because `email_verified_at` is deliberately absent from the model's
        // Fillable — completeSignup() forceFills it for the same reason.
        //
        // Nothing else has to be built to finish the job: residentLogin()
        // already answers an unverified row by issuing a code and returning 403
        // `email_unverified`, which the mobile client reads as "open the code
        // screen", and adoptUnverifiedResident() already covers a row that
        // exists but is unclaimed. So the next sign-in proves the new address
        // through the flow that is there. The current session is deliberately
        // left alive — it just proved the password, and ending it here would
        // log the resident out of the edit they were making.
        if (in_array('email_address', $contactChanges, true)) {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json([
            'role' => 'resident',
            'user' => $user->load('barangay'),
        ]);
    }

    /**
     * Proves the caller knows the account's password, rather than merely
     * holding a token issued for it.
     *
     * Checked with Hash::check against the row, not with Laravel's
     * `current_password` rule: that rule resolves the user from the default
     * auth guard, which is `web`, while this request authenticates through
     * `auth:sanctum` — so it would compare against a null user and reject a
     * correct password. Both logins in this controller check the same way.
     */
    private function assertCurrentPassword(Request $request, Resident $resident): void
    {
        $current = (string) $request->input('current_password', '');

        // One message for a missing password and a wrong one. The caller
        // already holds a token for this account, so there is nothing to
        // disclose by separating them — but there is nothing to gain either,
        // and the client renders whichever it gets as-is.
        if ($current === '' || ! Hash::check($current, (string) $resident->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Enter your current password to change the email address or phone number on this account.',
            ]);
        }
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
        $request->validate([
            'email_address' => 'required|email',
            'password' => 'required',
        ]);

        $email = $request->email_address;
        $resident = Resident::where('email_address', $email)->first();

        // A registration that has not been verified has no row, so a resident
        // who closed the app on the code screen would otherwise be told their
        // own password is wrong. The pending sign-up carries the same hash the
        // row would have, and answers the same way.
        $pending = $resident ? null : $this->pendingSignup($email);
        $hash = $resident->password ?? ($pending['attributes']['password'] ?? null);

        if ($hash === null || ! Hash::check($request->password, (string) $hash)) {
            return response()->json([
                'message' => 'Invalid resident credentials.',
            ], 401);
        }

        // 2026-08-05 quality-check finding 5 asked why residentLogin has no
        // `status` check when adminLogin has one. On 2026-08-08 that was
        // answered deliberately — leave it open — and this comment said so at
        // length, from just below the verification branch:
        //
        //     "There is deliberately NO `status` check here, unlike adminLogin
        //      above. [...] An `Inactive` resident can log in and file service
        //      requests. The only thing activation gates is who receives an
        //      SMS blast. That is the intended behaviour for now."
        //
        // REVERSED 2026-09-03, for something that argument never addressed: the
        // admin panel has a "Deactivate account" button, and it did nothing a
        // person would call deactivation. The resident stayed signed in, kept
        // filing requests, and merely stopped receiving text blasts. Staff were
        // told the account was closed when it was not. The gap was in the
        // promise, not in the reasoning.
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
        //  - There is still no self-serve reactivation, so this really does
        //    make the office the only way back. That is now the intent rather
        //    than the objection: it is what the button is for.
        //
        // Placed here, above the verification branch, and not where the old
        // comment sat below it. That branch calls issueSignupCode(), and
        // PhilSMS bills every send with no sandbox, so gating afterwards would
        // let repeated logins against a closed account cost real money.
        //
        // STILL OUTSTANDING, mobile side: the login screen needs a branch for
        // this `code`, or the resident sees a generic failure and retries
        // forever.
        if ($resident && $resident->isDeactivated()) {
            return response()->json([
                'message' => 'This account has been deactivated. Please visit the MDRRMO office.',
                'code' => 'account_deactivated',
            ], 403);
        }

        // Only an abandoned registration reaches this. Verification happens as
        // the last step of signing up, so a resident who finished it never sees
        // this refusal — and one who closed the app halfway can resume from the
        // code screen instead of being told their password is wrong.
        //
        // Checked after the password on purpose: answering before it would turn
        // this route into an oracle for which addresses have accounts.
        if ($pending || ! $resident->hasVerifiedEmail()) {
            // The client answers this by opening the code screen, so a code has
            // to be in flight by the time it gets there. Without this the
            // resident waited on a message nobody had sent and only got one by
            // tapping Resend.
            //
            // Guarded by the same per-sign-up cooldown the resend route uses,
            // and for the same reason: PhilSMS bills every send and has no
            // sandbox. Login is retried far more often than Resend is tapped,
            // so an unguarded send here would be the most expensive line in
            // the app. Inside the cooldown the outstanding code is still valid
            // and still has most of its 15 minutes left, so there is nothing
            // to reissue — and the entry already records the contact it went
            // to, so the code screen can still label itself correctly.
            $entry = $pending ?? $this->pendingSignup($email) ?? $this->adoptUnverifiedResident($resident);

            if ($this->signupResendWait($entry) === 0) {
                $entry = $this->issueSignupCode($entry);
            }

            return response()->json([
                'message' => 'Please enter the code we just sent to finish creating your account.',
                'code' => 'email_unverified',
                'email_address' => $email,
            ] + $this->signupDeliveryPayload($entry), 403);
        }

        // The `status` gate this comment used to argue against now exists, above
        // the verification branch — see there for the 2026-08-08 decision and
        // the 2026-09-03 reversal. It refuses 'Deactivated' only; 'Inactive'
        // still reaches this line, by design.

        // Password proven and the account is a real one. A token is not issued
        // yet — a code goes to the resident's phone (or mail, same fallback
        // issueSignupCode uses) and has to come back to /resident/login/verify
        // first. This is deliberately a *different* code from the signup one:
        // it lives under its own cache key, so a login attempt can never spend
        // or clobber an in-flight signup code and vice versa.
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
     * Test-only shortcut for the resident OTP — both sign-up verification and
     * login — so an automated client can finish either without reading the
     * SMS or email a real code goes to.
     *
     * Two conditions both have to hold, checked here rather than trusted from
     * the boot-time guard alone (AppServiceProvider::assertOtpBypassIsLocalOnly):
     * the config value must be non-empty, AND the running environment must be
     * `local`. An allow-list, not "not production": a staging or demo server
     * is still reachable by real residents. Deliberately redundant with the
     * boot guard, so a stale config cache or a refactor that drops that guard
     * cannot silently reopen this anywhere else.
     *
     * hash_equals rather than === : the bypass code is short and fixed, so a
     * timing side-channel on it is unlikely to matter in practice, but there
     * is no reason to compare a secret any other way.
     */
    private function otpBypassMatches(string $code): bool
    {
        $bypass = (string) config('serbis.otp_bypass_code', '');

        return $bypass !== ''
            && app()->environment('local')
            && hash_equals($bypass, $code);
    }

    /**
     * Every bypass use is written to tbl_system_logs, same shape TracksHistory
     * uses, so a real login that skipped OTP delivery is never invisible in
     * the audit trail even though it went through the resident's own model
     * events unchanged (issuing a token isn't a model write, so
     * TracksHistory has nothing to hook here on its own).
     */
    private function logOtpBypassUse(Resident $resident): void
    {
        DB::table('tbl_system_logs')->insert([
            'admin_id' => null,
            'resident_id' => $resident->getKey(),
            'action_type' => 'otp_bypass_used',
            'auditable_type' => Resident::class,
            'auditable_id' => $resident->getKey(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Issues a replacement login code against an existing challenge. Takes the
     * challenge id, not the email/password — the resident already proved the
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

        ['channel' => $channel, 'challenge_id' => $challengeId] = $this->sendLoginCode($resident, $request->challenge_id);

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
        ] + $this->deliveryPayloadFor($resident, $channel, $challengeId), 200);
    }

    /**
     * Sends (or resends, against an existing challenge id) a login code for a
     * resident and stores its hash in the challenge — never in the pending
     * sign-up entry, which belongs to the signup gate alone.
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

        if ($this->smsIsUsable($resident->phone_number)) {
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
                    'error' => $e->getMessage(),
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

        if (! is_int($sentAt)) {
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
            'message' => 'Successfully logged out',
        ]);
    }
}
