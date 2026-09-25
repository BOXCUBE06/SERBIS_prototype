<?php

namespace Tests\Feature;

use Database\Seeders\EchagueBarangaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangayListTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_list_returns_every_barangay_sorted_by_name_with_its_code(): void
    {
        $this->seed(EchagueBarangaySeeder::class);

        $response = $this->getJson('/api/barangays')->assertOk()->assertJsonCount(64);

        $names = array_column($response->json(), 'barangay_name');
        $sorted = $names;
        sort($sorted);

        $this->assertSame($sorted, $names);
        $this->assertNotNull($response->json('0.psgc_code'));
    }
}
