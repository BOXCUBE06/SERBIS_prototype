<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/admin/service-requests — staff filing a request for a walk-in,
 * added on the adviser's request. Two shapes of walk-in exist: an existing
 * resident who came to the office instead of using the app, and someone with
 * no account at all. resident_id is nullable for the second case, so the two
 * new columns (walk_in_name, walk_in_contact_number) are what identifies a
 * request that carries neither an account nor valid_id.
 */
class WalkInServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->service = Service::create([
            'service_name' => 'Medical Transport / Ambulance',
            'description' => 'Pick-up and drop-off',
        ]);

        $this->actingAs($this->admin);
    }

    public function test_staff_can_file_a_request_for_someone_with_no_account(): void
    {
        $response = $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Rosario Bautista',
            'walk_in_contact_number' => '09181234567',
            'service_id' => $this->service->service_id,
            'description' => 'Elderly resident, no phone, requesting relief goods pickup.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('walk_in_name', 'Rosario Bautista')
            ->assertJsonPath('resident_id', null)
            ->assertJsonPath('status', 'Pending');

        $this->assertDatabaseHas('tbl_service_request', [
            'walk_in_name' => 'Rosario Bautista',
            'walk_in_contact_number' => '09181234567',
            'resident_id' => null,
        ]);
    }

    public function test_staff_can_file_a_request_for_an_existing_resident(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $response = $this->postJson('/api/admin/service-requests', [
            'resident_id' => $resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Filed at the counter, phone was dead.',
        ]);

        $response->assertStatus(201)->assertJsonPath('resident_id', $resident->getKey());

        $this->assertDatabaseHas('tbl_service_request', [
            'resident_id' => $resident->getKey(),
            'walk_in_name' => null,
        ]);
    }

    public function test_walk_in_fields_are_dropped_when_a_resident_is_also_sent(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria2@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        // A leftover walk_in_name from switching the form's mode must not sit
        // next to a linked account pretending to be a fact about it.
        $this->postJson('/api/admin/service-requests', [
            'resident_id' => $resident->getKey(),
            'walk_in_name' => 'Stale Leftover Name',
            'service_id' => $this->service->service_id,
            'description' => 'Filed at the counter.',
        ])->assertStatus(201)->assertJsonPath('walk_in_name', null);
    }

    public function test_valid_id_is_not_required_for_a_staff_filed_request(): void
    {
        $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'No ID Uploaded',
            'walk_in_contact_number' => '09190000000',
            'service_id' => $this->service->service_id,
            'description' => 'ID checked in person at the counter.',
        ])->assertStatus(201);
    }

    public function test_neither_resident_nor_walk_in_name_is_rejected(): void
    {
        $this->postJson('/api/admin/service-requests', [
            'service_id' => $this->service->service_id,
            'description' => 'Missing requester entirely.',
        ])->assertStatus(422)->assertJsonValidationErrors(['walk_in_name', 'walk_in_contact_number']);
    }

    public function test_a_resident_cannot_call_the_staff_only_route(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria3@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->actingAs($resident)
            ->postJson('/api/admin/service-requests', [
                'walk_in_name' => 'Should Not Work',
                'walk_in_contact_number' => '09190000000',
                'service_id' => $this->service->service_id,
                'description' => 'A resident trying the staff-only route.',
            ])->assertForbidden();
    }
}
