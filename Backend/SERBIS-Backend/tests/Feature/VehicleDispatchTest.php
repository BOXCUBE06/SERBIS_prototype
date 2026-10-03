<?php

namespace Tests\Feature;

use App\Models\ConductionRequest;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\AssignsResponders;
use Tests\TestCase;

/**
 * VehicleDispatch's busy check: a unit is busy while another Responding
 * request holds it, or while it is out on a trip with no request behind it.
 * The vehicle status flag plays no part.
 */
class VehicleDispatchTest extends TestCase
{
    use AssignsResponders, RefreshDatabase;

    private Service $ambulance;

    private Service $road;

    private Vehicle $ambulanceUnit;

    private Vehicle $rescueUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'role' => 'Admin',
            'email_address' => 'ana@test.local', 'password' => Hash::make('password123'),
        ]));

        $this->ambulance = Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'Ambulance.']);
        $this->road = Service::create(['service_name' => 'Road Clearing', 'description' => 'Debris removal.']);

        $this->ambulanceUnit = Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);
        $this->rescueUnit = Vehicle::create(['unit_identifier' => 'RES-01', 'type' => 'Rescue Vehicle', 'status' => 'Available']);
    }

    private function request(Service $service, string $status = 'Pending', ?Vehicle $unit = null): ServiceRequest
    {
        return ServiceRequest::create([
            'walk_in_name' => 'Juan Dela Cruz',
            'walk_in_contact_number' => '09171234567',
            'service_id' => $service->service_id,
            'description' => 'Test request',
            'status' => $status,
            'vehicle_id' => $unit?->vehicle_id,
        ]);
    }

    private function trip(array $overrides = []): array
    {
        return array_merge([
            'patient_name' => 'Walk-up Patient',
            'patient_address' => 'Purok 4, San Fabian',
            'patient_contact_number' => '09173333333',
            'medical_diagnosis' => 'Fall injury',
            'origin' => 'Purok 4, San Fabian',
            'destination' => 'Echague District Hospital',
            'vehicle_id' => $this->ambulanceUnit->vehicle_id,
            'drivers' => ['Rico Santos'],
        ], $overrides);
    }

    private function respond(ServiceRequest $request, ?Vehicle $unit)
    {
        return $this->putJson("/api/service-requests/{$request->request_id}", array_filter([
            'status' => 'Responding', 'vehicle_id' => $unit?->vehicle_id,
        ]));
    }

    public function test_non_ambulance_dispatch_on_a_unit_held_by_a_responding_request_is_rejected(): void
    {
        $holder = $this->request($this->road, 'Responding', $this->rescueUnit);
        $request = $this->request($this->road);
        $this->assignResponder($request);

        // The flag still says Available; the Responding request is what makes it busy.
        $this->respond($request, $this->rescueUnit)
            ->assertStatus(409)
            ->assertJsonPath('message', "RES-01 is already responding to request #{$holder->request_id}.");

        $this->assertSame('Pending', $request->fresh()->status);
    }

    public function test_dispatch_on_a_unit_with_an_open_unlinked_trip_is_rejected(): void
    {
        $trip = ConductionRequest::create([...$this->trip(), 'departed_office_at' => now()->subHour()]);
        $request = $this->request($this->ambulance);

        $this->respond($request, $this->ambulanceUnit)
            ->assertStatus(409)
            ->assertJsonPath('message', "AMB-01 is already out on trip #{$trip->conduction_request_id}.");
    }

    public function test_an_unlinked_trip_on_a_unit_with_a_responding_request_is_rejected(): void
    {
        $holder = $this->request($this->ambulance, 'Responding', $this->ambulanceUnit);

        $this->postJson('/api/conduction-requests', $this->trip())
            ->assertStatus(409)
            ->assertJsonPath('message', "AMB-01 is already responding to request #{$holder->request_id}.");

        $this->assertSame(0, ConductionRequest::count());
    }

    public function test_an_unlinked_trip_already_returned_does_not_block(): void
    {
        ConductionRequest::create([
            ...$this->trip(),
            'departed_office_at' => now()->subHours(3),
            'returned_office_at' => now()->subHour(),
        ]);

        $this->respond($this->request($this->ambulance), $this->ambulanceUnit)->assertOk();
    }

    public function test_a_linked_trip_with_no_return_time_does_not_block_once_its_request_is_resolved(): void
    {
        $done = $this->request($this->ambulance, 'Resolved', $this->ambulanceUnit);
        ConductionRequest::create([
            ...$this->trip(), 'service_request_id' => $done->request_id, 'departed_office_at' => now()->subHours(2),
        ]);

        $this->respond($this->request($this->ambulance), $this->ambulanceUnit)->assertOk();
    }

    private function depart(int $tripId)
    {
        return $this->patchJson("/api/conduction-requests/{$tripId}/trip-log", [
            'departed_office_at' => now()->subMinutes(10)->toIso8601String(),
        ]);
    }

    public function test_the_second_of_two_filed_unlinked_trips_on_one_unit_cannot_depart(): void
    {
        $first = $this->postJson('/api/conduction-requests', $this->trip())->assertCreated()->json('conduction_request_id');
        $second = $this->postJson('/api/conduction-requests', $this->trip())->assertCreated()->json('conduction_request_id');

        $this->depart($first)->assertOk();
        $this->depart($second)
            ->assertStatus(409)
            ->assertJsonPath('message', "AMB-01 is already out on trip #{$first}.");

        $this->assertNull(ConductionRequest::find($second)->departed_office_at);
    }

    public function test_an_unlinked_trip_cannot_depart_once_its_unit_was_dispatched_to_a_request(): void
    {
        $trip = $this->postJson('/api/conduction-requests', $this->trip())->assertCreated()->json('conduction_request_id');

        // Filed but not departed, so the dispatch itself goes through.
        $request = $this->request($this->ambulance);
        $this->respond($request, $this->ambulanceUnit)->assertOk();

        $this->depart($trip)
            ->assertStatus(409)
            ->assertJsonPath('message', "AMB-01 is already responding to request #{$request->request_id}.");
    }

    public function test_an_unlinked_trip_departs_when_its_unit_is_free(): void
    {
        $trip = $this->postJson('/api/conduction-requests', $this->trip())->assertCreated()->json('conduction_request_id');

        $this->depart($trip)->assertOk();

        $this->assertNotNull(ConductionRequest::find($trip)->departed_office_at);
    }

    public function test_non_ambulance_dispatch_without_a_vehicle_is_still_allowed(): void
    {
        $request = $this->request($this->road);
        $this->assignResponder($request);

        $this->respond($request, null)->assertOk();

        $this->assertSame('Responding', $request->fresh()->status);
        $this->assertNull($request->fresh()->vehicle_id);
    }
}
