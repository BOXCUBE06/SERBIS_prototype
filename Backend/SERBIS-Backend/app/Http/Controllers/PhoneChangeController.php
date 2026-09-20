<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SendsResidentCodes;
use App\Models\Resident;
use App\Rules\PhoneAvailable;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A resident moves their own phone number.
 *
 * The number is the login, and it is also where every future code goes, so
 * whoever controls it controls the account. A bearer token alone must therefore
 * not be enough to move it — a token lifted from a shared phone would turn into
 * a permanent takeover. Two proofs are asked for, in two steps:
 *
 *   1. POST /me/phone      the new number and the CURRENT PASSWORD. A code is
 *                          texted to the NEW number.
 *   2. POST /me/phone/verify  the code. Only now does the account change.
 *
 * The password proves who is asking; the code proves they hold the number they
 * are moving to — otherwise anyone could claim a number that is not theirs,
 * which would also block its real owner from ever registering it.
 *
 * The pending change lives in the cache, like a sign-up: nothing is written to
 * the account until the code comes back, and an abandoned change lapses on its
 * own. It does not reserve the number — the unique index decides at the end.
 */
class PhoneChangeController extends Controller
{
    use SendsResidentCodes;

    /** Wrong guesses one code tolerates before it is retired — the figure every code in this app uses. */
    private const MAX_ATTEMPTS = 5;

    /** How long a started change waits for its code, however many times the code is resent. */
    private const CHANGE_WINDOW_MINUTES = 30;

    private function key(Resident $resident): string
    {
        return 'phonechange:'.$resident->getKey();
    }

    private function residentOrRefuse(Request $request): Resident|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json(['message' => 'This endpoint is for resident accounts.'], 403);
        }

        return $user;
    }

    /**
     * Step one: check the password, text a code to the new number.
     */
    public function start(Request $request)
    {
        $user = $this->residentOrRefuse($request);

        if (! $user instanceof Resident) {
            return $user;
        }

        $validated = $request->validate([
            'phone_number' => [
                'required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX,
                new PhoneAvailable($user->getKey(), 'That number is already registered to another account.'),
            ],
            'current_password' => 'required|string',
        ]);

        // One message for a missing password and a wrong one. The caller already
        // holds a token for this account, so there is nothing to disclose by
        // separating them — but nothing to gain either.
        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Enter your current password to change your phone number.',
            ]);
        }

        $phone = PhoneNumber::normalize($validated['phone_number']);

        if ($phone === $user->phone_number) {
            throw ValidationException::withMessages([
                'phone_number' => 'That is already your number.',
            ]);
        }

        $entry = $this->issueCode(['phone' => $phone, 'change_expires_at' => now()->addMinutes(self::CHANGE_WINDOW_MINUTES)->getTimestamp()]);

        if ($entry['delivery'] === 'failed') {
            // Nothing was stored and nothing billed, so the form is simply
            // submitted again.
            return $this->smsUnavailable();
        }

        $this->store($user, $entry);

        return response()->json([
            'pending' => true,
            'phone_number' => $phone,
        ] + $this->deliveryFields($phone, $entry['delivery'], Resident::RESEND_COOLDOWN_SECONDS));
    }

    /**
     * A replacement code for a change already started. No password again: the
     * client should not have to keep it in memory across screens, and the
     * pending change already proved it.
     */
    public function resend(Request $request)
    {
        $user = $this->residentOrRefuse($request);

        if (! $user instanceof Resident) {
            return $user;
        }

        $entry = $this->pending($user);

        if ($entry === null) {
            return $this->noPendingChange();
        }

        $sentAt = $entry['sent_at'] ?? null;
        $wait = is_int($sentAt) ? max(0, Resident::RESEND_COOLDOWN_SECONDS - (now()->getTimestamp() - $sentAt)) : 0;

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before asking for another code.",
                'code' => 'resend_too_soon',
                'retry_after' => $wait,
            ], 429);
        }

        $fresh = $this->issueCode($entry);

        if ($fresh['delivery'] === 'failed') {
            return $this->smsUnavailable();
        }

        $this->store($user, $fresh);

        return response()->json([
            'message' => 'A new code is on its way.',
            'code' => 'code_sent',
            'phone_number' => $fresh['phone'],
        ] + $this->deliveryFields($fresh['phone'], $fresh['delivery'], Resident::RESEND_COOLDOWN_SECONDS));
    }

    /**
     * Step two: the code comes back and the account changes.
     */
    public function verify(Request $request)
    {
        $user = $this->residentOrRefuse($request);

        if (! $user instanceof Resident) {
            return $user;
        }

        $request->validate(['code' => 'required|string|size:6']);

        $entry = $this->pending($user);

        if ($entry === null) {
            return $this->noPendingChange();
        }

        $bypassed = $this->otpBypassMatches((string) $request->code);

        if (! $bypassed && ! $this->codeMatches($entry, (string) $request->code)) {
            $attempts = (int) ($entry['attempts'] ?? 0) + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                // The whole change goes, not just the code: starting again asks
                // for the password again, which is the brake on guessing.
                Cache::forget($this->key($user));

                return response()->json([
                    'message' => 'Too many wrong codes. Start the change again.',
                    'code' => 'too_many_attempts',
                ], 429);
            }

            $entry['attempts'] = $attempts;
            $this->store($user, $entry);

            // One message for a wrong code and an expired one: telling them apart
            // tells someone guessing which half they got right.
            return response()->json([
                'message' => 'That code is not right, or it has expired. Ask for a new one.',
                'code' => 'invalid_code',
            ], 422);
        }

        // Checked again: the number was free when the change started, and may
        // not be now. The unique index has the last word.
        try {
            $user->phone_number = $entry['phone'];
            $user->phone_verified_at = now();
            $user->save();
        } catch (UniqueConstraintViolationException) {
            Cache::forget($this->key($user));

            return response()->json([
                'message' => 'That number is already registered to another account.',
                'code' => 'phone_taken',
            ], 422);
        }

        Cache::forget($this->key($user));

        // The number is the login and where every code goes, so a change ends
        // every OTHER session: whoever held the old number may hold a token.
        // This one stays, so the resident is not thrown out of the screen they
        // just finished.
        $others = $user->tokens();
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $others->where('id', '!=', $current->getKey());
        }

        $others->delete();

        if ($bypassed) {
            $this->logOtpBypassUse($user);
        }

        return response()->json([
            'role' => 'resident',
            'user' => $user->load('barangay'),
        ]);
    }

    private function noPendingChange()
    {
        return response()->json([
            'message' => 'There is no phone number change in progress. Start it again.',
            'code' => 'no_pending_change',
        ], 404);
    }

    /** @return array<string, mixed>|null */
    private function pending(Resident $resident): ?array
    {
        $entry = Cache::get($this->key($resident));

        return is_array($entry) ? $entry : null;
    }

    /**
     * Written back with the time the CHANGE has left, never a fresh window, so
     * resending or a wrong guess cannot keep it alive indefinitely. Scalars
     * only — see AuthController::sendLoginCode() for the cache trap.
     *
     * @param  array<string, mixed>  $entry
     */
    private function store(Resident $resident, array $entry): void
    {
        unset($entry['delivery']);

        $seconds = ($entry['change_expires_at'] ?? 0) - now()->getTimestamp();

        if ($seconds <= 0) {
            Cache::forget($this->key($resident));

            return;
        }

        Cache::put($this->key($resident), $entry, $seconds);
    }

    /**
     * A new code for the change, texted to the NEW number. The plain code exists
     * only inside this method; what is stored is its hash.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function issueCode(array $entry): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $delivery = $this->sendOtpText(
            $entry['phone'],
            "Your SERBIS code to confirm your new number is {$code}. It expires in ".Resident::PHONE_CHANGE_TTL_MINUTES.' minutes.',
        );

        if ($delivery === 'failed') {
            Log::warning('Phone change code could not be texted');

            return $entry + ['delivery' => 'failed'];
        }

        // A new code is a new secret, so it gets a new budget of guesses; what
        // stops that being a way around the cap is the resend cooldown and the
        // route limiter.
        $entry['code_hash'] = Hash::make($code);
        $entry['expires_at'] = now()->addMinutes(Resident::PHONE_CHANGE_TTL_MINUTES)->getTimestamp();
        $entry['attempts'] = 0;
        $entry['sent_at'] = now()->getTimestamp();
        $entry['delivery'] = $delivery;

        return $entry;
    }

    /** @param  array<string, mixed>  $entry */
    private function codeMatches(array $entry, string $code): bool
    {
        $expiresAt = $entry['expires_at'] ?? null;

        if (! is_int($expiresAt) || $expiresAt <= now()->getTimestamp()) {
            return false;
        }

        return Hash::check($code, (string) ($entry['code_hash'] ?? ''));
    }
}
