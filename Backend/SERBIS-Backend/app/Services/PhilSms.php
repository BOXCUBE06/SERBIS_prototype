<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The single place this application talks to PhilSMS. Both callers — the admin
 * text blast and the registration OTP — go through here so the endpoint, the
 * sender id and the number format are decided once.
 *
 * There is no sandbox. Every request that leaves this class is a billed send to
 * a real handset, which is why the tests fake the host rather than the class.
 */
class PhilSms
{
    private const ENDPOINT = 'https://dashboard.philsms.com/api/v3/sms/send';

    /**
     * @param  array<int, string>  $numbers
     */
    public function send(array $numbers, string $message): Response
    {
        // PhilSMS takes many recipients as one comma-separated string, not as a
        // list of objects the way the previous vendor did.
        $recipients = collect($numbers)
            ->map(fn (string $number) => self::normalize($number))
            ->filter()
            ->unique()
            ->implode(',');

        return Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.philsms.token'),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])
            // Guzzle has no timeout by default, so a slow PhilSMS response used to
            // run into PHP's own max_execution_time (30s, php.ini-production) —
            // a fatal script-kill, not a Throwable, that no caller's try/catch can
            // intercept. Timing out here first turns that into an ordinary
            // ConnectionException the caller can actually catch.
            //
            // Kept well under the mobile client's own 15s per-request timeout
            // (api_service.dart) too: this is one leg of that request, not the
            // whole thing, so it needs headroom left for bcrypt/cache/JSON
            // overhead and real network latency on top, or the phone can still
            // give up first even after the backend answers correctly.
            ->timeout(6)
            ->connectTimeout(3)
            ->post(self::ENDPOINT, [
            'recipient' => $recipients,
            'sender_id' => config('services.philsms.sender_id'),
            'type'      => 'plain',
            'message'   => $message,
        ]);
    }

    /**
     * PhilSMS rejects anything that is not an E.164 Philippine number, while
     * tbl_residents.phone_number is a free string a resident typed. Accepts the
     * three shapes people actually enter — 09171234567, 639171234567,
     * +639171234567 — and returns an empty string for anything else so the
     * caller drops it rather than paying for a guaranteed failure.
     */
    public static function normalize(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return match (true) {
            str_starts_with($digits, '639') && strlen($digits) === 12 => '+'.$digits,
            str_starts_with($digits, '09') && strlen($digits) === 11   => '+63'.substr($digits, 1),
            str_starts_with($digits, '9') && strlen($digits) === 10    => '+63'.$digits,
            default                                                    => '',
        };
    }

    /**
     * A developer machine has no token. Without this the OTP path would make a
     * real outbound request on every local registration, wait for the 401 and
     * only then fall back to mail.
     */
    public static function configured(): bool
    {
        return filled(config('services.philsms.token'));
    }

    /**
     * A 200 is not proof on its own: PhilSMS answers some rejections with a 200
     * carrying status "error" in the body.
     */
    public static function accepted(Response $response): bool
    {
        return $response->successful() && $response->json('status') !== 'error';
    }
}
