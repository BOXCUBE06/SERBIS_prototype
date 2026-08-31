<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * plate_no on tbl_vehicles -- added so the Ambulance Trip Record form's own
 * plate field can finally be sourced from the fleet instead of staying
 * free-text forever (C7 of docs/dispatch-audit.md's remediation plan
 * flagged this as a deferred gap, not a permanent one).
 */
class VehiclePlateNoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]));
    }

    public function test_a_vehicle_can_be_created_with_a_plate_no(): void
    {
        $response = $this->postJson('/api/vehicles', [
            'unit_identifier' => 'AMB-01',
            'plate_no' => 'NBA 2021',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $response->assertStatus(201)->assertJsonPath('plate_no', 'NBA 2021');
        $this->assertSame('NBA 2021', Vehicle::first()->plate_no);
    }

    public function test_a_vehicle_can_be_created_with_no_plate_no(): void
    {
        // The common case for an older or mutual-aid unit with nothing on
        // file -- must not become required just because the column exists.
        $response = $this->postJson('/api/vehicles', [
            'unit_identifier' => 'AMB-02',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $response->assertStatus(201)->assertJsonPath('plate_no', null);
    }

    public function test_a_vehicles_plate_no_can_be_updated(): void
    {
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-03', 'type' => 'Ambulance', 'status' => 'Available',
        ]);

        $response = $this->putJson("/api/vehicles/{$vehicle->vehicle_id}", ['plate_no' => 'NBA 3033']);

        $response->assertStatus(200)->assertJsonPath('plate_no', 'NBA 3033');
    }
}
