<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * C5 of docs/dispatch-audit.md's remediation plan: the instant path
 * (Approve & Dispatch -> Responding -> Resolved) could always reach a
 * terminal status with zero rows in tbl_conduction_requests. This is the
 * bridge — PUT /api/service-requests/{id} now creates a linked stub the
 * moment an ambulance request goes Responding, and refuses Resolved until
 * that trip has at minimum an arrival time and a driver.
 *
 * Odometer readings are NOT part of that set: they are frequently not to
 * hand when a trip is closed out, and requiring them left finished trips
 * sitting at 'Responding'. ConductionRequestControllerTest still covers the
 * ordering rule that applies whenever both readings are entered.
 *
 * Uses the real service name so its code slugifies to
 * `ambulance-medical-response` — ServiceRequestDispatchTest's fixture
 * ("Medical Transport / Ambulance") deliberately does not, so none of this
 * is reachable from that file.
 */
class AmbulanceDispatchBridgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Resident $resident;
    private Service $ambulance;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type II',
            'status' => 'Available',
        ]);
    }

    private function ambulanceRequest(array $overrides = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Chest pains, needs transport',
            'status' => 'Pending',
        ], $overrides));
    }

    public function test_approving_an_ambulance_request_creates_a_linked_trip_record(): void
    {
        $request = $this->ambulanceRequest();

        $this->assertSame(0, ConductionRequest::count());

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $this->assertSame(1, ConductionRequest::count());
        $trip = ConductionRequest::first();
        $this->assertSame($request->getKey(), $trip->service_request_id);
        $this->assertSame($this->vehicle->vehicle_id, $trip->vehicle_id);
    }

    public function test_the_stub_falls_back_to_the_residents_own_name_and_number(): void
    {
        // No patient_name/patient_address/etc — this request predates C3, or
        // was filed by the mobile app, which does not send them either.
        $request = $this->ambulanceRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();
        $this->assertSame('Maria Santos', $trip->patient_name);
        $this->assertSame('09171111111', $trip->patient_contact_number);
        // No structured pickup/destination exists for this request, so these
        // stay the same honest placeholders AmbulanceFormData already writes
        // into `description` for an unfilled field — not blank, not a guess.
        $this->assertSame('Address not specified', $trip->patient_address);
        $this->assertSame('Address not specified', $trip->origin);
        $this->assertSame('destination not specified', $trip->destination);
        $this->assertSame('Not described', $trip->medical_diagnosis);
    }

    public function test_the_stub_prefers_structured_columns_when_present(): void
    {
        $request = $this->ambulanceRequest([
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 2, San Fabian',
            'pickup_location' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Fractured leg',
        ]);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();
        $this->assertSame('Juan Dela Cruz', $trip->patient_name);
        $this->assertSame('Purok 2, San Fabian', $trip->origin);
        $this->assertSame('Echague District Hospital', $trip->destination);
        $this->assertSame('Fractured leg', $trip->medical_diagnosis);
    }

    public function test_a_walk_in_with_no_account_falls_back_to_its_own_name_and_number(): void
    {
        $request = $this->ambulanceRequest([
            'resident_id' => null,
            'walk_in_name' => 'Pedro Ramos',
            'walk_in_contact_number' => '09179876543',
        ]);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();
        $this->assertSame('Pedro Ramos', $trip->patient_name);
        $this->assertSame('09179876543', $trip->patient_contact_number);
    }

    public function test_re_approving_does_not_create_a_second_trip_record(): void
    {
        $other = Vehicle::create([
            'unit_identifier' => 'AMB-02', 'type' => 'Ambulance', 'specification' => 'Type II', 'status' => 'Available',
        ]);
        $request = $this->ambulanceRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        // A second PUT while already Responding — a vehicle swap, say.
        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $other->vehicle_id,
        ])->assertOk();

        $this->assertSame(1, ConductionRequest::count());
    }

    public function test_resolving_is_refused_with_no_trip_record_at_all(): void
    {
        // Reachable only if a row somehow skipped Responding entirely, or
        // predates this bridge — the normal path always creates one.
        $request = $this->ambulanceRequest(['status' => 'Responding', 'vehicle_id' => $this->vehicle->vehicle_id]);

        $response = $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertStringContainsString('a trip record', $response->json('errors.status.0'));
        $this->assertSame('Responding', $request->fresh()->status);
    }

    public function test_resolving_is_refused_until_arrival_and_a_driver_are_recorded(): void
    {
        $request = $this->ambulanceRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();

        $response = $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $message = $response->json('errors.status.0');
        $this->assertStringContainsString('arrival time', $message);
        $this->assertStringContainsString('a driver', $message);
        // The two readings are no longer part of the requirement set, so they
        // must not be named as missing either.
        $this->assertStringNotContainsString('odometer', $message);
        $this->assertSame('Responding', $request->fresh()->status);

        // Filled in one piece at a time, same as a real trip: still refused
        // until both of the two are present.
        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'departed_office_at' => now()->subMinutes(30)->toDateTimeLocalString(),
            'arrived_destination_at' => now()->toDateTimeLocalString(),
        ])->assertOk();

        $response = $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        // Arrival satisfied, so only the driver is outstanding.
        $message = $response->json('errors.status.0');
        $this->assertStringNotContainsString('arrival time', $message);
        $this->assertStringContainsString('a driver', $message);

        \App\Models\ConductionRequestPerson::create([
            'conduction_request_id' => $trip->conduction_request_id,
            'role' => 'driver',
            'name' => 'Rico Santos',
            'position' => 0,
        ]);

        // Never had an odometer reading written, and resolves anyway.
        $this->assertNull($trip->fresh()->odometer_start);
        $this->assertNull($trip->fresh()->odometer_end);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Resolved');

        $this->assertSame('Available', $this->vehicle->fresh()->status);
    }

    public function test_a_trip_with_odometer_readings_still_resolves(): void
    {
        // Removing the requirement must not turn the readings into something
        // that blocks: a trip that does carry both resolves exactly as before.
        $request = $this->ambulanceRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();

        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'departed_office_at' => now()->subMinutes(30)->toDateTimeLocalString(),
            'arrived_destination_at' => now()->toDateTimeLocalString(),
            'odometer_start' => 1000,
            'odometer_end' => 1050,
            'drivers' => ['Rico Santos'],
        ])->assertOk();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Resolved');
    }

    public function test_a_return_reading_below_the_departure_reading_is_still_rejected(): void
    {
        // ConductionRequestController's own ordering rule is untouched by the
        // resolve gate change and still applies whenever both are entered.
        $request = $this->ambulanceRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();

        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'odometer_start' => 10000,
            'odometer_end' => 9000,
        ])->assertStatus(422)->assertJsonValidationErrors(['odometer_end']);
    }

    public function test_a_non_ambulance_request_is_never_gated(): void
    {
        $other = Service::create(['service_name' => 'Road Clearing', 'description' => 'Debris']);
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $other->getKey(),
            'description' => 'Fallen tree',
            'status' => 'Pending',
        ]);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
        ])->assertOk();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
        ])->assertOk()->assertJsonPath('status', 'Resolved');

        $this->assertSame(0, ConductionRequest::count());
    }
}
