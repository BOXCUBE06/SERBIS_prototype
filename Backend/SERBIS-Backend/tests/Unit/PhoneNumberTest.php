<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function numbers(): array
    {
        return [
            'national' => ['09171234567', '+639171234567'],
            'no plus' => ['639171234567', '+639171234567'],
            'canonical' => ['+639171234567', '+639171234567'],
            'ten digits' => ['9171234567', '+639171234567'],
            'spaces and dashes' => ['0917 123-4567', '+639171234567'],
            'empty' => ['', ''],
            'letters only' => ['abc', ''],
            'too short' => ['091712345', ''],
            'too long' => ['091712345678', ''],
            'landline' => ['0287654321', ''],
            'wrong prefix' => ['08171234567', ''],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_display_turns_canonical_into_national(): void
    {
        $this->assertSame('09171234567', PhoneNumber::display('+639171234567'));
    }

    public function test_display_leaves_anything_else_alone(): void
    {
        $this->assertSame('09171234567', PhoneNumber::display('09171234567'));
        $this->assertSame('n/a', PhoneNumber::display('n/a'));
    }

    #[DataProvider('numbers')]
    public function test_regex_accepts_only_the_three_entry_shapes(string $input, string $expected): void
    {
        $entryShape = in_array($input, ['09171234567', '639171234567', '+639171234567'], true);

        $this->assertSame($entryShape, preg_match(PhoneNumber::REGEX, $input) === 1);
    }
}
