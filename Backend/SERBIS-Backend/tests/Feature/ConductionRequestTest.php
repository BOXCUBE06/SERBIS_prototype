<?php

namespace Tests\Feature;

use App\Models\ConductionRequest;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
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
}
