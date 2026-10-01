<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Resident;
use App\Models\User;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What every controller that texts a one-time code to a resident shares: sending
 * it and saying how it went, the shape of the answer, the "could not send"
 * response, and the local-only bypass that lets a test client finish without
 * reading a real text.
 *
 * Extracted from AuthController when changing a phone number, and resetting a
 * password, started to need the same things — each of those is a code sent to a
 * number, and they must agree on what a failed or timed-out send means.
 */
trait SendsResidentCodes
{
    protected const SMS_UNAVAILABLE_MESSAGE = 'We could not send the text message. Check the number and try again in a minute, or visit the MDRRMO office.';

    /** 503 when the code could not be texted, with the pending sign-up or challenge left in place. */
    protected function smsUnavailable(): JsonResponse
    {
        return response()->json([
            'message' => self::SMS_UNAVAILABLE_MESSAGE,
            'code' => 'sms_unavailable',
        ], 503);
    }

    /**
     * Texts a one-time code and says how it went, without the caller having to
     * know the vendor:
     *
     * - 'accepted': the vendor took it.
     * - 'unknown': the request timed out. The code screen still opens, with
     *   Resend, because the text may well be on its way; it is logged as
     *   UNKNOWN, never as delivered.
     * - 'failed': nothing went out (out of credits, rate limited past the short
     *   wait, refused number). There is no email to fall back to, so the caller
     *   answers 503 sms_unavailable and the screen says so.
     *
     * On a developer machine with no SMS key and the OTP bypass code set, the
     * absence of a key is not a failure — the bypass code is how that setup
     * finishes a sign-up. Local only, and only for that one reason.
     */
    protected function sendOtpText(string $phone, string $message): string
    {
        $result = app(SmsGateway::class)->sendOtp($phone, $message);

        if ($result->isAccepted()) {
            return 'accepted';
        }

        if ($result->isUnknown()) {
            Log::warning('OTP SMS send outcome unknown (timed out); showing the code screen, delivery unconfirmed');

            return 'unknown';
        }

        if ($result->reason === SmsResult::REASON_NOT_CONFIGURED
            && app()->environment('local')
            && filled(config('serbis.otp_bypass_code'))) {
            Log::info('OTP SMS skipped: no SMS key on a local machine with the OTP bypass code set');

            return 'accepted';
        }

        Log::warning('OTP SMS rejected', [
            'reason' => $result->reason,
            'status' => $result->httpStatus,
        ]);

        return 'failed';
    }

    /** The one shape every code-sending response carries. */
    protected function deliveryFields(string $phone, string $delivery, int $retryAfter): array
    {
        return [
            'channel' => 'sms',
            'sent_to' => substr(preg_replace('/\D/', '', $phone), -4),
            'retry_after' => $retryAfter,
            'delivery' => $delivery === 'unknown' ? 'unknown' : 'accepted',
        ];
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
    protected function otpBypassMatches(string $code): bool
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
    protected function logOtpBypassUse(Resident|User $account): void
    {
        $isStaff = $account instanceof User;

        DB::table('tbl_system_logs')->insert([
            'admin_id' => $isStaff ? $account->getKey() : null,
            'resident_id' => $isStaff ? null : $account->getKey(),
            'action_type' => 'otp_bypass_used',
            'auditable_type' => $account::class,
            'auditable_id' => $account->getKey(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
