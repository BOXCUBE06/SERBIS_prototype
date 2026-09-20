<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The admin CRUD (POST/PUT /api/residents) gained `street_address` alongside
 * the resident's own PATCH /me (MDRRMO feedback, 2026-09-19) — staff filing a
 * request on a resident's behalf need to be able to set it too, not just the
 * resident.
 */
class AdminResidentStreetAddressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_admin_can_set_street_address_on_create(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'street_address' => 'Purok 3',
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'jose@test.local',
            'password' => 'Password123',
            'status' => 'Active',
        ])->assertStatus(201)
            ->assertJsonPath('street_address', 'Purok 3');

        $this->assertSame('Purok 3', Resident::where('email_address', 'jose@test.local')->first()->street_address);
    }

    public function test_street_address_is_optional_on_create(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'jose@test.local',
            'password' => 'Password123',
            'status' => 'Active',
        ])->assertStatus(201);

        $this->assertNull(Resident::where('email_address', 'jose@test.local')->first()->street_address);
    }

    public function test_admin_can_change_street_address_on_update(): void
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'street_address' => 'Purok 3',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->actingAs($this->admin)->putJson("/api/residents/{$resident->resident_id}", [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'barangay_id' => $this->barangay->barangay_id,
            'street_address' => 'Purok 9, near the chapel',
            'status' => 'Active',
        ])->assertOk();

        $this->assertSame(
            'Purok 9, near the chapel',
            $resident->fresh()->street_address,
        );
    }
}
