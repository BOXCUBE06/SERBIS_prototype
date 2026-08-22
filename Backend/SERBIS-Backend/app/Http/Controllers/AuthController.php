<?php

namespace App\Http\Controllers;

use App\Mail\ResidentVerificationCode;
use App\Models\User; // Represents Admins/Staff
use App\Models\Resident;
use App\Services\PhilSms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        $this->sendVerificationCode($resident);

        // Still no token and still no 'message' key — the mobile client reads any
        // `message` on this response as an error to show the resident. A token is
        // withheld for a second reason now: the account is not usable until the
        // emailed code comes back, so there is nothing for a token to authorise.
        // `verification_required` is what sends the client to the code screen.
        return response()->json([
            'resident' => $resident,
            'verification_required' => true,
            'email_address' => $resident->email_address,
        ], 201);
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

        $this->sendVerificationCode($resident);

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
        ]);
    }

    /**
     * The plain code exists only between these two lines. Everything stored is
     * hashed, so this is the single point where it can be sent.
     */
    private function sendVerificationCode(Resident $resident): void
    {
        $code = $resident->issueVerificationCode();

        // SMS is the primary channel: a resident registering on a phone reads the
        // code without leaving the handset, and the deployment has an SMS vendor
        // configured before it has a mail one. Email stays as the fallback for a
        // number the vendor cannot dial — the column is still email_verified_at
        // either way, because what is being proven is ownership of the account,
        // not of a particular channel.
        if (PhilSms::configured() && PhilSms::normalize((string) $resident->phone_number) !== '') {
            $response = app(PhilSms::class)->send(
                [$resident->phone_number],
                "Your SERBIS verification code is {$code}. It expires in ".Resident::CODE_TTL_MINUTES.' minutes.',
            );

            if (PhilSms::accepted($response)) {
                return;
            }

            Log::warning('OTP SMS failed, falling back to email', [
                'resident_id' => $resident->resident_id,
                'status'      => $response->status(),
            ]);
        }

        Mail::to($resident->email_address)->send(
            new ResidentVerificationCode($resident, $code)
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

        return response()->json([
            'token' => $admin->createToken(
                'admin-token',
                ['*'],
                now()->addMinutes(config('sanctum.admin_expiration')),
            )->plainTextToken,
            'role' => 'admin',
            'user' => $admin
        ], 200);
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
            return response()->json([
                'message' => 'Please verify your email address to finish creating your account.',
                'code' => 'email_unverified',
                'email_address' => $resident->email_address,
            ], 403);
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

        return response()->json([
            'token' => $resident->createToken(
                'resident-token',
                ['*'],
                now()->addMinutes(config('sanctum.resident_expiration')),
            )->plainTextToken,
            'role' => 'resident',
            // Load the barangay relation so the mobile profile has a location on
            // login, matching what /me returns. Residents have no address column.
            'user' => $resident->load('barangay')
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }
}