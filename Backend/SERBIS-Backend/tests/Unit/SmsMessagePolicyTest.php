<?php

namespace Tests\Unit;

use App\Services\Sms\SmsMessagePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsMessagePolicyTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function messages(): array
    {
        return [
            'plain advisory' => ['Typhoon warning for Echague. Stay indoors.', false],
            'no space after full stop' => ['Stay safe.Listen to radio', false],
            'https link' => ['See https://example.org/x', true],
            'www' => ['Visit www.mdrrmo', true],
            'domain' => ['Open serbis.ph now', true],
            'shortener' => ['Go to bit.ly/abc', true],
            'ip address' => ['Server 192.168.0.1 down', true],
            'upper case domain' => ['SERBIS.COM', true],
        ];
    }

    #[DataProvider('messages')]
    public function test_contains_link(string $message, bool $expected): void
    {
        $this->assertSame($expected, SmsMessagePolicy::containsLink($message));
    }

    public function test_plain_text_has_no_non_gsm_characters(): void
    {
        $this->assertSame([], SmsMessagePolicy::nonGsmCharacters('Flood alert: 3 barangays {affected}.'));
    }

    public function test_non_gsm_characters_are_listed_once(): void
    {
        $this->assertSame(['😀', '“'], SmsMessagePolicy::nonGsmCharacters('😀 “hi 😀'));
    }

    public function test_gsm_safe_swaps_look_alikes_for_ascii(): void
    {
        $in = "\u{201C}hi\u{201D} \u{2013} it\u{2019}s\u{00A0}ok\u{2026}";

        $this->assertSame('"hi" - it\'s ok...', SmsMessagePolicy::toGsmSafe($in));
    }

    public function test_gsm_safe_leaves_emoji_for_the_caller_to_see(): void
    {
        $this->assertSame('ok 😀', SmsMessagePolicy::toGsmSafe('ok 😀'));
    }
}
