<?php

namespace Tests\Feature;

use App\Models\ConductionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_index_lists_requests_with_their_people(): void
    {
        $this->postJson('/api/conduction-requests', $this->payload())->assertStatus(201);

        $this->getJson('/api/conduction-requests')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.people.0.role', 'driver');
    }
}
