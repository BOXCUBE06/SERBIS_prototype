<?php

namespace Tests\Feature;

use App\Models\AmbulanceDestination;
use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /ambulance-destinations — the ambulance form's destination dropdown
 * (MDRRMO feedback, 2026-09-19). Read-only; see AmbulanceDestinationSeeder
 * for how the seeded list was chosen and docs/ambulance-destinations-audit.md
 * for the count it was seeded from.
 */
class AmbulanceDestinationTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09171111111',
            'email_address' => 'r1@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    public function test_lists_destinations_in_alphabetical_order(): void
    {
        AmbulanceDestination::insert([
            ['name' => 'Santiago City General Hospital', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Echague District Hospital', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->resident)->getJson('/api/ambulance-destinations')
            ->assertOk()
            ->assertJson(['data' => ['Echague District Hospital', 'Santiago City General Hospital']]);
    }

    public function test_an_empty_table_answers_an_empty_list_not_an_error(): void
    {
        $this->actingAs($this->resident)->getJson('/api/ambulance-destinations')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/ambulance-destinations')->assertStatus(401);
    }
}
