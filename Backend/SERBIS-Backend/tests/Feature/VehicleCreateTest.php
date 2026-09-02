<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/vehicles. Its only coverage used to live in VehiclePlateNoTest,
 * which went away with the plate_no column it was written for -- taking the
 * create path's happy case with it. Everything else on this controller is
 * already covered elsewhere: PUT by VehicleMaintenanceGuardTest, DELETE by
 * VehicleDeleteGuardTest, GET by ListPaginationTest.
 *
 * The plate assertions are deliberately inverted rather than deleted: a
 * plate_no sent by a stale client must be dropped, not persisted, or the
 * column comes back through mass assignment the first time anyone re-adds
 * it to $fillable.
 */
class VehicleCreateTest extends TestCase
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

    public function test_a_vehicle_can_be_created(): void
    {
        $response = $this->postJson('/api/vehicles', [
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'TYPE I',
            'status' => 'Available',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('unit_identifier', 'AMB-01')
            ->assertJsonPath('type', 'Ambulance')
            ->assertJsonPath('specification', 'TYPE I')
            ->assertJsonPath('status', 'Available');

        $this->assertSame('AMB-01', Vehicle::first()->unit_identifier);
    }

    public function test_a_created_vehicle_carries_no_plate_no(): void
    {
        $this->postJson('/api/vehicles', [
            'unit_identifier' => 'AMB-02',
            'type' => 'Ambulance',
            'status' => 'Available',
        ])->assertStatus(201)->assertJsonMissingPath('plate_no');
    }

    public function test_a_plate_no_sent_by_a_stale_client_is_ignored(): void
    {
        $this->postJson('/api/vehicles', [
            'unit_identifier' => 'AMB-03',
            'plate_no' => 'NBA 2021',
            'type' => 'Ambulance',
            'status' => 'Available',
        ])->assertStatus(201)->assertJsonMissingPath('plate_no');

        $this->assertArrayNotHasKey('plate_no', Vehicle::first()->getAttributes());
    }

    public function test_a_duplicate_unit_identifier_is_rejected(): void
    {
        Vehicle::create([
            'unit_identifier' => 'AMB-04', 'type' => 'Ambulance', 'status' => 'Available',
        ]);

        $this->postJson('/api/vehicles', [
            'unit_identifier' => 'AMB-04',
            'type' => 'Ambulance',
            'status' => 'Available',
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_identifier']);
    }
}
