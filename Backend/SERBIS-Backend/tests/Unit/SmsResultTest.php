<?php

namespace Tests\Unit;

use App\Services\Sms\SmsResult;
use PHPUnit\Framework\TestCase;

class SmsResultTest extends TestCase
{
    public function test_accepted_carries_ids_and_credits(): void
    {
        $r = SmsResult::accepted('77', 997, 201, [], ['1', '2']);

        $this->assertTrue($r->isAccepted());
        $this->assertFalse($r->isRejected() || $r->isUnknown());
        $this->assertSame('77', $r->queueId);
        $this->assertSame(['1', '2'], $r->queueIds);
        $this->assertSame(997, $r->creditsRemaining);
    }

    public function test_rejected_keeps_the_reason(): void
    {
        $r = SmsResult::rejected(SmsResult::REASON_OUT_OF_CREDITS, 402);

        $this->assertTrue($r->isRejected());
        $this->assertTrue($r->isOutOfCredits());
        $this->assertFalse($r->isRateLimited());
        $this->assertFalse($r->isAccepted());
    }

    public function test_rate_limited_carries_retry_after(): void
    {
        $r = SmsResult::rejected(SmsResult::REASON_RATE_LIMITED, 429, null, 30);

        $this->assertTrue($r->isRateLimited());
        $this->assertSame(30, $r->retryAfter);
    }

    public function test_unknown_is_neither_accepted_nor_rejected(): void
    {
        $r = SmsResult::unknown('timed out');

        $this->assertTrue($r->isUnknown());
        $this->assertFalse($r->isAccepted());
        $this->assertFalse($r->isRejected());
        $this->assertSame(SmsResult::REASON_TIMEOUT, $r->reason);
    }
}
