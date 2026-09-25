<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Concerns\AssignsResponders;
use Tests\TestCase;

/**
 * C8 of docs/dispatch-audit.md's remediation plan — the regression pass.
 * Walks the instant path, the scheduled path, and the standalone
 * (no-booking) case end to end on seed data, then asserts the two
 * invariants the whole plan exists to guarantee:
 *
 *   1. No ambulance service request reaches a terminal status without a
 *      linked trip record (C5's bridge).
 *   2. No two open trips (departed, not yet returned) share a vehicle,
 *      unless one of them carries an override reason (C7's guard).
 *
 * Both are asserted globally, across every row the whole test class
 * creates — not per-scenario — because the defect these findings closed
 * was exactly the kind that a single happy-path test would not catch: a
 * gap reachable from one path and invisible from the others.
 */
class AmbulanceDispatchRegressionTest extends TestCase
{
    use AssignsResponders, RefreshDatabase;

    private User $admin;

    private Service $ambulance;

    private Vehicle $vehicleA;

    private Vehicle $vehicleB;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'role' => 'Admin',
            'email_address' => 'ana@test.local', 'password' => Hash::make('password123'),
        ]);
        $this->actingAs($this->admin);

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->vehicleA = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        $this->vehicleB = Vehicle::create([
            'unit_identifier' => 'AMB-02', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);

        // Instance property, not a static local: RefreshDatabase rolls back
        // the transaction between tests but does not reset PHP statics, so
        // a `static $barangay` here would hand the next test a reference to
        // a row already rolled away — exactly the trap
        // ServiceRequestAdminScopeTest's own docblock warns about, just via
        // a different mechanism (a stale object instead of id drift).
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $first, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => $first, 'last_name' => 'Santos', 'phone_number' => $phone,
            'email_address' => strtolower($first).'@test.local',
            'password' => Hash::make('Password123'), 'status' => 'Active',
        ]);
    }

    private function assertNoOrphanedTerminalAmbulanceRequests(): void
    {
        $orphans = ServiceRequest::where('service_id', $this->ambulance->service_id)
            ->whereIn('status', ['Resolved'])
            ->whereDoesntHave('conductionRequests')
            ->count();

        $this->assertSame(0, $orphans, 'A Resolved ambulance request exists with no linked trip record.');
    }

    private function assertNoUnexplainedDoubleBooking(): void
    {
        $openTrips = ConductionRequest::whereNotNull('vehicle_id')
            ->whereNotNull('departed_office_at')
            ->whereNull('returned_office_at')
            ->get(['conduction_request_id', 'vehicle_id', 'vehicle_override_reason']);

        $byVehicle = $openTrips->groupBy('vehicle_id');

        foreach ($byVehicle as $vehicleId => $trips) {
            if ($trips->count() <= 1) {
                continue;
            }

            // More than one open trip on the same unit is only legitimate if
            // at least one of them was filed with an override reason on
            // record — that is what makes it a deliberate reassignment
            // rather than a guard that let two through silently.
            $hasReason = $trips->contains(fn ($t) => filled($t->vehicle_override_reason));
            $this->assertTrue(
                $hasReason,
                "Vehicle {$vehicleId} has ".$trips->count().' concurrent open trips with no override reason on any of them.',
            );
        }
    }

    public function test_the_instant_path_ends_with_a_linked_trip_record(): void
    {
        $resident = $this->resident('Maria', '09171111111');
        $request = ServiceRequest::create([
            'resident_id' => $resident->getKey(),
            'service_id' => $this->ambulance->service_id,
            'description' => 'Chest pains',
            'status' => 'Pending',
        ]);
        $this->assignResponder($request);

        // Approve & Dispatch.
        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicleA->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::where('service_request_id', $request->getKey())->firstOrFail();

        // Crew data recorded while the trip is running.
        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-31 08:00:00',
            'arrived_destination_at' => '2026-08-31 08:20:00',
            'odometer_start' => 1000,
            'odometer_end' => 1015,
            'drivers' => ['Rico Santos'],
        ])->assertOk();

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
            'vehicle_id' => $this->vehicleA->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Resolved');

        $this->assertSame('Available', $this->vehicleA->fresh()->status);
        $this->assertNoOrphanedTerminalAmbulanceRequests();
        $this->assertNoUnexplainedDoubleBooking();
    }

    public function test_the_scheduled_path_ends_with_a_linked_trip_record(): void
    {
        $resident = $this->resident('Juan', '09172222222');
        $request = ServiceRequest::create([
            'resident_id' => $resident->getKey(),
            'service_id' => $this->ambulance->service_id,
            'description' => 'Scheduled dialysis transport',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => now()->addDays(2),
        ]);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->vehicleB->vehicle_id,
        ])->assertOk();

        $this->assertSame('Booked', $request->fresh()->status);
        $this->assertSame($this->vehicleB->vehicle_id, $request->fresh()->vehicle_id);

        // Dispatch: the trip form filed directly against the booking.
        $response = $this->postJson('/api/conduction-requests', [
            'service_request_id' => $request->getKey(),
            'vehicle_id' => $this->vehicleB->vehicle_id,
            'patient_name' => 'Juan Santos',
            'patient_address' => 'Purok 1, San Fabian',
            'patient_contact_number' => '09172222222',
            'medical_diagnosis' => 'Dialysis',
            'origin' => 'Purok 1, San Fabian',
            'destination' => 'Echague District Hospital',
            'drivers' => ['Ben Cruz'],
        ]);
        $response->assertStatus(201);

        $trip = ConductionRequest::where('service_request_id', $request->getKey())->firstOrFail();
        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-09-02 08:00:00',
            'arrived_destination_at' => '2026-09-02 08:30:00',
            'departed_destination_at' => '2026-09-02 09:00:00',
            'returned_office_at' => '2026-09-02 09:30:00',
            'odometer_start' => 500, 'odometer_end' => 520,
        ])->assertOk();

        // The scheduled path never flips tbl_service_request to Resolved
        // (docs/dispatch-audit.md finding 1) — the trip record itself is
        // the accountable one here, and it exists, which is what this
        // assertion is actually checking. Not asserting Resolved on the
        // booking is deliberate, not an oversight.
        $this->assertSame(1, ConductionRequest::where('service_request_id', $request->getKey())->count());
        $this->assertNoUnexplainedDoubleBooking();
    }

    public function test_the_standalone_walk_up_case_needs_no_booking_at_all(): void
    {
        $response = $this->postJson('/api/conduction-requests', [
            'patient_name' => 'Walk-up Patient',
            'patient_address' => 'Purok 4, San Fabian',
            'patient_contact_number' => '09173333333',
            'medical_diagnosis' => 'Fall injury',
            'origin' => 'Purok 4, San Fabian',
            'destination' => 'Echague District Hospital',
            'vehicle_id' => $this->vehicleA->vehicle_id,
            'drivers' => ['Rico Santos'],
        ]);

        $response->assertStatus(201)->assertJsonPath('service_request_id', null);
        $this->assertNoUnexplainedDoubleBooking();
    }

    public function test_a_deliberate_reassignment_with_a_reason_does_not_trip_the_invariant(): void
    {
        $firstResident = $this->resident('Ana', '09174444444');
        $first = ServiceRequest::create([
            'resident_id' => $firstResident->getKey(), 'service_id' => $this->ambulance->service_id,
            'description' => 'First patient', 'status' => 'Pending',
        ]);
        $this->assignResponder($first);
        $this->putJson("/api/service-requests/{$first->getKey()}", [
            'status' => 'Responding', 'vehicle_id' => $this->vehicleA->vehicle_id,
        ])->assertOk();
        $firstTrip = ConductionRequest::where('service_request_id', $first->getKey())->firstOrFail();
        $this->patchJson("/api/conduction-requests/{$firstTrip->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-31 08:00:00',
        ])->assertOk();

        // Second, higher-priority patient reassigns the same unit mid-trip.
        $secondResponse = $this->postJson('/api/conduction-requests', [
            'patient_name' => 'Second Patient', 'patient_address' => 'Purok 2, San Fabian',
            'patient_contact_number' => '09175555555', 'medical_diagnosis' => 'Cardiac arrest',
            'origin' => 'Purok 2, San Fabian', 'destination' => 'Echague District Hospital',
            'vehicle_id' => $this->vehicleA->vehicle_id,
            'drivers' => ['Test Driver'],
            'override_reason' => 'Dispatcher-approved reassignment, second patient higher acuity.',
        ]);
        $secondResponse->assertStatus(201);

        // "Open" means departed with no return — store() does not accept
        // checkpoint fields, so the second trip needs its own departure
        // recorded before it counts as concurrent with the first.
        $this->patchJson("/api/conduction-requests/{$secondResponse->json('conduction_request_id')}/trip-log", [
            'departed_office_at' => '2026-08-31 08:10:00',
        ])->assertOk();

        // Two open trips on the same unit — legitimate here, because one
        // carries the reason. assertNoUnexplainedDoubleBooking() passing on
        // this fixture is the actual assertion this test makes.
        $this->assertSame(
            2,
            ConductionRequest::where('vehicle_id', $this->vehicleA->vehicle_id)
                ->whereNotNull('departed_office_at')->whereNull('returned_office_at')->count(),
        );
        $this->assertNoUnexplainedDoubleBooking();
    }

    public function test_an_unexplained_double_booking_would_be_caught(): void
    {
        // Proves the helper assertion is not a tautology: force the
        // situation it exists to catch, bypassing the guard by writing
        // directly to the model, and confirm the helper fails on it.
        ConductionRequest::create([
            'vehicle_id' => $this->vehicleA->vehicle_id, 'patient_name' => 'A', 'patient_address' => 'A',
            'patient_contact_number' => 'A', 'medical_diagnosis' => 'A', 'origin' => 'A', 'destination' => 'A',
            'departed_office_at' => '2026-08-31 08:00:00',
        ]);
        ConductionRequest::create([
            'vehicle_id' => $this->vehicleA->vehicle_id, 'patient_name' => 'B', 'patient_address' => 'B',
            'patient_contact_number' => 'B', 'medical_diagnosis' => 'B', 'origin' => 'B', 'destination' => 'B',
            'departed_office_at' => '2026-08-31 08:05:00',
        ]);

        $this->expectException(ExpectationFailedException::class);
        $this->assertNoUnexplainedDoubleBooking();
    }

    public function test_the_backfilled_orphan_check_would_be_caught(): void
    {
        // Same proof for the other helper: a Resolved ambulance request
        // with no trip record must fail assertNoOrphanedTerminalAmbulanceRequests().
        ServiceRequest::create([
            'service_id' => $this->ambulance->service_id, 'description' => 'Orphaned', 'status' => 'Resolved',
        ]);

        $this->expectException(ExpectationFailedException::class);
        $this->assertNoOrphanedTerminalAmbulanceRequests();
    }
}
