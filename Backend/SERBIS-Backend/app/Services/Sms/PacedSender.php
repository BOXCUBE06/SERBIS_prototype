<?php

namespace App\Services\Sms;

use Closure;

/**
 * For loops that text people one at a time — the reminders and the
 * availability notice — where nobody is waiting on any single send.
 *
 * The account allows 30 messages a minute and 3 a second, shared with every
 * other send. A loop is held to 20 a minute (a send every three seconds) so the
 * other ten are always free for sign-in codes, which a person is waiting on.
 * If the vendor still answers 429, this backs off — honouring Retry-After when
 * it gives one, doubling from there otherwise — and tries again a few times
 * before handing the failure back. A retry is a send too, so it never goes out
 * sooner than the spacing allows.
 *
 * One instance per loop: the spacing is measured between the sends this
 * instance makes.
 */
class PacedSender
{
    /** The most a loop may send in a minute; the account's limit is 30. */
    public const MAX_PER_MINUTE = 20;

    private bool $sentOnce = false;

    private readonly Closure $sleeper;

    public function __construct(private readonly SmsGateway $gateway, ?Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    public function send(string $phone, string $message): SmsResult
    {
        $pace = (float) config('services.skysms.pace_seconds', 60 / self::MAX_PER_MINUTE);

        if ($this->sentOnce) {
            $this->wait($pace);
        }

        $this->sentOnce = true;

        $base = (float) config('services.skysms.retry_base_seconds', 2);
        $maxRetries = (int) config('services.skysms.max_retries', 3);

        $result = $this->gateway->sendOne($phone, $message);

        for ($attempt = 0; $attempt < $maxRetries && $result->isRateLimited(); $attempt++) {
            $this->wait(max((float) ($result->retryAfter ?? 0), $base * (2 ** $attempt), $pace));
            $result = $this->gateway->sendOne($phone, $message);
        }

        return $result;
    }

    private function wait(float $seconds): void
    {
        if ($seconds > 0) {
            ($this->sleeper)($seconds);
        }
    }
}
