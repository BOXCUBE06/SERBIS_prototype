<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} — the second-order guard on top of
 * ALLOWED_TRANSITIONS. Booked -> Responding is legal by the matrix (it has
 * to be: the non-ambulance instant-approval path and the manual "Dispatch"
 * button both use it), but for an ambulance booking specifically that route
 * skipped approve() entirely — no locked availability re-check, approved_at
 * stayed NULL, and the resident never got the approval SMS. This file
 * covers the guard that closes that: an ambulance request may not reach
 * Responding from Booked unless approved_at is already set.
 */
class ServiceRequestAmbulanceApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $ambulance;

    private Service $nonAmbulance;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        // Slugifies to 'ambulance-medical-response' via Service::booted().
        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->nonAmbulance = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal',
        ]);

        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type II',
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);
    }

    private function bookedAmbulanceRequest(array $overrides = []): ServiceRequest
    {
        $request = ServiceRequest::create(array_merge([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ], $overrides));

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0),
        ]);

        return $request;
    }

    public function test_an_unapproved_ambulance_booking_cannot_be_dispatched(): void
    {
        $request = $this->bookedAmbulanceRequest();
        $this->assertNull($request->ambulanceBooking->approved_at);

        $response = $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertStringContainsString('approved', $response->json('errors.status.0'));

        $fresh = $request->fresh();
        $this->assertSame('Booked', $fresh->status);
        $this->assertNull($fresh->vehicle_id);
        $this->assertSame('Available', $this->vehicle->fresh()->status);
    }

    public function test_an_approved_ambulance_booking_dispatches_normally(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $request = $this->bookedAmbulanceRequest();

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $this->assertNotNull($request->fresh()->ambulanceBooking->approved_at);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Responding');

        $fresh = $request->fresh();
        $this->assertSame('Responding', $fresh->status);
        $this->assertSame($this->vehicle->vehicle_id, $fresh->vehicle_id);
        $this->assertSame('Dispatched', $this->vehicle->fresh()->status);
    }

    public function test_a_non_ambulance_booked_request_still_dispatches_with_no_approval_step(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->nonAmbulance->getKey(),
            'description' => 'Fallen tree scheduled for pickup',
            'status' => 'Booked',
        ]);
        // No booking row at all for a non-ambulance request — approved_at
        // has nowhere to live.
        $this->assertNull($request->ambulanceBooking);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
        ])->assertOk()->assertJsonPath('status', 'Responding');

        $this->assertSame('Responding', $request->fresh()->status);
    }

    public function test_the_gate_does_not_apply_to_pending_to_responding(): void
    {
        // Only the Booked -> Responding pair is gated. The instant path
        // (Pending -> Responding, no Booked step at all) has never had an
        // approve() to skip and must be untouched.
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Chest pains, needs transport',
            'status' => 'Pending',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Responding');
    }
}
