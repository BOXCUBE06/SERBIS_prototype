<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} — the admin dispatch path.
 *
 * Two defects motivated this file, and both presented as working software:
 *
 *   1. `vehicle_id` was missing from update()'s validation rules, so the panel
 *      sent it on every dispatch and validate() dropped it. The request stayed
 *      unattached and the detail panel rendered "Vehicle Unknown".
 *   2. Nothing ever returned a unit to the fleet. The panel moved a vehicle to
 *      Dispatched with a second request and no code path moved it back, so the
 *      picker — which lists only Available units — emptied one dispatch at a
 *      time until no request could be dispatched at all.
 *
 * Both are asserted against tbl_vehicles, not against the response body: the
 * response would have looked correct for defect 2 the entire time.
 */
class ServiceRequestDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Resident $resident;
    private Service $service;
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

        $this->service = Service::create([
            'service_name' => 'Medical Transport / Ambulance',
            'description' => 'Pick-up and drop-off',
        ]);

        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type II',
            'status' => 'Available',
        ]);
    }

    private function pendingRequest(): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Chest pains, needs transport',
            'status' => 'Pending',
        ]);
    }

    public function test_dispatching_attaches_the_vehicle_to_the_request(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", [
                'status' => 'Responding',
                'vehicle_id' => $this->vehicle->vehicle_id,
                'remarks' => 'Unit en route',
            ])
            ->assertOk();

        $request->refresh();

        // The assertion the old code failed: vehicle_id was silently dropped.
        $this->assertSame($this->vehicle->vehicle_id, $request->vehicle_id);
        $this->assertSame('Responding', $request->status);
        $this->assertSame('Dispatched', $this->vehicle->fresh()->status);
    }

    public function test_resolving_returns_the_vehicle_to_the_fleet(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", [
                'status' => 'Responding',
                'vehicle_id' => $this->vehicle->vehicle_id,
            ])
            ->assertOk();

        $this->assertSame('Dispatched', $this->vehicle->fresh()->status);

        // The panel sends the attached vehicle_id on every PUT, including the
        // one that closes the request. That must release the unit, not re-claim it.
        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", [
                'status' => 'Resolved',
                'vehicle_id' => $this->vehicle->vehicle_id,
            ])
            ->assertOk();

        $this->assertSame('Available', $this->vehicle->fresh()->status);
    }

    public function test_disapproving_releases_the_unit_too(): void
    {
        // store()'s immediate-claim path dispatches a vehicle while the
        // request is still Pending (see syncFleet()'s own docblock) — this
        // is that same shape, reached directly rather than through
        // Responding: ServiceRequestController::ALLOWED_TRANSITIONS allows
        // Disapproved only from Pending or Booked, never from Responding.
        $request = $this->pendingRequest();
        $request->update(['vehicle_id' => $this->vehicle->vehicle_id]);
        $this->vehicle->update(['status' => 'Dispatched']);

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", [
                'status' => 'Disapproved',
                'vehicle_id' => $this->vehicle->vehicle_id,
                'remarks' => 'Outside MDRRMO scope',
            ])->assertOk();

        $this->assertSame('Available', $this->vehicle->fresh()->status);
    }

    public function test_a_vehicle_can_be_dispatched_again_after_a_request_closes(): void
    {
        // The whole point of the release: with one unit in the fleet, a second
        // request must still be dispatchable once the first is closed. Before
        // the fix this left the fleet permanently empty.
        $first = $this->pendingRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$first->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$first->getKey()}", [
            'status' => 'Resolved',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $second = $this->pendingRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$second->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $second->refresh();

        $this->assertSame($this->vehicle->vehicle_id, $second->vehicle_id);
        $this->assertSame('Dispatched', $this->vehicle->fresh()->status);
    }

    public function test_swapping_the_vehicle_releases_the_one_it_replaced(): void
    {
        $other = Vehicle::create([
            'unit_identifier' => 'AMB-02',
            'type' => 'Ambulance',
            'specification' => 'Type II',
            'status' => 'Available',
        ]);

        $request = $this->pendingRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $other->vehicle_id,
        ])->assertOk();

        $this->assertSame('Available', $this->vehicle->fresh()->status);
        $this->assertSame('Dispatched', $other->fresh()->status);
        $this->assertSame($other->vehicle_id, $request->fresh()->vehicle_id);
    }

    public function test_a_status_outside_the_vocabulary_is_rejected(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Compelted'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('Pending', $request->fresh()->status);
    }

    public function test_a_vehicle_under_maintenance_is_not_pressed_into_service(): void
    {
        $this->vehicle->update(['status' => 'Maintenance']);

        $request = $this->pendingRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $this->assertSame('Maintenance', $this->vehicle->fresh()->status);
    }

    public function test_claiming_a_unit_another_request_already_dispatched_is_rejected(): void
    {
        // Two admins racing for the same unit: the first request's dispatch
        // already flipped it to Dispatched. A second request trying to claim
        // that same vehicle_id must not silently succeed with an unattached
        // unit — it must fail loudly and leave the loser's vehicle_id unset.
        $winner = $this->pendingRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$winner->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $loser = $this->pendingRequest();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$loser->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($loser->fresh()->vehicle_id);
        $this->assertSame('Pending', $loser->fresh()->status);
        $this->assertSame('Dispatched', $this->vehicle->fresh()->status);
        $this->assertSame($this->vehicle->vehicle_id, $winner->fresh()->vehicle_id);
    }
}
