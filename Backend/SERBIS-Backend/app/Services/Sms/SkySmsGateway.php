<?php

namespace App\Services\Sms;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SkySMS (https://skysms.skyio.site/api/v1). Credits, not a subscription:
 * one credit is one standard 160-character message, and there is no sandbox,
 * so every request that leaves this class is billed.
 *
 * Nothing here logs a message body. OTP texts carry the code itself.
 */
class SkySmsGateway implements SmsGateway
{
    /** A phone may be waiting on this request; it must stay under the app's own 15s client timeout. */
    private const SINGLE_TIMEOUT = 6;

    /** A bulk request carries up to MAX_BULK recipients and nobody is waiting on it. */
    private const BULK_TIMEOUT = 20;

    public const MAX_BULK = 1000;

    public const MAX_MESSAGE_LENGTH = 1000;

    /** Cache keys read by SmsController::balance() — there is no balance endpoint to ask. */
    public const CACHE_CREDITS = 'sms:credits_remaining';

    public const CACHE_CREDITS_AT = 'sms:credits_remaining_at';

    public const CACHE_OUT_OF_CREDITS = 'sms:out_of_credits';

    private const CACHE_BULK_SHAPE_LOGGED = 'sms:bulk_shape_logged';

    /** The longest Retry-After an OTP will sit through; past this the person is better told to try again. */
    public const OTP_MAX_WAIT_SECONDS = 5;

    private readonly Closure $sleeper;

    public function __construct(?Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    public function configured(): bool
    {
        return filled(config('services.skysms.api_key'));
    }

    public function sendOne(string $phone, string $message): SmsResult
    {
        $number = PhoneNumber::normalize($phone);

        if ($number === '') {
            return SmsResult::rejected(SmsResult::REASON_INVALID_NUMBER, null, 'not a dialable Philippine mobile number');
        }

        return $this->dispatch('/sms/send', ['phone_number' => $number, 'message' => $message], $message, self::SINGLE_TIMEOUT, false);
    }

    public function sendOtp(string $phone, string $message): SmsResult
    {
        $result = $this->sendOne($phone, $message);

        // Once, and only for a short, stated wait. No Retry-After means we do not
        // know how long, and a longer one is not something to hold a phone on.
        if ($result->isRateLimited() && $result->retryAfter !== null && $result->retryAfter <= self::OTP_MAX_WAIT_SECONDS) {
            if ($result->retryAfter > 0) {
                ($this->sleeper)((float) $result->retryAfter);
            }

            $result = $this->sendOne($phone, $message);
        }

        return $result;
    }

    public function sendBulk(array $phones, string $message): SmsResult
    {
        $numbers = collect($phones)
            ->map(fn ($phone) => PhoneNumber::normalize((string) $phone))
            ->filter()
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            return SmsResult::rejected(SmsResult::REASON_INVALID_NUMBER, null, 'no dialable recipients');
        }

        if ($numbers->count() > self::MAX_BULK) {
            throw new \InvalidArgumentException('A bulk send takes at most '.self::MAX_BULK.' recipients; chunk it first.');
        }

        return $this->dispatch(
            '/sms/send-bulk',
            [
                'recipients' => $numbers->map(fn (string $n) => ['phone_number' => $n])->all(),
                'message' => $message,
            ],
            $message,
            self::BULK_TIMEOUT,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $path, array $payload, string $message, int $timeout, bool $bulk): SmsResult
    {
        if (self::fakingEnabled()) {
            Log::info('SkySMS send suppressed (SERBIS_SMS_FAKE)', ['bulk' => $bulk]);

            return SmsResult::accepted('fake');
        }

        if (! $this->configured()) {
            Log::warning('SkySMS send skipped: SKYSMS_API_KEY is not set');

            return SmsResult::rejected(SmsResult::REASON_NOT_CONFIGURED);
        }

        // Before any request leaves. A link or domain is penalised and reported
        // as sent without being delivered, so it costs credits and reaches
        // nobody. Refused here, whatever the caller thought it was sending.
        if (SmsMessagePolicy::containsLink($message)) {
            Log::warning('SkySMS send refused locally: the message contains a URL or domain', ['length' => mb_strlen($message)]);

            return SmsResult::rejected(SmsResult::REASON_BLOCKED_CONTENT, null, 'message contains a URL or domain');
        }

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH || trim($message) === '') {
            return SmsResult::rejected(SmsResult::REASON_INVALID, null, 'message is empty or longer than '.self::MAX_MESSAGE_LENGTH.' characters');
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => (string) config('services.skysms.api_key'),
                'User-Agent' => 'SERBIS/1.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                // DNS and the handshake do not take longer because there are
                // more recipients in the body.
                ->connectTimeout(3)
                ->post(rtrim((string) config('services.skysms.base_url'), '/').$path, $payload);
        } catch (ConnectionException $e) {
            // The request left and no answer came back. The vendor may have
            // taken it and billed it — we stopped listening, the send did not
            // stop. Never "delivered", and never a reason to send it again.
            Log::warning('SkySMS request did not complete; delivery is UNKNOWN', [
                'bulk' => $bulk,
                'error' => $e->getMessage(),
            ]);

            return SmsResult::unknown($e->getMessage());
        }

        if ($bulk) {
            $this->logBulkShapeOnce($response);
        }

        return $this->classify($response, $bulk);
    }

    private function classify(Response $response, bool $bulk): SmsResult
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $detail = $body['message'] ?? $body['error'] ?? null;
        $detail = is_string($detail) ? $detail : null;

        if ($status === 402) {
            // Nothing will send until someone tops the account up. Flagged so
            // the admin panel can say so instead of leaving every blast to fail
            // one at a time.
            Cache::put(self::CACHE_OUT_OF_CREDITS, true, now()->addDay());
            Log::error('SkySMS is out of credits (402); no message can be sent until the account is topped up', ['bulk' => $bulk]);

            return SmsResult::rejected(SmsResult::REASON_OUT_OF_CREDITS, $status, $detail, null, $body);
        }

        if ($status === 429) {
            $retryAfter = $this->retryAfterSeconds($response);
            Log::warning('SkySMS rate limit hit (429)', ['retry_after' => $retryAfter, 'bulk' => $bulk]);

            return SmsResult::rejected(SmsResult::REASON_RATE_LIMITED, $status, $detail, $retryAfter, $body);
        }

        if ($status === 401 || $status === 403) {
            Log::error('SkySMS refused the API key', ['status' => $status]);

            return SmsResult::rejected(SmsResult::REASON_AUTH, $status, $detail, null, $body);
        }

        if ($status >= 500) {
            Log::error('SkySMS server error', ['status' => $status, 'bulk' => $bulk]);

            return SmsResult::rejected(SmsResult::REASON_SERVER, $status, $detail, null, $body);
        }

        if (! $response->successful()) {
            Log::warning('SkySMS rejected the request', ['status' => $status, 'detail' => $detail, 'bulk' => $bulk]);

            return SmsResult::rejected(SmsResult::REASON_INVALID, $status, $detail, null, $body);
        }

        // A "warning" means the vendor took the message and penalised it —
        // typically a link or profanity — and it will be reported as sent while
        // not being delivered. Treated as failed, so nobody believes it went.
        if (! empty($body['warning'])) {
            Log::warning('SkySMS returned a warning; treating the send as failed', [
                'warning' => $body['warning'],
                'queue_id' => $body['queue_id'] ?? null,
                'bulk' => $bulk,
            ]);

            $warning = is_string($body['warning']) ? $body['warning'] : json_encode($body['warning']);

            return SmsResult::rejected(SmsResult::REASON_WARNING, $status, $warning, null, $body);
        }

        // Explicit false only: the bulk reply's shape is not documented, so a
        // 2xx without a `success` key is taken at its status.
        if (($body['success'] ?? null) === false) {
            Log::warning('SkySMS answered success=false', ['status' => $status, 'detail' => $detail, 'bulk' => $bulk]);

            return SmsResult::rejected(SmsResult::REASON_INVALID, $status, $detail, null, $body);
        }

        $credits = $body['credits_remaining'] ?? $body['data']['credits_remaining'] ?? null;
        $credits = is_numeric($credits) ? (int) $credits : null;

        if ($credits !== null) {
            Cache::forever(self::CACHE_CREDITS, $credits);
            Cache::forever(self::CACHE_CREDITS_AT, now()->toIso8601String());
        }

        Cache::forget(self::CACHE_OUT_OF_CREDITS);

        $queueId = $body['queue_id'] ?? $body['data']['queue_id'] ?? $body['batch_id'] ?? $body['data']['batch_id'] ?? null;

        return SmsResult::accepted(is_scalar($queueId) ? (string) $queueId : null, $credits, $status, $body);
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(0, (int) $header) : null;
    }

    /**
     * The bulk reply is undocumented, so the first one is written down as it
     * arrived — status and body, capped — for whoever reads the log to learn
     * the real shape. Once, not on every blast.
     */
    private function logBulkShapeOnce(Response $response): void
    {
        if (! Cache::add(self::CACHE_BULK_SHAPE_LOGGED, true)) {
            return;
        }

        Log::warning('SkySMS bulk response, first use (raw, for documentation)', [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 4000),
        ]);
    }

    /**
     * The test-only suppression flag (config/serbis.php). Checked here, at the
     * point of the call, and again against the environment: a config value
     * cached before an environment change must not reopen this in production.
     */
    private static function fakingEnabled(): bool
    {
        return (bool) config('serbis.sms_fake', false) && ! app()->environment('production');
    }
}
