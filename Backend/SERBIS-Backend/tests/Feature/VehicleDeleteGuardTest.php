<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DELETE /api/vehicles/{id} — the permanent version of the Maintenance guard
 * in VehicleMaintenanceGuardTest. tbl_service_request.vehicle_id nulls on
 * delete, so an unguarded delete does not fail loudly: it succeeds and quietly
 * detaches every request resting on the unit.
 */
class VehicleDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Resident $resident;
    private Service $service;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);
    }

    private function requestOn(Carbon $scheduledAt, string $status = 'Booked'): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => $status,
            'vehicle_id' => $this->vehicle->vehicle_id,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    public function test_an_idle_unit_deletes(): void
    {
        $this->deleteJson("/api/vehicles/{$this->vehicle->getKey()}")->assertOk();

        $this->assertNull(Vehicle::find($this->vehicle->getKey()));
    }

    public function test_a_dispatched_unit_cannot_be_deleted(): void
    {
        $this->vehicle->update(['status' => 'Dispatched']);

        $this->deleteJson("/api/vehicles/{$this->vehicle->getKey()}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete this unit: it is currently Dispatched.');

        $this->assertNotNull(Vehicle::find($this->vehicle->getKey()));
    }

    public function test_a_future_booked_request_blocks_the_delete_and_is_named(): void
    {
        $booking = $this->requestOn(Carbon::now('UTC')->addDays(3));

        $response = $this->deleteJson("/api/vehicles/{$this->vehicle->getKey()}")
            ->assertStatus(422);

        $this->assertStringContainsString("#{$booking->request_id}", $response->json('message'));

        $this->assertNotNull(Vehicle::find($this->vehicle->getKey()));
        $this->assertSame($this->vehicle->vehicle_id, $booking->fresh()->vehicle_id);
    }

    public function test_a_future_pending_request_blocks_the_delete_too(): void
    {
        // Wider than the Maintenance guard on purpose: that one keys on
        // 'Booked', this one on the whole non-terminal set.
        $pending = $this->requestOn(Carbon::now('UTC')->addDays(3), 'Pending');

        $response = $this->deleteJson("/api/vehicles/{$this->vehicle->getKey()}")
            ->assertStatus(422);

        $this->assertStringContainsString("#{$pending->request_id}", $response->json('message'));
    }

    public function test_a_request_already_in_the_past_does_not_block_the_delete(): void
    {
        $this->requestOn(Carbon::now('UTC')->subDays(3));

        $this->deleteJson("/api/vehicles/{$this->vehicle->getKey()}")->assertOk();

        $this->assertNull(Vehicle::find($this->vehicle->getKey()));
    }

    public function test_a_cancelled_future_request_does_not_block_the_delete(): void
    {
        $this->requestOn(Carbon::now('UTC')->addDays(3), 'Cancelled');

        $this->deleteJson("/api/vehicles/{$this->vehicle->getKey()}")->assertOk();

        $this->assertNull(Vehicle::find($this->vehicle->getKey()));
    }
}
