<?php

namespace App\Services\Sms;

/**
 * What became of one send request, in the three states a caller has to treat
 * differently:
 *
 * - accepted: the vendor took it. That is not delivery — the vendor's own
 *   status endpoint is the only proof of that.
 * - rejected: the vendor (or a local check) said no, so nothing went out and
 *   nothing was billed. Safe to retry or to fall back.
 * - unknown: the request left and no answer came back (a timeout). The message
 *   may well have been sent and billed, so it must never be recorded as
 *   delivered and never be blindly re-sent.
 */
final class SmsResult
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const UNKNOWN = 'unknown';

    public const REASON_OUT_OF_CREDITS = 'out_of_credits';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_WARNING = 'warning';

    public const REASON_BLOCKED_CONTENT = 'blocked_content';

    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_INVALID_NUMBER = 'invalid_number';

    public const REASON_AUTH = 'auth';

    public const REASON_INVALID = 'invalid';

    public const REASON_SERVER = 'server_error';

    public const REASON_TIMEOUT = 'timeout';

    /**
     * @param  array<string, mixed>  $body  The vendor's decoded reply, when there was one.
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $queueId = null,
        public readonly ?int $creditsRemaining = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $detail = null,
        public readonly array $body = [],
    ) {}

    public static function accepted(?string $queueId = null, ?int $creditsRemaining = null, ?int $httpStatus = 200, array $body = []): self
    {
        return new self(self::ACCEPTED, null, $httpStatus, $queueId, $creditsRemaining, null, null, $body);
    }

    public static function rejected(string $reason, ?int $httpStatus = null, ?string $detail = null, ?int $retryAfter = null, array $body = []): self
    {
        return new self(self::REJECTED, $reason, $httpStatus, null, null, $retryAfter, $detail, $body);
    }

    public static function unknown(?string $detail = null): self
    {
        return new self(self::UNKNOWN, self::REASON_TIMEOUT, null, null, null, null, $detail);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->outcome === self::REJECTED;
    }

    public function isUnknown(): bool
    {
        return $this->outcome === self::UNKNOWN;
    }

    public function isOutOfCredits(): bool
    {
        return $this->reason === self::REASON_OUT_OF_CREDITS;
    }

    public function isRateLimited(): bool
    {
        return $this->reason === self::REASON_RATE_LIMITED;
    }
}
