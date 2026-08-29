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
 * PUT /api/vehicles/{id} — flipping a unit to Maintenance must not silently
 * orphan a future Booked request already resting on it. No force flag exists
 * to skip this; reassigning those bookings is a person's decision.
 */
class VehicleMaintenanceGuardTest extends TestCase
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

    private function bookedRequest(Carbon $scheduledAt, string $status = 'Booked'): ServiceRequest
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

    public function test_maintenance_is_refused_while_a_future_booking_holds_the_unit(): void
    {
        $booking = $this->bookedRequest(Carbon::now('UTC')->addDays(3));

        $response = $this->putJson("/api/vehicles/{$this->vehicle->getKey()}", [
            'status' => 'Maintenance',
        ])->assertStatus(422);

        $response->assertJsonValidationErrors('status');
        $this->assertStringContainsString(
            "#{$booking->request_id}",
            $response->json('errors.status.0')
        );

        $this->assertSame('Available', $this->vehicle->fresh()->status);
    }

    public function test_maintenance_is_allowed_with_no_future_bookings(): void
    {
        $this->putJson("/api/vehicles/{$this->vehicle->getKey()}", [
            'status' => 'Maintenance',
        ])->assertOk();

        $this->assertSame('Maintenance', $this->vehicle->fresh()->status);
    }

    public function test_maintenance_ignores_a_booking_already_in_the_past(): void
    {
        $this->bookedRequest(Carbon::now('UTC')->subDays(3));

        $this->putJson("/api/vehicles/{$this->vehicle->getKey()}", [
            'status' => 'Maintenance',
        ])->assertOk();

        $this->assertSame('Maintenance', $this->vehicle->fresh()->status);
    }

    public function test_maintenance_ignores_a_cancelled_future_booking(): void
    {
        $this->bookedRequest(Carbon::now('UTC')->addDays(3), 'Cancelled');

        $this->putJson("/api/vehicles/{$this->vehicle->getKey()}", [
            'status' => 'Maintenance',
        ])->assertOk();

        $this->assertSame('Maintenance', $this->vehicle->fresh()->status);
    }
}
