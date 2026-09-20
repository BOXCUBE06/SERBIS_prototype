<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} — ServiceRequestController::ALLOWED_TRANSITIONS.
 *
 * Before this guard existed, update() only checked the incoming status
 * against the six-name vocabulary (STATUSES), never against the request's
 * current status. All 30 from/to pairs were reachable through the one
 * endpoint both admin boards write through: a Resolved request could be
 * reopened, a Cancelled one dispatched, a Disapproved one resolved, and —
 * worst — a Booked request could reach Responding without ever calling
 * approve(), skipping its availability re-check and leaving approved_at
 * NULL with no approval SMS sent.
 *
 * test_the_full_matrix_is_enforced below is the exhaustive check: all 36
 * from/to pairs (including same-status), asserting the six the matrix
 * allows succeed and the other thirty 422. The remaining methods mirror the
 * specific flows both boards actually perform, at the exact payload shape
 * the panel sends (ServiceRequestQueue.vue's updateStatus()/approveBooking()),
 * so a matrix that passes the exhaustive check but still breaks a real
 * button would still be caught.
 */
class ServiceRequestTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

    private Service $ambulance;

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

        // Not ambulance-coded — the matrix loop below deliberately stays
        // clear of C5's resolve-completeness gate (docs/dispatch-audit.md
        // finding 1), which is a second, separate restriction layered on
        // top of Responding -> Resolved for the ambulance service only.
        $this->service = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal',
        ]);

        // Slugifies to 'ambulance-medical-response' via Service::booted() —
        // same fixture name ServiceRequestApproveTest and
        // AmbulanceDispatchBridgeTest use, for the same reason.
        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'PAT-01',
            'type' => 'Rescue Vehicle',
            'specification' => null,
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);
    }

    private function requestWithStatus(string $status, array $overrides = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Fallen tree blocking the road',
            'status' => $status,
        ], $overrides));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function transitionMatrix(): array
    {
        $statuses = ['Pending', 'Booked', 'Responding', 'Resolved', 'Cancelled', 'Disapproved'];

        // The map itself: for each target (right-hand side), the sources
        // ALLOWED_TRANSITIONS lists for it. Mirrors the constant exactly —
        // this is the test guarding against that constant drifting, so it
        // is written independently rather than reading it off the class.
        $allowedSourcesFor = [
            'Pending' => [],
            'Booked' => ['Pending'],
            'Responding' => ['Pending', 'Booked'],
            'Resolved' => ['Responding'],
            'Cancelled' => [],
            'Disapproved' => ['Pending', 'Booked'],
        ];

        $cases = [];

        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                $allowed = $from === $to || in_array($from, $allowedSourcesFor[$to], true);
                $label = sprintf('%s -> %s (%s)', $from, $to, $allowed ? 'allowed' : 'blocked');
                $cases[$label] = [$from, $to, $allowed];
            }
        }

        return $cases;
    }

    #[DataProvider('transitionMatrix')]
    public function test_the_full_matrix_is_enforced(string $from, string $to, bool $allowed): void
    {
        $request = $this->requestWithStatus($from);

        $payload = ['status' => $to];
        // required_if:status,Disapproved fires on the status value alone —
        // present it every time the target is Disapproved so a blocked pair
        // 422s on the transition itself, not on a missing remarks field.
        if ($to === 'Disapproved') {
            $payload['remarks'] = 'Outside MDRRMO scope';
        }

        $response = $this->putJson("/api/service-requests/{$request->getKey()}", $payload);

        if ($allowed) {
            $response->assertOk()->assertJsonPath('status', $to);
            $this->assertSame($to, $request->fresh()->status);
        } else {
            $response->assertStatus(422)->assertJsonValidationErrors('status');
            $this->assertSame($from, $request->fresh()->status, "blocked {$from} -> {$to} must leave status untouched");
        }
    }

    /** Probe 1: Pending -> Responding, a vehicle attached, the service is not the ambulance one. */
    public function test_approving_a_pending_request_with_a_non_ambulance_vehicle(): void
    {
        $request = $this->requestWithStatus('Pending');

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Responding');

        $fresh = $request->fresh();
        $this->assertSame('Responding', $fresh->status);
        $this->assertSame($this->vehicle->vehicle_id, $fresh->vehicle_id);
        $this->assertSame('Dispatched', $this->vehicle->fresh()->status);
    }

    /** Probe 2: Pending -> Responding with no vehicle at all — update() never required one. */
    public function test_approving_a_pending_request_with_no_vehicle(): void
    {
        $request = $this->requestWithStatus('Pending');

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
        ])->assertOk()->assertJsonPath('status', 'Responding');

        $fresh = $request->fresh();
        $this->assertSame('Responding', $fresh->status);
        $this->assertNull($fresh->vehicle_id);
    }

    /** Probe 3: an ambulance booking's real lifecycle — Booked -> Responding -> Resolved with a complete trip. */
    public function test_ambulance_booked_to_responding_to_resolved_with_a_complete_trip_record(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        $ambulanceVehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type II',
            'status' => 'Available',
        ]);

        $request = $this->requestWithStatus('Booked', [
            'service_id' => $this->ambulance->getKey(),
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => now()->addDays(2),
        ]);

        // The real dispatch board sequence: approve() first — it re-checks
        // availability, stamps approved_at and sends the approval SMS —
        // then the "Dispatch" button's own PUT is what update()'s ambulance
        // gate (added alongside the matrix) now requires approved_at for.
        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $ambulanceVehicle->vehicle_id,
        ])->assertOk();

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $ambulanceVehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Responding');

        // C5's bridge: the Responding flip creates the linked trip stub.
        $trip = ConductionRequest::where('service_request_id', $request->getKey())->first();
        $this->assertNotNull($trip, 'Booked -> Responding must still create the trip stub');

        $trip->update(['arrived_destination_at' => now()]);
        ConductionRequestPerson::create([
            'conduction_request_id' => $trip->conduction_request_id,
            'role' => 'driver',
            'name' => 'Rico Santos',
            'position' => 0,
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
            'vehicle_id' => $ambulanceVehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Resolved');

        $this->assertSame('Resolved', $request->fresh()->status);
        $this->assertSame('Available', $ambulanceVehicle->fresh()->status);
    }

    /** Probe 5: a Pending request declined outright, never having reached Booked or Responding. */
    public function test_disapproving_a_pending_request(): void
    {
        $request = $this->requestWithStatus('Pending');

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'Outside MDRRMO scope',
        ])->assertOk()->assertJsonPath('status', 'Disapproved');

        $this->assertSame('Disapproved', $request->fresh()->status);
    }

    /** Probe 6: a non-ambulance request resolved straight from Responding — no trip-record gate applies to it. */
    public function test_resolving_a_non_ambulance_request_directly(): void
    {
        $request = $this->requestWithStatus('Responding', ['vehicle_id' => $this->vehicle->vehicle_id]);
        $this->vehicle->update(['status' => 'Dispatched']);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Resolved');

        $this->assertSame('Resolved', $request->fresh()->status);
        $this->assertSame('Available', $this->vehicle->fresh()->status);
    }

    /**
     * Resending the current status is a no-op, not a transition — every PUT
     * this panel makes carries one (see ServiceRequestQueue.vue's
     * updateStatus()), whether or not the operator actually changed it. A
     * vehicle swap while already Responding, or a second identical
     * Disapprove, must keep working exactly as before the matrix landed.
     */
    public function test_resending_the_current_status_is_not_treated_as_a_transition(): void
    {
        $other = Vehicle::create([
            'unit_identifier' => 'PAT-02', 'type' => 'Rescue Vehicle', 'status' => 'Available',
        ]);

        $request = $this->requestWithStatus('Responding', ['vehicle_id' => $this->vehicle->vehicle_id]);
        $this->vehicle->update(['status' => 'Dispatched']);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $other->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Responding');

        $this->assertSame($other->vehicle_id, $request->fresh()->vehicle_id);
        $this->assertSame('Available', $this->vehicle->fresh()->status);
        $this->assertSame('Dispatched', $other->fresh()->status);
    }

    /** Idempotent terminal resend must not error — required explicitly by the matrix spec. */
    public function test_resending_a_terminal_status_is_a_silent_no_op(): void
    {
        $request = $this->requestWithStatus('Resolved');

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
        ])->assertOk()->assertJsonPath('status', 'Resolved');

        $this->assertSame('Resolved', $request->fresh()->status);
    }

    /** The exact bug report: a terminal request must never be reopened through update(). */
    public function test_a_resolved_request_cannot_be_reopened(): void
    {
        $request = $this->requestWithStatus('Resolved');

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Pending',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('Resolved', $request->fresh()->status);
    }

    /** The exact bug report: a cancelled request must never be dispatched through update(). */
    public function test_a_cancelled_request_cannot_be_dispatched(): void
    {
        $request = $this->requestWithStatus('Cancelled');

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('Cancelled', $request->fresh()->status);
        $this->assertNull($request->fresh()->vehicle_id);
    }

    /** The exact bug report: a disapproved request must never be resolved through update(). */
    public function test_a_disapproved_request_cannot_be_resolved(): void
    {
        $request = $this->requestWithStatus('Disapproved');

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Resolved',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('Disapproved', $request->fresh()->status);
    }
}
