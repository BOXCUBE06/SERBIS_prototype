<?php

namespace App\Services\Sms;

use Closure;

/**
 * For loops that text people one at a time — the reminders and the
 * availability notice — where nobody is waiting on any single send.
 *
 * The account allows 30 messages a minute and 3 a second, shared with every
 * other send (an OTP included). Spacing sends by two seconds keeps a loop at
 * or under 30 a minute; if the vendor still answers 429, this backs off —
 * honouring Retry-After when it gives one, doubling from there otherwise — and
 * tries again a few times before handing the failure back.
 *
 * One instance per loop: the spacing is measured between the sends this
 * instance makes.
 */
class PacedSender
{
    private bool $sentOnce = false;

    private readonly Closure $sleeper;

    public function __construct(private readonly SmsGateway $gateway, ?Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    public function send(string $phone, string $message): SmsResult
    {
        if ($this->sentOnce) {
            $this->wait((float) config('services.skysms.pace_seconds', 2));
        }

        $this->sentOnce = true;

        $base = (float) config('services.skysms.retry_base_seconds', 2);
        $maxRetries = (int) config('services.skysms.max_retries', 3);

        $result = $this->gateway->sendOne($phone, $message);

        for ($attempt = 0; $attempt < $maxRetries && $result->isRateLimited(); $attempt++) {
            $this->wait(max((float) ($result->retryAfter ?? 0), $base * (2 ** $attempt)));
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
