<?php

namespace Tests\Feature;

use App\Services\Sms\PacedSender;
use App\Services\Sms\SkySmsGateway;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsMessagePolicy;
use App\Services\Sms\SmsResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The SkySMS gateway, against a faked host — the vendor has no sandbox, so a
 * request that escaped these fakes would be a billed message to a real phone.
 */
class SkySmsGatewayTest extends TestCase
{
    private const HOST = 'skysms.skyio.site/*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
    }

    private function gateway(): SmsGateway
    {
        return app(SmsGateway::class);
    }

    private function ok(array $extra = []): array
    {
        return array_merge([
            'success' => true,
            'queue_id' => 'q-1',
            'status' => 'pending',
            'credits_used' => 1,
            'credits_remaining' => 99,
        ], $extra);
    }

    // ---- success ---------------------------------------------------------

    public function test_a_send_posts_the_normalised_number_with_the_key_and_a_real_user_agent(): void
    {
        Http::fake([self::HOST => Http::response($this->ok(), 200)]);

        $result = $this->gateway()->sendOne('09171234567', 'Your SERBIS login code is 123456.');

        $this->assertTrue($result->isAccepted());
        $this->assertSame('q-1', $result->queueId);
        $this->assertSame(99, $result->creditsRemaining);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://skysms.skyio.site/api/v1/sms/send'
                && $request->hasHeader('X-API-Key', 'testing-key')
                && $request->hasHeader('User-Agent', 'SERBIS/1.0')
                && $request['phone_number'] === '+639171234567'
                && $request['message'] === 'Your SERBIS login code is 123456.';
        });
    }

    public function test_the_remaining_credits_are_remembered_for_the_admin_page(): void
    {
        Http::fake([self::HOST => Http::response($this->ok(['credits_remaining' => 42]), 200)]);

        $this->gateway()->sendOne('09171234567', 'Hello');

        $this->assertSame(42, Cache::get(SkySmsGateway::CACHE_CREDITS));
    }

    public function test_an_unusable_number_is_refused_without_a_request(): void
    {
        Http::fake();

        $result = $this->gateway()->sendOne('0288888888', 'Hello');

        $this->assertTrue($result->isRejected());
        $this->assertSame(SmsResult::REASON_INVALID_NUMBER, $result->reason);
        Http::assertNothingSent();
    }

    // ---- warning is failure ---------------------------------------------

    public function test_a_response_with_a_warning_is_a_failure_and_is_logged(): void
    {
        Log::spy();
        Http::fake([self::HOST => Http::response($this->ok(['warning' => 'Message contains a URL; penalty applied']), 200)]);

        $result = $this->gateway()->sendOne('09171234567', 'Hello');

        $this->assertTrue($result->isRejected());
        $this->assertSame(SmsResult::REASON_WARNING, $result->reason);
        $this->assertStringContainsString('URL', (string) $result->detail);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'warning'))
            ->once();
    }

    public function test_success_false_is_a_failure(): void
    {
        Http::fake([self::HOST => Http::response(['success' => false, 'message' => 'nope'], 200)]);

        $this->assertTrue($this->gateway()->sendOne('09171234567', 'Hello')->isRejected());
    }

    // ---- 402 -------------------------------------------------------------

    public function test_402_is_out_of_credits_logged_and_flagged(): void
    {
        Log::spy();
        Http::fake([self::HOST => Http::sequence()
            ->push(['message' => 'Insufficient credits'], 402)
            ->push($this->ok(), 200)]);

        $result = $this->gateway()->sendOne('09171234567', 'Hello');

        $this->assertTrue($result->isRejected());
        $this->assertTrue($result->isOutOfCredits());
        $this->assertTrue(Cache::get(SkySmsGateway::CACHE_OUT_OF_CREDITS));

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'out of credits'))
            ->once();

        // The next accepted send clears the flag: the account was topped up.
        $this->gateway()->sendOne('09171234567', 'Hello');
        $this->assertNull(Cache::get(SkySmsGateway::CACHE_OUT_OF_CREDITS));
    }

    // ---- 429 -------------------------------------------------------------

    public function test_429_carries_retry_after(): void
    {
        Http::fake([self::HOST => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '7'])]);

        $result = $this->gateway()->sendOne('09171234567', 'Hello');

        $this->assertTrue($result->isRateLimited());
        $this->assertSame(7, $result->retryAfter);
    }

    public function test_the_paced_sender_backs_off_on_429_and_then_succeeds(): void
    {
        config(['services.skysms.retry_base_seconds' => 2, 'services.skysms.pace_seconds' => 2]);
        $slept = [];

        Http::fake([self::HOST => Http::sequence()
            ->push(['message' => 'slow'], 429, ['Retry-After' => '5'])
            ->push(['message' => 'slow'], 429)
            ->push($this->ok(), 200)]);

        $sender = new PacedSender($this->gateway(), function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        $result = $sender->send('09171234567', 'Hello');

        $this->assertTrue($result->isAccepted());
        Http::assertSentCount(3);
        // First wait honours Retry-After (5 > 2); the second is the doubled base (4).
        $this->assertSame([5.0, 4.0], $slept);
    }

    public function test_the_paced_sender_gives_up_after_its_retries(): void
    {
        config(['services.skysms.retry_base_seconds' => 1, 'services.skysms.max_retries' => 2]);
        $slept = [];

        Http::fake([self::HOST => Http::response(['message' => 'slow'], 429)]);

        $sender = new PacedSender($this->gateway(), function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        $result = $sender->send('09171234567', 'Hello');

        $this->assertTrue($result->isRateLimited());
        Http::assertSentCount(3); // the first try plus two retries
        $this->assertSame([1.0, 2.0], $slept);
    }

    public function test_the_paced_sender_spaces_consecutive_sends(): void
    {
        config(['services.skysms.pace_seconds' => 2]);
        $slept = [];

        Http::fake([self::HOST => Http::response($this->ok(), 200)]);

        $sender = new PacedSender($this->gateway(), function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        $sender->send('09171234567', 'One');
        $sender->send('09171234568', 'Two');
        $sender->send('09171234569', 'Three');

        // Nothing before the first; two seconds before each after it, which is
        // 30 a minute.
        $this->assertSame([2.0, 2.0], $slept);
    }

    // ---- headroom for OTPs -----------------------------------------------

    public function test_loops_default_to_twenty_sends_a_minute_leaving_ten_for_codes(): void
    {
        $this->assertSame(20, PacedSender::MAX_PER_MINUTE);

        // The shipped default, read from the file: phpunit.xml pins the pace to
        // zero so the rest of the suite does not wait, which hides it.
        $this->assertMatchesRegularExpression(
            "/'pace_seconds' => \\(float\\) env\\('SKYSMS_PACE_SECONDS', 3\\)/",
            file_get_contents(config_path('services.php')),
        );
    }

    public function test_a_paced_loop_never_exceeds_twenty_sends_in_a_minute(): void
    {
        config(['services.skysms.pace_seconds' => 3, 'services.skysms.retry_base_seconds' => 0]);
        $slept = [];

        Http::fake([self::HOST => Http::response($this->ok(), 200)]);

        $sender = new PacedSender($this->gateway(), function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        // 21 sends make 20 gaps of three seconds: the 21st cannot start until
        // a full minute after the first, so at most 20 land inside any minute.
        for ($i = 1; $i <= 21; $i++) {
            $sender->send('0917'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'Hello');
        }

        $this->assertCount(20, $slept);
        $this->assertSame(60.0, array_sum($slept));
    }

    public function test_a_retry_in_a_loop_is_spaced_like_any_other_send(): void
    {
        // A short Retry-After and a tiny backoff must not let the retry jump
        // the queue: it is a send, and it counts against the twenty.
        config(['services.skysms.pace_seconds' => 3, 'services.skysms.retry_base_seconds' => 0]);
        $slept = [];

        Http::fake([self::HOST => Http::sequence()
            ->push(['message' => 'slow'], 429, ['Retry-After' => '1'])
            ->push($this->ok(), 200)]);

        $sender = new PacedSender($this->gateway(), function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        $this->assertTrue($sender->send('09171234567', 'Hello')->isAccepted());
        $this->assertSame([3.0], $slept);
    }

    public function test_an_otp_that_gets_a_short_429_waits_once_and_retries(): void
    {
        $slept = [];
        $gateway = new SkySmsGateway(function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        Http::fake([self::HOST => Http::sequence()
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '3'])
            ->push($this->ok(), 200)]);

        $result = $gateway->sendOtp('09171234567', 'Your SERBIS login code is 123456.');

        $this->assertTrue($result->isAccepted());
        Http::assertSentCount(2);
        $this->assertSame([3.0], $slept);
    }

    public function test_an_otp_retries_only_once(): void
    {
        $slept = [];
        $gateway = new SkySmsGateway(function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        Http::fake([self::HOST => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '2'])]);

        $result = $gateway->sendOtp('09171234567', 'Hello');

        $this->assertTrue($result->isRateLimited());
        Http::assertSentCount(2);
        $this->assertSame([2.0], $slept);
    }

    public function test_an_otp_with_a_long_or_missing_retry_after_is_not_held_or_retried(): void
    {
        $slept = [];
        $gateway = new SkySmsGateway(function (float $seconds) use (&$slept) {
            $slept[] = $seconds;
        });

        Http::fake([self::HOST => Http::sequence()
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '6'])
            ->push(['message' => 'slow down'], 429)]);

        $this->assertTrue($gateway->sendOtp('09171234567', 'Hello')->isRateLimited());
        $this->assertTrue($gateway->sendOtp('09171234567', 'Hello')->isRateLimited());

        // One request each: 6 is over the five-second ceiling, and no header
        // means we do not know how long.
        Http::assertSentCount(2);
        $this->assertSame([], $slept);
    }

    public function test_an_otp_that_is_not_rate_limited_is_sent_once_as_before(): void
    {
        Http::fake([self::HOST => Http::response(['message' => 'Insufficient credits'], 402)]);

        $gateway = new SkySmsGateway(fn (float $seconds) => $this->fail('nothing to wait for'));

        $this->assertTrue($gateway->sendOtp('09171234567', 'Hello')->isOutOfCredits());
        Http::assertSentCount(1);
    }

    // ---- content policy --------------------------------------------------

    public function test_a_message_with_a_url_or_domain_is_refused_before_any_request(): void
    {
        Http::fake();

        foreach ([
            'Visit https://example.org now',
            'see www.echague.gov',
            'go to bit.ly/abc',
            'email mdrrmo@echague.gov.ph',
            'open serbis.com today',
            'server at 192.168.1.10',
            'Check echague.ph',
        ] as $message) {
            $result = $this->gateway()->sendOne('09171234567', $message);

            $this->assertTrue($result->isRejected(), $message);
            $this->assertSame(SmsResult::REASON_BLOCKED_CONTENT, $result->reason, $message);
        }

        Http::assertNothingSent();
    }

    public function test_ordinary_notices_are_not_mistaken_for_links(): void
    {
        foreach ([
            'Your SERBIS verification code is 123456. It expires in 15 minutes.',
            'SERBIS: your ambulance is scheduled tomorrow at 12:30 PM. Please be ready.',
            'MDRRMO Echague heat advisory: avoid outdoor work 10AM-3PM and drink water often.',
            'Rescue boat 2.5 m long. Call 09171234567.',
            'Pack food.Bring water.',
        ] as $message) {
            $this->assertFalse(SmsMessagePolicy::containsLink($message), $message);
        }
    }

    public function test_look_alike_punctuation_is_replaced_with_ascii(): void
    {
        $this->assertSame(
            'Don\'t "panic" - stay calm...',
            SmsMessagePolicy::toGsmSafe("Don\u{2019}t \u{201C}panic\u{201D} \u{2014} stay calm\u{2026}"),
        );

        $this->assertSame([], SmsMessagePolicy::nonGsmCharacters('Plain text, 100% fine. Ñandu é £'));
        $this->assertSame(["\u{2014}", "\u{1F600}"], SmsMessagePolicy::nonGsmCharacters("a \u{2014} b \u{1F600}"));
    }

    public function test_a_name_is_sanitised_but_written_message_text_keeps_the_hard_block(): void
    {
        $this->assertSame('Tent com Set', SmsMessagePolicy::sanitizeName('Tent.com Set'));
        $this->assertSame('Radio 192 168 0 10', SmsMessagePolicy::sanitizeName('Radio 192.168.0.10'));
        $this->assertSame('www echague gov unit', SmsMessagePolicy::sanitizeName('www.echague.gov unit'));
        $this->assertSame('https boat', SmsMessagePolicy::sanitizeName('https://boat'));
        $this->assertSame('Crutches (pair)', SmsMessagePolicy::sanitizeName('Crutches (pair)'));
        $this->assertSame('Tent 3.5m', SmsMessagePolicy::sanitizeName('Tent 3.5m'));
        $this->assertSame("Boat's ring", SmsMessagePolicy::sanitizeName("Boat\u{2019}s ring"));
        $this->assertSame('item', SmsMessagePolicy::sanitizeName('...'));
        $this->assertSame('item', SmsMessagePolicy::sanitizeName(''));

        // The same text as a whole message is still refused, by the gateway
        // and by the blast: the person typed that, so they are told.
        Http::fake();
        $result = $this->gateway()->sendOne('09171234567', 'Bring the Tent.com Set');
        $this->assertSame(SmsResult::REASON_BLOCKED_CONTENT, $result->reason);
        Http::assertNothingSent();
    }

    // ---- timeouts, auth, server ------------------------------------------

    public function test_a_timeout_is_unknown_not_delivered(): void
    {
        Log::spy();
        Http::fake([self::HOST => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $result = $this->gateway()->sendOne('09171234567', 'Hello');

        $this->assertTrue($result->isUnknown());
        $this->assertFalse($result->isAccepted());
        $this->assertFalse($result->isRejected());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'UNKNOWN'))
            ->once();
    }

    public function test_auth_validation_and_server_errors_are_rejections(): void
    {
        $cases = [[401, SmsResult::REASON_AUTH], [403, SmsResult::REASON_AUTH], [400, SmsResult::REASON_INVALID], [422, SmsResult::REASON_INVALID], [500, SmsResult::REASON_SERVER]];

        // One sequence, not a fresh fake per case: a second Http::fake() adds
        // a stub behind the first, which keeps answering.
        $sequence = Http::sequence();
        foreach ($cases as [$status]) {
            $sequence->push(['message' => 'x'], $status);
        }
        Http::fake([self::HOST => $sequence]);

        foreach ($cases as [$status, $reason]) {
            $result = $this->gateway()->sendOne('09171234567', 'Hello');

            $this->assertTrue($result->isRejected(), (string) $status);
            $this->assertSame($reason, $result->reason, (string) $status);
        }
    }

    public function test_no_key_means_no_request(): void
    {
        config(['services.skysms.api_key' => null]);
        Http::fake();

        $result = $this->gateway()->sendOne('09171234567', 'Hello');

        $this->assertSame(SmsResult::REASON_NOT_CONFIGURED, $result->reason);
        Http::assertNothingSent();
    }

    public function test_a_message_body_is_never_written_to_the_log(): void
    {
        Log::spy();
        Http::fake([self::HOST => Http::response(['message' => 'boom'], 500)]);

        $this->gateway()->sendOne('09171234567', 'Your SERBIS login code is 918273.');

        Log::shouldNotHaveReceived('error', fn ($message, $context = []) => str_contains(json_encode($context), '918273'));
        Log::shouldNotHaveReceived('warning', fn ($message, $context = []) => str_contains(json_encode($context), '918273'));
    }

    // ---- bulk ------------------------------------------------------------

    public function test_bulk_posts_recipients_deduplicated_and_normalised(): void
    {
        Http::fake([self::HOST => Http::response(['success' => true, 'batch_id' => 'b-9'], 200)]);

        $result = $this->gateway()->sendBulk(['09171234567', '+639171234567', '09171234568', '12345'], 'Advisory');

        $this->assertTrue($result->isAccepted());
        $this->assertSame('b-9', $result->queueId);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://skysms.skyio.site/api/v1/sms/send-bulk'
                && $request['recipients'] === [['phone_number' => '+639171234567'], ['phone_number' => '+639171234568']]
                && $request['message'] === 'Advisory';
        });
    }

    public function test_the_first_bulk_reply_is_logged_raw_once(): void
    {
        Log::spy();
        Http::fake([self::HOST => Http::response(['success' => true, 'anything' => 'shape'], 200)]);

        $this->gateway()->sendBulk(['09171234567'], 'One');
        $this->gateway()->sendBulk(['09171234568'], 'Two');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'bulk response, first use') && str_contains($context['body'], 'shape'))
            ->once();
    }

    public function test_a_bulk_send_over_the_limit_is_a_programming_error(): void
    {
        $numbers = array_map(fn ($i) => '0917'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), range(1, 1001));

        $this->expectException(\InvalidArgumentException::class);

        $this->gateway()->sendBulk($numbers, 'Too many');
    }

    public function test_a_bulk_message_with_a_link_is_refused_locally(): void
    {
        Http::fake();

        $result = $this->gateway()->sendBulk(['09171234567'], 'Details at www.example.com');

        $this->assertSame(SmsResult::REASON_BLOCKED_CONTENT, $result->reason);
        Http::assertNothingSent();
    }
}
