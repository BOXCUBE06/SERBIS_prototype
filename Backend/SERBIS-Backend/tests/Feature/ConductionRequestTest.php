<?php

namespace Tests\Feature;

use App\Models\ConductionRequest;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConductionRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'patient_name' => 'Juan Dela Cruz',
            'patient_age' => 45,
            'patient_address' => 'Purok 3, San Isidro',
            'patient_sex' => 'male',
            'patient_contact_number' => '09171234567',
            'vehicle' => 'Ambulance 1',
            'medical_diagnosis' => 'Suspected stroke',
            'plate_no' => 'AMB-001',
            'origin' => 'San Isidro',
            'destination' => 'Echague District Hospital',
            'drivers' => ['Pedro Santos', ''],
            'authorized_passengers' => ['Maria Santos'],
            'patient_relatives' => ['', ''],
        ], $overrides);
    }

    public function test_a_conduction_request_can_be_filed_with_its_personnel(): void
    {
        $response = $this->postJson('/api/conduction-requests', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('patient_name', 'Juan Dela Cruz')
            ->assertJsonPath('trip_status', 'Not dispatched');

        $conductionRequest = ConductionRequest::first();

        // The blank second driver slot and both blank relative slots must not
        // become rows — the form always sends the fixed slot count.
        $this->assertCount(2, $conductionRequest->people);
        $this->assertSame('Pedro Santos', $conductionRequest->drivers()->first()->name);
        $this->assertSame('Maria Santos', $conductionRequest->authorizedPassengers()->first()->name);
    }

    /**
     * The model has carried service_request_id/vehicle_id in $fillable since
     * they were added — this proves store()'s own validate() actually lets
     * them through rather than silently stripping them, which is what
     * DISPATCH's prefill flow depends on to link a trip log back to its
     * booking.
     */
    public function test_a_conduction_request_can_be_linked_to_its_booking(): void
    {
        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $booking = ServiceRequest::create([
            'service_id' => $service->service_id,
            'vehicle_id' => $vehicle->vehicle_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        $response = $this->postJson('/api/conduction-requests', $this->payload([
            'service_request_id' => $booking->request_id,
            'vehicle_id' => $vehicle->vehicle_id,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('service_request_id', $booking->request_id)
            ->assertJsonPath('vehicle_id', $vehicle->vehicle_id);

        $conductionRequest = ConductionRequest::first();
        $this->assertSame($booking->request_id, $conductionRequest->service_request_id);
        $this->assertSame($vehicle->vehicle_id, $conductionRequest->vehicle_id);
    }

    /**
     * The bug this guards: the "Dispatch" button on an already-approved
     * Booked request stayed clickable after the first trip was filed
     * (nothing flipped the request's status), so a second click filed a
     * second conduction request against the same booking with nothing to
     * stop it. Unlike the vehicle conflict below, there is no override —
     * a booking maps to at most one trip.
     */
    public function test_filing_a_second_trip_against_an_already_dispatched_booking_is_refused(): void
    {
        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        $booking = ServiceRequest::create([
            'service_id' => $service->service_id,
            'vehicle_id' => $vehicle->vehicle_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        $firstTrip = ConductionRequest::create($this->payload([
            'service_request_id' => $booking->request_id,
            'destination' => 'Echague District Hospital',
        ]));

        $response = $this->postJson('/api/conduction-requests', $this->payload([
            'service_request_id' => $booking->request_id,
        ]));

        $response->assertStatus(409);
        $this->assertSame('Echague District Hospital', $response->json('conflict.destination'));
        $this->assertSame($firstTrip->conduction_request_id, $response->json('conflict.conduction_request_id'));
        $this->assertSame(1, ConductionRequest::count());
    }

    /**
     * The common case, per store()'s own comment: a walk-in trip with no
     * prior booking. Filing several of these must never trip the new guard
     * — it only ever compares non-null service_request_id values.
     */
    public function test_multiple_walk_in_trips_with_no_booking_are_unaffected_by_the_duplicate_guard(): void
    {
        $this->postJson('/api/conduction-requests', $this->payload())->assertStatus(201);
        $this->postJson('/api/conduction-requests', $this->payload())->assertStatus(201);

        $this->assertSame(2, ConductionRequest::count());
    }

    /**
     * The other half of the same bug: filing a trip from the manual
     * "Dispatch" path never advanced the booking past Booked, so it had no
     * path to "Mark as Resolved" either (gated on status === 'Responding').
     * Mirrors what ServiceRequestController::update() already does for the
     * instant path's own Booked → Responding transition.
     */
    public function test_dispatching_a_booked_request_flips_it_to_responding(): void
    {
        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        $booking = ServiceRequest::create([
            'service_id' => $service->service_id,
            'vehicle_id' => $vehicle->vehicle_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        $this->postJson('/api/conduction-requests', $this->payload([
            'service_request_id' => $booking->request_id,
        ]))->assertStatus(201);

        $this->assertSame('Responding', $booking->fresh()->status);
    }

    /**
     * The flip is gated on the linked request currently being Booked, not
     * fired unconditionally — a create-dialog call against anything else
     * (here, still Pending: never approved, no vehicle assigned) must leave
     * that request's status exactly as it was.
     */
    public function test_filing_a_trip_against_a_non_booked_request_does_not_change_its_status(): void
    {
        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);
        $pendingRequest = ServiceRequest::create([
            'service_id' => $service->service_id,
            'description' => 'Not yet approved',
            'status' => 'Pending',
        ]);

        $this->postJson('/api/conduction-requests', $this->payload([
            'service_request_id' => $pendingRequest->request_id,
        ]))->assertStatus(201);

        $this->assertSame('Pending', $pendingRequest->fresh()->status);
    }

    /**
     * The 409 above is the application's guard; this proves the schema
     * backs it up. Goes through the model directly, not the endpoint — the
     * point is that the constraint holds even if store()'s own check is
     * ever bypassed, not that the controller works (already covered above).
     */
    public function test_the_unique_index_rejects_a_second_conduction_request_for_the_same_booking_at_the_db_level(): void
    {
        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);
        $booking = ServiceRequest::create([
            'service_id' => $service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        ConductionRequest::create($this->payload(['service_request_id' => $booking->request_id]));

        $this->expectException(QueryException::class);

        ConductionRequest::create($this->payload(['service_request_id' => $booking->request_id]));
    }

    /**
     * The other half of the same constraint: nullable stays nullable. A
     * unique index must not collapse every walk-in trip (no booking, no
     * service_request_id) down to just one allowed row.
     */
    public function test_the_unique_index_allows_any_number_of_walk_in_trips_with_no_booking(): void
    {
        ConductionRequest::create($this->payload());
        ConductionRequest::create($this->payload());
        ConductionRequest::create($this->payload());

        $this->assertSame(3, ConductionRequest::count());
    }

    /**
     * C7 of docs/dispatch-audit.md's remediation plan: the standalone form's
     * free-text `vehicle` field never checked whether the unit was already
     * out. A picker bound to vehicle_id makes that checkable — this is the
     * guard, exercised at the endpoint since the picker itself is a Vue
     * concern with nothing here to test.
     */
    public function test_filing_against_a_unit_already_on_a_trip_is_refused(): void
    {
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        $inProgress = ConductionRequest::create($this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
            'destination' => 'Echague District Hospital',
            'departed_office_at' => '2026-08-31 08:00:00',
        ]));
        $this->assertSame('In transit', $inProgress->trip_status);

        $response = $this->postJson('/api/conduction-requests', $this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
        ]));

        $response->assertStatus(409);
        $this->assertSame('Echague District Hospital', $response->json('conflict.destination'));
        $this->assertSame($inProgress->conduction_request_id, $response->json('conflict.conduction_request_id'));
        $this->assertSame(1, ConductionRequest::count());
    }

    public function test_a_returned_unit_is_no_longer_a_conflict(): void
    {
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        ConductionRequest::create($this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
            'departed_office_at' => '2026-08-31 08:00:00',
            'returned_office_at' => '2026-08-31 09:00:00',
        ]));

        $this->postJson('/api/conduction-requests', $this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
        ]))->assertStatus(201);
    }

    public function test_a_unit_never_dispatched_is_not_a_conflict(): void
    {
        // ConductionRequest::create() alone — a filed but never-departed trip
        // record — must not itself count as "already on a trip".
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        ConductionRequest::create($this->payload(['vehicle_id' => $vehicle->vehicle_id]));

        $this->postJson('/api/conduction-requests', $this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
        ]))->assertStatus(201);
    }

    public function test_a_reason_overrides_the_conflict_and_is_recorded(): void
    {
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        ConductionRequest::create($this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
            'departed_office_at' => '2026-08-31 08:00:00',
        ]));

        $response = $this->postJson('/api/conduction-requests', $this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
            'override_reason' => 'Second patient, unit reassigned mid-trip on dispatcher instruction.',
        ]));

        $response->assertStatus(201);
        $this->assertSame(2, ConductionRequest::count());
        $this->assertSame(
            'Second patient, unit reassigned mid-trip on dispatcher instruction.',
            ConductionRequest::latest('conduction_request_id')->first()->vehicle_override_reason,
        );
    }

    public function test_an_override_reason_is_not_stored_when_there_was_no_conflict(): void
    {
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);

        $this->postJson('/api/conduction-requests', $this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
            'override_reason' => 'Not needed, sent anyway.',
        ]))->assertStatus(201);

        $this->assertNull(ConductionRequest::first()->vehicle_override_reason);
    }

    public function test_the_conflict_is_logged_to_the_audit_trail(): void
    {
        $admin = User::create([
            'first_name' => 'MDRRMO', 'last_name' => 'Admin', 'email_address' => 'admin2@test.local',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active',
        ]);
        Sanctum::actingAs($admin);

        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available',
        ]);
        ConductionRequest::create($this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
            'departed_office_at' => '2026-08-31 08:00:00',
        ]));

        $this->postJson('/api/conduction-requests', $this->payload([
            'vehicle_id' => $vehicle->vehicle_id,
            'override_reason' => 'Dispatcher-approved reassignment.',
        ]))->assertStatus(201);

        $newest = ConductionRequest::latest('conduction_request_id')->first();
        $log = DB::table('tbl_system_logs')
            ->where('auditable_type', ConductionRequest::class)
            ->where('auditable_id', $newest->conduction_request_id)
            ->where('action_type', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Dispatcher-approved reassignment.', $log->new_values);
    }

    /**
     * The admin panel's detail view shows the linked booking's own
     * scheduled_at and status — index() and show() both have to eager-load
     * the relation for that, not just carry the bare id.
     */
    public function test_index_and_show_eager_load_the_linked_booking(): void
    {
        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $booking = ServiceRequest::create([
            'service_id' => $service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
            'scheduled_at' => '2026-09-01 09:00:00',
        ]);

        $conductionRequest = ConductionRequest::create($this->payload([
            'service_request_id' => $booking->request_id,
        ]));

        $this->getJson('/api/conduction-requests')
            ->assertOk()
            ->assertJsonPath('0.service_request.request_id', $booking->request_id)
            ->assertJsonPath('0.service_request.status', 'Booked');

        $this->getJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}")
            ->assertOk()
            ->assertJsonPath('service_request.request_id', $booking->request_id);
    }

    public function test_required_patient_fields_are_enforced(): void
    {
        $this->postJson('/api/conduction-requests', $this->payload(['patient_name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['patient_name']);
    }

    public function test_authorized_passengers_are_capped_at_two(): void
    {
        $this->postJson('/api/conduction-requests', $this->payload([
            'authorized_passengers' => ['Maria Santos', 'Ana Reyes'],
        ]))->assertStatus(201);

        $this->postJson('/api/conduction-requests', $this->payload([
            'authorized_passengers' => ['Maria Santos', 'Ana Reyes', 'Jose Cruz'],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['authorized_passengers']);
    }

    public function test_the_passenger_cap_does_not_narrow_the_other_two_roles(): void
    {
        // Drivers and relatives keep MAX_PEOPLE_PER_ROLE. Three of each would
        // fail if the passenger limit had been applied to all three.
        $this->postJson('/api/conduction-requests', $this->payload([
            'drivers' => ['Pedro Santos', 'Luis Ramos', 'Ben Aquino'],
            'patient_relatives' => ['Ana Cruz', 'Rosa Cruz', 'Mario Cruz'],
        ]))->assertStatus(201);
    }

    public function test_the_passenger_cap_also_holds_on_the_trip_log(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload());

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'authorized_passengers' => ['Maria Santos', 'Ana Reyes', 'Jose Cruz'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['authorized_passengers']);
    }

    public function test_trip_log_can_be_filled_in_over_separate_calls(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload());

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18 08:00:00',
            'odometer_start' => 10000,
        ])->assertStatus(200)
            ->assertJsonPath('trip_status', 'In transit');

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'arrived_destination_at' => '2026-08-18 08:30:00',
            'departed_destination_at' => '2026-08-18 09:00:00',
            'returned_office_at' => '2026-08-18 09:30:00',
            'odometer_end' => 10025,
        ])->assertStatus(200)
            ->assertJsonPath('trip_status', 'Completed');

        $conductionRequest->refresh();
        $this->assertSame(10000, $conductionRequest->odometer_start);
        $this->assertSame(10025, $conductionRequest->odometer_end);
    }

    /**
     * The Responding -> Resolved gate (ServiceRequestController::update())
     * accepts no_arrival_reason as an alternative to arrived_destination_at.
     * This is what actually lets staff write one.
     */
    public function test_trip_log_accepts_a_no_arrival_reason(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload());

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'no_arrival_reason' => 'Patient already transported by family before crew arrived.',
        ])->assertOk();

        $this->assertSame(
            'Patient already transported by family before crew arrived.',
            $conductionRequest->fresh()->no_arrival_reason
        );
        $this->assertNull($conductionRequest->fresh()->arrived_destination_at);
    }

    /**
     * C5's bridge (ServiceRequestController::createConductionStub) creates a
     * trip record with no personnel at all — a driver is only ever known
     * once the crew is assigned, not at approval time. This is the one
     * place that gap can be closed after the fact.
     */
    public function test_trip_log_can_set_drivers_on_a_stub_with_none(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['drivers' => []]));
        $this->assertSame(0, $conductionRequest->drivers()->count());

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'drivers' => ['Rico Santos'],
        ])->assertOk();

        $this->assertSame(['Rico Santos'], $conductionRequest->drivers()->pluck('name')->all());
    }

    public function test_trip_log_replaces_drivers_rather_than_appending(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload());
        \App\Models\ConductionRequestPerson::create([
            'conduction_request_id' => $conductionRequest->conduction_request_id,
            'role' => 'driver', 'name' => 'Pedro Santos', 'position' => 0,
        ]);
        $this->assertSame(1, $conductionRequest->drivers()->count());

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'drivers' => ['Rico Santos', 'Ben Cruz'],
        ])->assertOk();

        $this->assertSame(
            ['Rico Santos', 'Ben Cruz'],
            $conductionRequest->drivers()->pluck('name')->all(),
        );
    }

    public function test_trip_log_without_a_drivers_key_leaves_existing_drivers_alone(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload());
        \App\Models\ConductionRequestPerson::create([
            'conduction_request_id' => $conductionRequest->conduction_request_id,
            'role' => 'driver', 'name' => 'Pedro Santos', 'position' => 0,
        ]);

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'odometer_start' => 500,
        ])->assertOk();

        $this->assertSame(['Pedro Santos'], $conductionRequest->drivers()->pluck('name')->all());
    }

    /**
     * The admin panel's <input type="datetime-local"> can only ever send a
     * naive string — that is not a defect to fix, but the day some caller
     * starts sending an offset instead should be visible, not assumed. Both
     * shapes must keep working and resolve to the identical instant either
     * way.
     */
    public function test_a_naive_checkpoint_is_logged_and_read_as_manila(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $conductionRequest = ConductionRequest::create($this->payload());

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18 08:00:00',
        ])->assertStatus(200);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'no UTC offset')
                && $context['field'] === 'departed_office_at'
                && $context['conduction_request_id'] === $conductionRequest->conduction_request_id
        );

        // 8 AM Manila is midnight UTC.
        $this->assertTrue(
            $conductionRequest->fresh()->departed_office_at->utc()->equalTo(
                \Carbon\Carbon::parse('2026-08-18 00:00:00', 'UTC')
            )
        );
    }

    public function test_an_offset_carrying_checkpoint_is_not_logged_and_is_honoured_as_sent(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $conductionRequest = ConductionRequest::create($this->payload());

        // The same instant as the naive test above, sent as UTC directly.
        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18T00:00:00.000000Z',
        ])->assertStatus(200);

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('info');

        $this->assertTrue(
            $conductionRequest->fresh()->departed_office_at->utc()->equalTo(
                \Carbon\Carbon::parse('2026-08-18 00:00:00', 'UTC')
            )
        );
    }

    public function test_odometer_end_before_odometer_start_is_rejected(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['patient_name' => 'Odometer Case']));

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'odometer_start' => 10000,
            'odometer_end' => 9000,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['odometer_end']);
    }

    public function test_odometer_end_is_checked_against_a_previously_saved_start(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['patient_name' => 'Two Call Odometer']));

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'odometer_start' => 10000,
        ])->assertStatus(200);

        // Sent alone, in a later call — must still fail against the odometer_start
        // saved earlier, not just fields present in this one request.
        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'odometer_end' => 500,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['odometer_end']);
    }

    public function test_out_of_order_checkpoints_are_rejected(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['patient_name' => 'Out Of Order']));

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18 09:00:00',
            'arrived_destination_at' => '2026-08-18 08:30:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['arrived_destination_at']);
    }

    public function test_a_checkpoint_is_checked_against_one_saved_in_an_earlier_call(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['patient_name' => 'Two Call Checkpoint']));

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18 09:00:00',
        ])->assertStatus(200);

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'arrived_destination_at' => '2026-08-18 08:30:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['arrived_destination_at']);
    }

    public function test_a_checkpoint_cannot_be_recorded_while_an_earlier_one_is_blank(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['patient_name' => 'Gap Case']));

        // The state the panel could not describe: trip_status would have read
        // 'Completed' while the detail view still offered to start the trip.
        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'returned_office_at' => '2026-08-18 09:30:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['returned_office_at']);

        // A gap in the middle is rejected too, and the departure that shares
        // the call is not saved on its own.
        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18 08:00:00',
            'departed_destination_at' => '2026-08-18 09:00:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['departed_destination_at']);

        $conductionRequest->refresh();
        $this->assertNull($conductionRequest->departed_office_at);
        $this->assertSame('Not dispatched', $conductionRequest->trip_status);
    }

    public function test_a_naive_checkpoint_is_read_as_office_local_and_stored_as_utc(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['patient_name' => 'Timezone Case']));

        // Exactly what <input type="datetime-local"> sends: no offset, no zone.
        // The office typed 8 AM Manila.
        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18 08:00:00',
        ])->assertStatus(200);

        // Read straight off the column, not through the model: the cast is what
        // is being checked, so going through it would prove nothing.
        $stored = DB::table('tbl_conduction_requests')
            ->where('conduction_request_id', $conductionRequest->conduction_request_id)
            ->value('departed_office_at');

        $this->assertSame('2026-08-18 00:00:00', (string) $stored);
    }

    public function test_a_checkpoint_sent_with_an_offset_is_honoured_as_sent(): void
    {
        $conductionRequest = ConductionRequest::create($this->payload(['patient_name' => 'Explicit Offset']));

        $this->patchJson("/api/conduction-requests/{$conductionRequest->conduction_request_id}/trip-log", [
            'departed_office_at' => '2026-08-18T08:00:00+00:00',
        ])->assertStatus(200);

        $stored = DB::table('tbl_conduction_requests')
            ->where('conduction_request_id', $conductionRequest->conduction_request_id)
            ->value('departed_office_at');

        // Already UTC, so it is not shifted a second time.
        $this->assertSame('2026-08-18 08:00:00', (string) $stored);
    }

    public function test_index_lists_requests_with_their_people(): void
    {
        $this->postJson('/api/conduction-requests', $this->payload())->assertStatus(201);

        $this->getJson('/api/conduction-requests')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.people.0.role', 'driver');
    }

    // ---- The no-arrival timeline -------------------------------------------
    //
    // no_arrival_reason (2026-09-02) records a trip that never reached its
    // destination: patient already transported, crew recalled, transport
    // refused. The checkpoint ordering guard predated it and contradicted it —
    // with arrival blank it refused departed_destination_at, and refusing that
    // refused returned_office_at, so a crew that turned back and drove home
    // could not record either. These four pin the shape that replaced it.

    private function tripLog(ConductionRequest $trip, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->patchJson(
            "/api/conduction-requests/{$trip->conduction_request_id}/trip-log",
            $body,
        );
    }

    /**
     * The bug this was all for. A trip that never arrived still went out and
     * still came back, and the log has to be able to say so.
     */
    public function test_a_trip_that_never_arrived_can_still_record_its_return(): void
    {
        $trip = ConductionRequest::create($this->payload());

        $this->tripLog($trip, [
            'departed_office_at' => '2026-09-03 08:00:00',
            'no_arrival_reason' => 'Patient already transported by family before crew arrived.',
            'returned_office_at' => '2026-09-03 09:30:00',
        ])->assertOk();

        $fresh = $trip->fresh();

        $this->assertNotNull($fresh->returned_office_at);
        $this->assertNull($fresh->arrived_destination_at, 'The trip never arrived; nothing should have invented an arrival.');
        $this->assertNull($fresh->departed_destination_at);
    }

    /** A trip either arrived or it did not. Both is not a state anyone can act on. */
    public function test_an_arrival_and_a_no_arrival_reason_cannot_both_be_set(): void
    {
        $trip = ConductionRequest::create($this->payload());

        $this->tripLog($trip, [
            'departed_office_at' => '2026-09-03 08:00:00',
            'arrived_destination_at' => '2026-09-03 08:40:00',
            'no_arrival_reason' => 'Patient already transported by family before crew arrived.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('no_arrival_reason');
    }

    /**
     * There is no departure from a destination the crew never reached. Refused
     * outright rather than ordered around, because the field is meaningless on
     * this kind of trip rather than merely out of sequence.
     */
    public function test_departed_destination_is_refused_when_the_trip_never_arrived(): void
    {
        $trip = ConductionRequest::create($this->payload());

        $this->tripLog($trip, [
            'departed_office_at' => '2026-09-03 08:00:00',
            'no_arrival_reason' => 'Crew recalled mid-route.',
            'departed_destination_at' => '2026-09-03 09:00:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('departed_destination_at');
    }

    /**
     * The guard this change loosens is still a guard. With no reason recorded
     * the full four-step sequence applies exactly as before — otherwise the fix
     * would have quietly opened every gap the ordering check exists to catch.
     */
    public function test_the_full_sequence_still_applies_when_no_reason_is_recorded(): void
    {
        $trip = ConductionRequest::create($this->payload());

        $this->tripLog($trip, [
            'departed_office_at' => '2026-09-03 08:00:00',
            'departed_destination_at' => '2026-09-03 09:00:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('departed_destination_at');
    }
}
