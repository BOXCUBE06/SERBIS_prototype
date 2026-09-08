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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Structured ambulance intake on the resident-facing path.
 *
 * store() used to accept a single `description` string and nothing else, so
 * patient_name, patient_age, patient_sex, patient_address,
 * patient_contact_number, pickup_location, destination and condition_notes
 * were NULL on every app-filed request — the admin panel's structured detail
 * view (ServiceRequestQueue.vue, gated on patient_name) never rendered for one,
 * and createConductionStub() filled the trip record with placeholders.
 *
 * The required set here is deliberately narrower than adminStore()'s five:
 * only patient_name and destination. A staffer at the counter has the
 * requester in front of them; a resident on a phone may not have the address
 * or the diagnosis, and those are confirmed during admin verification.
 *
 * Uses the real service name so its code slugifies to
 * `ambulance-medical-response`, which is what the required_if rules key on.
 */
class ResidentAmbulanceIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Service $ambulance;

    private Service $roadClearing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->roadClearing = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal.',
        ]);
    }

    /** create(), not image(): image() needs GD, which is not installed here. */
    private function validId(): UploadedFile
    {
        return UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->ambulance->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'destination' => 'Echague District Hospital',
            'valid_id' => $this->validId(),
        ], $overrides);
    }

    // ---- the required pair -------------------------------------------------

    public function test_the_two_required_fields_are_enough_to_file(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload())
            ->assertStatus(201);

        $created = ServiceRequest::first();
        $this->assertSame('Juan Dela Cruz', $created->patient_name);
        $this->assertSame('Echague District Hospital', $created->destination);
        // Everything else stays optional on this path.
        $this->assertNull($created->patient_age);
        $this->assertNull($created->patient_sex);
        $this->assertNull($created->patient_address);
        $this->assertNull($created->patient_contact_number);
        $this->assertNull($created->condition_notes);
    }

    public function test_a_missing_patient_name_is_rejected(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['patient_name' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['patient_name']);

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_a_missing_destination_is_rejected(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['destination' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    public function test_the_four_optional_columns_are_stored_when_sent(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'patient_age' => 62,
                'patient_sex' => 'female',
                'patient_address' => 'Purok 2, San Fabian',
                'patient_contact_number' => '09189999999',
                'pickup_location' => 'Purok 2, San Fabian',
                'condition_notes' => 'Chest pains',
            ]))
            ->assertStatus(201);

        $created = ServiceRequest::first();
        $this->assertSame(62, $created->patient_age);
        $this->assertSame('female', $created->patient_sex);
        $this->assertSame('Purok 2, San Fabian', $created->patient_address);
        $this->assertSame('09189999999', $created->patient_contact_number);
        $this->assertSame('Chest pains', $created->condition_notes);
    }

    public function test_an_out_of_range_age_is_rejected(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['patient_age' => 200]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['patient_age']);
    }

    // ---- description ------------------------------------------------------

    public function test_the_server_composes_the_description(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'pickup_location' => 'Purok 2, San Fabian',
                'condition_notes' => 'Chest pains',
            ]))
            ->assertStatus(201);

        $this->assertSame(
            "Patient: Juan Dela Cruz\n"
            ."Purok 2, San Fabian → Echague District Hospital\n"
            ."Condition: Chest pains\n"
            .'Contact: 09171111111',
            ServiceRequest::first()->description
        );
    }

    public function test_a_client_sent_description_is_ignored_for_an_ambulance_request(): void
    {
        // The composition is the server's, so a stale client that still sends
        // its own prose must not be able to overwrite it.
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'description' => 'whatever the old app used to send',
            ]))
            ->assertStatus(201);

        $this->assertStringNotContainsString(
            'whatever the old app used to send',
            ServiceRequest::first()->description
        );
        $this->assertStringContainsString('Patient: Juan Dela Cruz', ServiceRequest::first()->description);
    }

    public function test_the_composed_description_prefers_the_patients_own_number(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'patient_contact_number' => '09189999999',
            ]))
            ->assertStatus(201);

        $description = ServiceRequest::first()->description;
        $this->assertStringContainsString('Contact: 09189999999', $description);
        $this->assertStringNotContainsString('09171111111', $description);
    }

    // ---- pickup defaulting -------------------------------------------------

    public function test_a_blank_pickup_defaults_to_the_registered_barangay(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload())
            ->assertStatus(201);

        $created = ServiceRequest::first();
        $this->assertSame('San Fabian', $created->pickup_location);
        $this->assertStringContainsString('San Fabian → Echague District Hospital', $created->description);
    }

    public function test_a_supplied_pickup_is_not_overwritten(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'pickup_location' => 'Purok 7, beside the chapel',
            ]))
            ->assertStatus(201);

        $this->assertSame('Purok 7, beside the chapel', ServiceRequest::first()->pickup_location);
    }

    public function test_a_whitespace_only_pickup_counts_as_blank(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['pickup_location' => '   ']))
            ->assertStatus(201);

        $this->assertSame('San Fabian', ServiceRequest::first()->pickup_location);
    }

    // ---- other services are untouched --------------------------------------

    public function test_a_non_ambulance_request_still_requires_a_description(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', [
                'service_id' => $this->roadClearing->getKey(),
                'valid_id' => $this->validId(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_a_non_ambulance_request_needs_no_patient_fields(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', [
                'service_id' => $this->roadClearing->getKey(),
                'description' => 'Fallen tree blocking the provincial road.',
                'valid_id' => $this->validId(),
            ])
            ->assertStatus(201);

        $created = ServiceRequest::first();
        $this->assertSame('Fallen tree blocking the provincial road.', $created->description);
        // The structured columns must never be written on another service —
        // patient details on a road-clearing report would be a data leak in
        // the panel's own detail view.
        $this->assertNull($created->patient_name);
        $this->assertNull($created->pickup_location);
        $this->assertNull($created->destination);
    }

    public function test_patient_fields_sent_on_a_non_ambulance_request_are_dropped(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', [
                'service_id' => $this->roadClearing->getKey(),
                'description' => 'Fallen tree.',
                'patient_name' => 'Should Not Persist',
                'destination' => 'Should Not Persist',
                'valid_id' => $this->validId(),
            ])
            ->assertStatus(201);

        $created = ServiceRequest::first();
        $this->assertNull($created->patient_name);
        $this->assertNull($created->destination);
    }

    // ---- the trip record's contact ----------------------------------------

    public function test_the_trip_record_uses_the_patients_number_when_one_was_given(): void
    {
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available',
        ]);

        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'patient_contact_number' => '09189999999',
            ]))
            ->assertStatus(201);

        $request = ServiceRequest::first();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $vehicle->vehicle_id,
        ])->assertOk();

        $this->assertSame('09189999999', ConductionRequest::first()->patient_contact_number);
    }

    public function test_the_trip_record_falls_back_to_the_account_number(): void
    {
        // The existing derivation, unchanged — and the only answer for every
        // row filed before patient_contact_number existed.
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available',
        ]);

        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload())
            ->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertNull($request->patient_contact_number);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $vehicle->vehicle_id,
        ])->assertOk();

        $this->assertSame('09171111111', ConductionRequest::first()->patient_contact_number);
    }
}
