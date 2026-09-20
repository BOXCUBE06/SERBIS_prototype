<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SendsResidentCodes;
use App\Models\Resident;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * A resident who has forgotten their password gets it back with their phone.
 *
 *   1. POST /resident/password/forgot   the number. A code is texted to it — when
 *                                       an account holds it.
 *   2. POST /resident/password/verify   the number and the code. Answers a
 *                                       short-lived reset token.
 *   3. POST /resident/password/reset    the number, the token and a new password.
 *
 * The routes say nothing about which numbers have accounts. Step one answers the
 * same body whether or not one does, and whether or not a text went out; step
 * two answers a wrong code, an expired one and an unknown number identically.
 * The work that differs — looking the number up, generating a code, the send that
 * takes a moment — runs after the response has gone, so how long the answer took
 * does not say either.
 *
 * The limits are keyed on the number (Route: 'password-reset' limiter), so they
 * are the same for a number with an account and one without. Nothing is sent to
 * an unknown or deactivated number: a billed text to a number that cannot use it
 * would only be a way to spend the agency's credits.
 */
class PasswordResetController extends Controller
{
    use SendsResidentCodes;

    /** Wrong guesses one code tolerates before it is retired. */
    private const MAX_ATTEMPTS = 5;

    private const NEUTRAL_MESSAGE = 'If this number has an account, a code is on its way.';

    private const INVALID_CODE_MESSAGE = 'That code is not right, or it has expired. Ask for a new one.';

    private function phoneHash(string $phone): string
    {
        return hash('sha256', $phone);
    }

    private function codeKey(string $phone): string
    {
        return 'pwreset:code:'.$this->phoneHash($phone);
    }

    private function tokenKey(string $token): string
    {
        return 'pwreset:token:'.hash('sha256', $token);
    }

    private function invalidCode(): JsonResponse
    {
        return response()->json([
            'message' => self::INVALID_CODE_MESSAGE,
            'code' => 'invalid_code',
        ], 422);
    }

    /**
     * Step one. Always 200 with the same body for a well-formed number.
     */
    public function forgot(Request $request)
    {
        $request->validate([
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX],
        ]);

        $phone = PhoneNumber::normalize($request->phone_number);

        // Set for EVERY number, so asking twice inside the minute is answered the
        // same way whether or not the first ask sent anything.
        $cooldown = 'pwreset:cooldown:'.$this->phoneHash($phone);
        $first = Cache::add($cooldown, true, Resident::RESEND_COOLDOWN_SECONDS);

        if ($first) {
            // After the response, so the lookup and the SMS round trip cannot be
            // timed. A plain terminating callback rather than a queued closure:
            // nothing here is worth serialising. Guarded to run once, because
            // callbacks registered on a long-lived application (a test, a
            // worker) are run again by every later request's terminate.
            $fired = false;

            app()->terminating(function () use ($phone, &$fired) {
                if ($fired) {
                    return;
                }

                $fired = true;
                $this->sendCodeIfAccountExists($phone);
            });
        }

        return response()->json([
            'message' => self::NEUTRAL_MESSAGE,
            'retry_after' => Resident::RESEND_COOLDOWN_SECONDS,
        ]);
    }

    /**
     * The half of step one that differs by number. Never throws and never says
     * anything: whatever happens here is only ever logged.
     */
    private function sendCodeIfAccountExists(string $phone): void
    {
        $resident = Resident::where('phone_number', $phone)->first();

        // An unknown number and a closed account get nothing.
        if (! $resident || $resident->isDeactivated()) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $delivery = $this->sendOtpText(
            $phone,
            "Your SERBIS password reset code is {$code}. It expires in ".Resident::PHONE_CHANGE_TTL_MINUTES.' minutes.',
        );

        // A text that never went out leaves no code to guess. The resident simply
        // sees no message and asks again after the cooldown; the client cannot be
        // told, because telling it would tell an outsider the number has an
        // account.
        if ($delivery === 'failed') {
            return;
        }

        Cache::put($this->codeKey($phone), [
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(Resident::PHONE_CHANGE_TTL_MINUTES)->getTimestamp(),
            'attempts' => 0,
        ], now()->addMinutes(Resident::PHONE_CHANGE_TTL_MINUTES));
    }

    /**
     * Step two. A wrong code, an expired one, a number with no account and a
     * closed account all answer 422 invalid_code.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX],
            'code' => 'required|string|size:6',
        ]);

        $phone = PhoneNumber::normalize($request->phone_number);
        $entry = Cache::get($this->codeKey($phone));

        if (! is_array($entry)) {
            return $this->invalidCode();
        }

        $resident = Resident::where('phone_number', $phone)->first();

        if (! $resident || $resident->isDeactivated()) {
            return $this->invalidCode();
        }

        $bypassed = $this->otpBypassMatches((string) $request->code);
        $expiresAt = $entry['expires_at'] ?? null;
        $live = is_int($expiresAt) && $expiresAt > now()->getTimestamp();

        if (! $bypassed && ! ($live && Hash::check((string) $request->code, (string) ($entry['code_hash'] ?? '')))) {
            $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;

            if ($entry['attempts'] >= self::MAX_ATTEMPTS || ! $live) {
                // Retired: the code is dropped, not merely counted, so a spent
                // code cannot be guessed after the cap. A wrong guess answers the
                // same 422 as any other, never a 429 that only a real account
                // could produce.
                Cache::forget($this->codeKey($phone));
            } else {
                Cache::put($this->codeKey($phone), $entry, $expiresAt - now()->getTimestamp());
            }

            return $this->invalidCode();
        }

        // Single-use: the code is spent the moment it is accepted.
        Cache::forget($this->codeKey($phone));

        $token = Str::random(48);
        Cache::put($this->tokenKey($token), ['phone_hash' => $this->phoneHash($phone)], now()->addMinutes(Resident::PHONE_CHANGE_TTL_MINUTES));

        if ($bypassed) {
            $this->logOtpBypassUse($resident);
        }

        return response()->json(['reset_token' => $token]);
    }

    /**
     * Step three: the new password.
     */
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX],
            'reset_token' => 'required|string|max:100',
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $phone = PhoneNumber::normalize($validated['phone_number']);
        $entry = Cache::get($this->tokenKey($validated['reset_token']));

        // A token opens the reset for the number it was issued for and no other.
        $resident = is_array($entry) && hash_equals((string) ($entry['phone_hash'] ?? ''), $this->phoneHash($phone))
            ? Resident::where('phone_number', $phone)->first()
            : null;

        if (! $resident || $resident->isDeactivated()) {
            return response()->json([
                'message' => 'This reset has expired. Start again.',
                'code' => 'reset_expired',
            ], 422);
        }

        Cache::forget($this->tokenKey($validated['reset_token']));

        $resident->password = Hash::make($validated['password']);
        $resident->save();

        // Every session ends. There is no current one to spare — the resident is
        // signed out, that is why they reset — and a token an intruder holds must
        // not outlive the password it was issued under.
        $resident->tokens()->delete();

        // The trait that logs model changes ignores the password column, so a
        // reset would otherwise leave no trace at all.
        DB::table('tbl_system_logs')->insert([
            'admin_id' => null,
            'resident_id' => $resident->getKey(),
            'action_type' => 'password_reset_by_sms',
            'auditable_type' => Resident::class,
            'auditable_id' => $resident->getKey(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Your password has been changed. Log in with your new password.',
        ]);
    }
}
