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
use Tests\Concerns\AssignsResponders;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} — which unit may be attached to which request.
 *
 * The panel's picker has filtered on both rules since 2026-08-30
 * (ServiceRequestQueue.vue's availableVehicles: status Available, and
 * Ambulance on the ambulance board / everything but an Ambulance on the
 * other), but update() validated only exists:tbl_vehicles,vehicle_id. A
 * request made outside the panel could put a Fire Truck on an ambulance
 * booking, or a unit under Maintenance on anything, and the panel then
 * rendered a dispatch nobody could drive.
 *
 * The ambulance side is here; ServiceRequestDispatchTest covers the mirror
 * rule, since its fixture service deliberately does not slugify to
 * `ambulance-medical-response`.
 */
class ServiceRequestVehicleGuardTest extends TestCase
{
    use AssignsResponders, RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $ambulance;

    private Vehicle $unit;

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

        // Slugifies to 'ambulance-medical-response' via Service::booted().
        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->unit = Vehicle::create([
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

    public function test_an_available_ambulance_is_accepted_on_an_ambulance_request(): void
    {
        $request = $this->ambulanceRequest();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->unit->vehicle_id,
        ])->assertOk();

        $this->assertSame($this->unit->vehicle_id, $request->fresh()->vehicle_id);
        $this->assertSame('Dispatched', $this->unit->fresh()->status);
    }

    public function test_a_unit_that_is_not_an_ambulance_is_rejected(): void
    {
        $fireTruck = Vehicle::create([
            'unit_identifier' => 'FIR-01',
            'type' => 'Fire Truck',
            'specification' => 'Pumper',
            'status' => 'Available',
        ]);

        $request = $this->ambulanceRequest();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $fireTruck->vehicle_id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);
        $this->assertSame('Pending', $request->fresh()->status);
        $this->assertSame('Available', $fireTruck->fresh()->status);
    }

    public function test_a_unit_under_maintenance_is_rejected(): void
    {
        $this->unit->update(['status' => 'Maintenance']);

        $request = $this->ambulanceRequest();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->unit->vehicle_id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);
        $this->assertSame('Maintenance', $this->unit->fresh()->status);
    }

    public function test_a_unit_already_dispatched_elsewhere_is_rejected(): void
    {
        // Busy means another Responding request holds the unit (VehicleDispatch).
        $this->ambulanceRequest(['status' => 'Responding', 'vehicle_id' => $this->unit->vehicle_id]);

        $request = $this->ambulanceRequest();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->unit->vehicle_id,
        ])
            ->assertStatus(409);

        $this->assertNull($request->fresh()->vehicle_id);
    }

    public function test_resending_the_unit_already_attached_is_still_accepted(): void
    {
        // The panel sends vehicle_id on every PUT, including ones that only
        // change a note. By then the unit is Dispatched, not Available, so a
        // guard without this exemption would reject the request's own vehicle.
        $request = $this->ambulanceRequest();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->unit->vehicle_id,
        ])->assertOk();

        $this->assertSame('Dispatched', $this->unit->fresh()->status);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->unit->vehicle_id,
            'internal_notes' => 'Crew radioed in',
        ])->assertOk();

        $this->assertSame($this->unit->vehicle_id, $request->fresh()->vehicle_id);
        $this->assertSame('Dispatched', $this->unit->fresh()->status);
    }

    public function test_a_terminal_update_still_carries_its_unit_back(): void
    {
        // Disapprove/Cancel arrive with the current vehicle_id attached and
        // must release it, not fail the Available check on the way out.
        $request = $this->ambulanceRequest(['status' => 'Booked', 'vehicle_id' => $this->unit->vehicle_id]);
        $this->unit->update(['status' => 'Dispatched']);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Disapproved',
            'vehicle_id' => $this->unit->vehicle_id,
            'remarks' => 'No unit free for that window',
        ])->assertOk();

        $this->assertSame('Available', $this->unit->fresh()->status);
    }
}
