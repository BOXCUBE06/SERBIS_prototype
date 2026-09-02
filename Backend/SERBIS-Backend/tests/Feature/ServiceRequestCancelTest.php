<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /api/service-requests/{id}/cancel — extended to let a resident
 * withdraw a Booked request, not just a Pending one. The three new refusals
 * (cutoff, dispatched trip) sit alongside the existing ownership and status
 * checks, which stay exactly as they were for a Pending request.
 */
class ServiceRequestCancelTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;
    private Resident $otherResident;
    private Service $service;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => bcrypt('Password123'),
            'status' => 'Active',
        ]);

        $this->otherResident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'juan@test.local',
            'password' => bcrypt('Password123'),
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
    }

    private function bookedRequest(?Carbon $scheduledAt = null, ?Vehicle $vehicle = null): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
            'vehicle_id' => $vehicle?->vehicle_id,
            'scheduled_at' => $scheduledAt ?? Carbon::now('UTC')->addDays(2),
        ]);
    }

    public function test_owner_cancels_a_booked_request(): void
    {
        $request = $this->bookedRequest();

        $this->actingAs($this->resident)
            ->patchJson("/api/service-requests/{$request->getKey()}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'Cancelled');

        $this->assertSame('Cancelled', $request->fresh()->status);
    }

    public function test_a_non_owner_gets_404(): void
    {
        $request = $this->bookedRequest();

        $this->actingAs($this->otherResident)
            ->patchJson("/api/service-requests/{$request->getKey()}/cancel")
            ->assertStatus(404);

        $this->assertSame('Booked', $request->fresh()->status);
    }

    public function test_inside_the_cutoff_is_rejected(): void
    {
        // 90 minutes out — inside the 2-hour cutoff.
        $request = $this->bookedRequest(Carbon::now('UTC')->addMinutes(90));

        $this->actingAs($this->resident)
            ->patchJson("/api/service-requests/{$request->getKey()}/cancel")
            ->assertStatus(422);

        $this->assertSame('Booked', $request->fresh()->status);
    }

    public function test_a_past_due_booking_cancels_normally(): void
    {
        // A booking whose scheduled_at has already passed without ever being
        // dispatched — e.g. a resident who never got the crew's call. gte()
        // against the cutoff stays true forever once the window is behind it,
        // so this used to return the same "too close to cancel" refusal as a
        // booking still approaching its slot.
        $request = $this->bookedRequest(Carbon::now('UTC')->subDays(5));

        $this->actingAs($this->resident)
            ->patchJson("/api/service-requests/{$request->getKey()}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'Cancelled');

        $this->assertSame('Cancelled', $request->fresh()->status);
    }

    public function test_after_dispatch_is_rejected(): void
    {
        $request = $this->bookedRequest(Carbon::now('UTC')->addDays(2), $this->vehicle);

        ConductionRequest::create([
            'service_request_id' => $request->request_id,
            'vehicle_id' => $this->vehicle->vehicle_id,
            'patient_name' => 'Maria Santos',
            'patient_address' => 'Barangay San Fabian, Echague, Isabela',
            'patient_contact_number' => '09171111111',
            'medical_diagnosis' => 'Hypertensive emergency',
            'origin' => 'MDRRMO Office, Echague',
            'destination' => 'Echague District Hospital',
            'departed_office_at' => Carbon::now('UTC')->subMinutes(5),
        ]);

        $this->actingAs($this->resident)
            ->patchJson("/api/service-requests/{$request->getKey()}/cancel")
            ->assertStatus(422);

        $this->assertSame('Booked', $request->fresh()->status);
    }

    public function test_the_unit_is_released(): void
    {
        $this->vehicle->update(['status' => 'Dispatched']);
        $request = $this->bookedRequest(Carbon::now('UTC')->addDays(2), $this->vehicle);

        $this->actingAs($this->resident)
            ->patchJson("/api/service-requests/{$request->getKey()}/cancel")
            ->assertOk();

        $this->assertSame('Available', $this->vehicle->fresh()->status);
    }
}
