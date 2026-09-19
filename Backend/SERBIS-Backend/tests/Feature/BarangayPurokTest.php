<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /barangays/{id}/puroks — the type-ahead source for the purok/street
 * field (MDRRMO feedback, 2026-09-19). No seed data: the list is built
 * entirely from `street_address` values residents of that barangay have
 * already entered, so it starts empty and grows on its own.
 */
class BarangayPurokTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangayA;

    private Barangay $barangayB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangayA = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->barangayB = Barangay::create(['barangay_name' => 'San Miguel']);
    }

    private function resident(Barangay $barangay, ?string $street, string $email): Resident
    {
        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'street_address' => $street,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '0917'.rand(1000000, 9999999),
            'email_address' => $email,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    public function test_returns_no_suggestions_for_a_barangay_nobody_has_entered_a_purok_in(): void
    {
        $this->getJson("/api/barangays/{$this->barangayA->barangay_id}/puroks")
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_returns_distinct_puroks_entered_for_that_barangay(): void
    {
        $this->resident($this->barangayA, 'Purok 3', 'a1@test.local');
        $this->resident($this->barangayA, 'Purok 7', 'a2@test.local');
        // A duplicate — must appear once, not twice.
        $this->resident($this->barangayA, 'Purok 3', 'a3@test.local');

        $response = $this->getJson("/api/barangays/{$this->barangayA->barangay_id}/puroks")
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing(['Purok 3', 'Purok 7'], $response);
    }

    public function test_null_and_empty_street_addresses_are_excluded(): void
    {
        $this->resident($this->barangayA, null, 'a1@test.local');
        $this->resident($this->barangayA, '', 'a2@test.local');

        $this->getJson("/api/barangays/{$this->barangayA->barangay_id}/puroks")
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_is_scoped_to_the_requested_barangay(): void
    {
        $this->resident($this->barangayA, 'Purok 3', 'a1@test.local');
        $this->resident($this->barangayB, 'Purok 9', 'b1@test.local');

        $this->getJson("/api/barangays/{$this->barangayA->barangay_id}/puroks")
            ->assertOk()
            ->assertJson(['data' => ['Purok 3']]);
    }

    public function test_is_public_and_needs_no_authentication(): void
    {
        $this->getJson("/api/barangays/{$this->barangayA->barangay_id}/puroks")
            ->assertOk();
    }

    public function test_an_unknown_barangay_is_a_404(): void
    {
        $this->getJson('/api/barangays/999999/puroks')->assertStatus(404);
    }
}
