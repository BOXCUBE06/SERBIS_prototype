<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * VehicleDispatch, ambulance side: both routes to Responding (PUT /service-requests and
 * POST /conduction-requests) share one set of checks.
 */
class AmbulanceDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Service $ambulance;

    private Vehicle $unitA;

    private Vehicle $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'role' => 'Admin',
            'email_address' => 'ana@test.local', 'password' => Hash::make('password123'),
        ]));

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Ambulance services.',
        ]);

        $this->unitA = Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);
        $this->unitB = Vehicle::create(['unit_identifier' => 'AMB-02', 'type' => 'Ambulance', 'status' => 'Available']);
    }

    private function request(string $status = 'Pending', ?Vehicle $unit = null): ServiceRequest
    {
        return ServiceRequest::create([
            'walk_in_name' => 'Juan Dela Cruz',
            'walk_in_contact_number' => '09171234567',
            'service_id' => $this->ambulance->service_id,
            'description' => 'Transfer',
            'status' => $status,
            'vehicle_id' => $unit?->vehicle_id,
        ]);
    }

    private function booking(bool $approved, Vehicle $unit): ServiceRequest
    {
        $request = $this->request('Booked', $unit);
        AmbulanceBooking::create([
            'request_id' => $request->request_id,
            'patient_name' => 'Juan Dela Cruz',
            'destination' => 'Echague District Hospital',
            'scheduled_at' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHours(2),
            'approved_at' => $approved ? now() : null,
        ]);

        return $request;
    }

    private function trip(ServiceRequest $request, array $overrides = []): array
    {
        return array_merge([
            'service_request_id' => $request->request_id,
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 3, San Fabian',
            'patient_contact_number' => '09171234567',
            'medical_diagnosis' => 'Dialysis',
            'origin' => 'San Fabian',
            'destination' => 'Echague District Hospital',
            'drivers' => ['Pedro Reyes'],
        ], $overrides);
    }

    public function test_dispatch_without_a_vehicle_is_rejected(): void
    {
        $request = $this->request();

        $this->putJson("/api/service-requests/{$request->request_id}", ['status' => 'Responding'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        $this->assertSame('Pending', $request->fresh()->status);
    }

    public function test_a_booking_without_approved_at_is_rejected_on_both_routes(): void
    {
        $request = $this->booking(approved: false, unit: $this->unitA);

        $this->putJson("/api/service-requests/{$request->request_id}", [
            'status' => 'Responding', 'vehicle_id' => $this->unitA->vehicle_id,
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->postJson('/api/conduction-requests', $this->trip($request))
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('Booked', $request->fresh()->status);
        $this->assertSame(0, ConductionRequest::count());
        $this->assertSame('Available', $this->unitA->fresh()->status);
    }

    public function test_a_unit_with_a_responding_request_cannot_be_dispatched_again_on_either_route(): void
    {
        // The flag says Available; the rule must read the Responding request, not the flag.
        $this->request('Responding', $this->unitA);

        $pending = $this->request();
        $this->putJson("/api/service-requests/{$pending->request_id}", [
            'status' => 'Responding', 'vehicle_id' => $this->unitA->vehicle_id,
        ])->assertStatus(409);
        $this->assertSame('Pending', $pending->fresh()->status);

        $booked = $this->booking(approved: true, unit: $this->unitA);
        $this->postJson('/api/conduction-requests', $this->trip($booked))->assertStatus(409);
        $this->assertSame('Booked', $booked->fresh()->status);
        $this->assertSame(0, ConductionRequest::where('service_request_id', $booked->request_id)->count());
    }

    public function test_conduction_dispatch_marks_the_unit_dispatched_and_uses_the_requests_vehicle(): void
    {
        $booked = $this->booking(approved: true, unit: $this->unitA);

        // A different unit in the input is ignored.
        $this->postJson('/api/conduction-requests', $this->trip($booked, ['vehicle_id' => $this->unitB->vehicle_id]))
            ->assertStatus(201)
            ->assertJsonPath('vehicle_id', $this->unitA->vehicle_id);

        $this->assertSame('Responding', $booked->fresh()->status);
        $this->assertSame($this->unitA->vehicle_id, $booked->fresh()->vehicle_id);
        $this->assertSame('Dispatched', $this->unitA->fresh()->status);
        $this->assertSame('Available', $this->unitB->fresh()->status);
    }

    public function test_resolving_releases_the_unit(): void
    {
        $request = $this->request();

        $this->putJson("/api/service-requests/{$request->request_id}", [
            'status' => 'Responding', 'vehicle_id' => $this->unitA->vehicle_id,
        ])->assertOk();
        $this->assertSame('Dispatched', $this->unitA->fresh()->status);

        $trip = $request->conductionRequests()->firstOrFail();
        $trip->update(['arrived_destination_at' => now()]);
        ConductionRequestPerson::create([
            'conduction_request_id' => $trip->conduction_request_id, 'role' => 'driver', 'name' => 'Pedro Reyes', 'position' => 0,
        ]);

        $this->putJson("/api/service-requests/{$request->request_id}", [
            'status' => 'Resolved', 'vehicle_id' => $this->unitA->vehicle_id,
        ])->assertOk();

        $this->assertSame('Resolved', $request->fresh()->status);
        $this->assertSame('Available', $this->unitA->fresh()->status);
    }
}
