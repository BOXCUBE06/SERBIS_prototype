<?php

namespace Tests\Unit;

use App\Models\Recipient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecipientStatusMappingTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function vendorStatuses(): array
    {
        return [
            'queued' => ['queued', Recipient::QUEUED],
            'pending' => ['pending', Recipient::PENDING],
            'sent' => ['sent', Recipient::SENT],
            'failed' => ['failed', Recipient::FAILED],
            'upper case' => ['SENT', Recipient::SENT],
            'padded' => ['  Failed ', Recipient::FAILED],
            // Never read as delivered: an unknown word is kept apart.
            'delivered is not known' => ['delivered', Recipient::OTHER],
            'empty' => ['', Recipient::OTHER],
            'null' => [null, Recipient::OTHER],
            'number' => [1, Recipient::OTHER],
            'array' => [['sent'], Recipient::OTHER],
        ];
    }

    #[DataProvider('vendorStatuses')]
    public function test_from_vendor(mixed $status, string $expected): void
    {
        $this->assertSame($expected, Recipient::fromVendor($status));
    }
}
