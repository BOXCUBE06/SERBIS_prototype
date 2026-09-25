<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRelative;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\AssignsResponders;
use Tests\TestCase;

/**
 * Relatives collected at request time.
 *
 * tbl_conduction_request_people hangs off tbl_conduction_requests, and that
 * row does not exist until the request reaches Responding — so anyone named
 * at intake had nowhere to be recorded. They now land in
 * tbl_service_request_relatives and are copied onto the trip when one is
 * created.
 *
 * There are two paths that create a trip against a booking, and the copy has
 * to happen on both: the automatic Booked -> Responding flip
 * (ServiceRequestController::createConductionStub) and a manually filed trip
 * (ConductionRequestController::store). docs/dispatch-audit.md already names
 * that split as the drift hazard, so both are exercised here.
 *
 * Uses the real service name so its code slugifies to
 * `ambulance-medical-response`, which is what gates the ambulance-only
 * validation rules and the dispatch bridge.
 */
class IntakeRelativesTest extends TestCase
{
    use AssignsResponders, RefreshDatabase;

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

    /** The walk-in counter form's payload, minus whatever a case overrides. */
    private function walkInPayload(array $overrides = []): array
    {
        return array_merge([
            'walk_in_name' => 'Pedro Cruz',
            'walk_in_contact_number' => '09172222222',
            'service_id' => $this->ambulance->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 2, San Fabian',
            'pickup_location' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Fractured leg',
            'patient_relatives' => ['Lalaine Ferrer'],
        ], $overrides);
    }

    private function relativeNamesOn(ConductionRequest $trip): array
    {
        return $trip->people()
            ->where('role', 'relative')
            ->orderBy('position')
            ->pluck('name')
            ->all();
    }

    // ---- intake ----------------------------------------------------------

    public function test_the_walk_in_form_stores_relatives_on_the_request(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['Lalaine Ferrer', 'Rosa Dela Cruz'],
        ]));

        $response->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertSame(
            ['Lalaine Ferrer', 'Rosa Dela Cruz'],
            $request->relatives()->pluck('name')->all()
        );
        $this->assertSame([0, 1], $request->relatives()->pluck('position')->all());
    }

    public function test_a_residents_own_submission_stores_relatives(): void
    {
        $response = $this->actingAs($this->resident)->postJson('/api/service-requests', [
            'service_id' => $this->ambulance->getKey(),
            // The two fields store() requires for an ambulance request;
            // `description` is composed server-side from them.
            'patient_name' => 'Juan Dela Cruz',
            'destination' => 'Echague District Hospital',
            // create(), not image(): image() needs the GD extension, which is
            // not installed here. Same workaround as ResidentPhotoTest.
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
            'patient_relatives' => ['Lalaine Ferrer'],
        ]);

        $response->assertStatus(201);
        $this->assertSame(['Lalaine Ferrer'], ServiceRequest::first()->relatives()->pluck('name')->all());
    }

    public function test_blank_slots_are_dropped_rather_than_rejected(): void
    {
        // The form renders a blank field by default and "Add relative" adds
        // more, so an untouched slot beside a filled one is the common case,
        // not a malformed request.
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['', 'Lalaine Ferrer', '   '],
        ]))->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertSame(['Lalaine Ferrer'], $request->relatives()->pluck('name')->all());
        // Position renumbers from zero across the surviving names — it is a
        // display order, not the index of the slot the name was typed into.
        $this->assertSame([0], $request->relatives()->pluck('position')->all());
    }

    public function test_an_ambulance_request_with_no_relative_is_refused_on_both_intake_paths(): void
    {
        // Required since 2026-09-20: the hospital asks for a companion. A slot
        // that is present but blank names nobody, so it does not count.
        foreach ([null, [], [''], ['', '   ']] as $relatives) {
            $body = $this->walkInPayload($relatives === null ? [] : ['patient_relatives' => $relatives]);
            if ($relatives === null) {
                unset($body['patient_relatives']);
            }

            $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $body)
                ->assertStatus(422)->assertJsonValidationErrors(['patient_relatives']);

            $this->actingAs($this->resident)->postJson('/api/service-requests', [
                'service_id' => $this->ambulance->getKey(),
                'patient_name' => 'Juan Dela Cruz',
                'destination' => 'Echague District Hospital',
                'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
            ] + ($relatives === null ? [] : ['patient_relatives' => $relatives]))
                ->assertStatus(422)->assertJsonValidationErrors(['patient_relatives']);
        }

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_one_or_two_relatives_are_accepted_and_three_are_not(): void
    {
        foreach ([['Lalaine Ferrer'], ['Lalaine Ferrer', 'Rosa Dela Cruz']] as $relatives) {
            $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
                'patient_relatives' => $relatives,
            ]))->assertStatus(201);
        }

        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['A', 'B', 'C'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['patient_relatives']);
    }

    public function test_a_request_that_is_not_an_ambulance_never_needs_one(): void
    {
        $road = Service::create(['service_name' => 'Road Clearing', 'description' => 'Debris removal.']);

        $this->actingAs($this->resident)->postJson('/api/service-requests', [
            'service_id' => $road->getKey(),
            'description' => 'Fallen tree on the road',
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ])->assertStatus(201);
    }

    public function test_an_over_long_relative_name_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => [str_repeat('a', 256)],
        ]))->assertStatus(422)->assertJsonValidationErrors(['patient_relatives.0']);
    }

    // ---- path 1: the automatic Booked -> Responding flip -----------------

    public function test_relatives_reach_the_trip_created_by_the_dispatch_bridge(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['Lalaine Ferrer', 'Rosa Dela Cruz'],
        ]))->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();
        $this->assertSame(['Lalaine Ferrer', 'Rosa Dela Cruz'], $this->relativeNamesOn($trip));
    }

    public function test_dispatch_still_works_for_a_request_filed_before_relatives_were_required(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['Lalaine Ferrer'],
        ]))->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assignResponder($request);
        // Requests already on file predate the rule and carry no relatives.
        ServiceRequestRelative::query()->delete();

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();
        $this->assertNotNull($trip);
        $this->assertSame([], $this->relativeNamesOn($trip));
    }

    public function test_the_intake_rows_survive_the_copy(): void
    {
        // The trip manifest is what the crew logged; the intake list is what
        // the requester said. Copying must not consume the second.
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['Lalaine Ferrer'],
        ]))->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $this->assertSame(['Lalaine Ferrer'], $request->relatives()->pluck('name')->all());
        $this->assertSame(1, ServiceRequestRelative::count());
    }

    // ---- path 2: a manually filed trip -----------------------------------

    public function test_relatives_reach_a_manually_filed_trip(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'patient_relatives' => ['Lalaine Ferrer', 'Rosa Dela Cruz'],
        ]))->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertSame('Booked', $request->status);

        $this->actingAs($this->admin)->postJson('/api/conduction-requests', [
            'service_request_id' => $request->getKey(),
            'vehicle_id' => $this->vehicle->vehicle_id,
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 2, San Fabian',
            'patient_contact_number' => '09172222222',
            'medical_diagnosis' => 'Fractured leg',
            'origin' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'drivers' => ['Pedro Santos'],
        ])->assertStatus(201);

        $trip = ConductionRequest::first();
        $this->assertSame(['Lalaine Ferrer', 'Rosa Dela Cruz'], $this->relativeNamesOn($trip));
    }

    public function test_a_manually_filed_trip_keeps_relatives_typed_into_its_own_dialog(): void
    {
        // Both lists are real statements about the same trip: one made at the
        // counter, one made by whoever filed the trip. Appending keeps both,
        // in that order, rather than one silently replacing the other.
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'patient_relatives' => ['Lalaine Ferrer'],
        ]))->assertStatus(201);

        $request = ServiceRequest::first();

        $this->actingAs($this->admin)->postJson('/api/conduction-requests', [
            'service_request_id' => $request->getKey(),
            'vehicle_id' => $this->vehicle->vehicle_id,
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 2, San Fabian',
            'patient_contact_number' => '09172222222',
            'medical_diagnosis' => 'Fractured leg',
            'origin' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'drivers' => ['Pedro Santos'],
            'patient_relatives' => ['Rosa Dela Cruz'],
        ])->assertStatus(201);

        $trip = ConductionRequest::first();
        $this->assertSame(['Rosa Dela Cruz', 'Lalaine Ferrer'], $this->relativeNamesOn($trip));
        // Positions stay distinct, or the trip form renders them in an order
        // the database does not actually promise.
        $this->assertSame(
            [0, 1],
            $trip->people()->where('role', 'relative')->orderBy('position')->pluck('position')->all()
        );
    }

    public function test_a_trip_filed_with_no_linked_booking_copies_nothing(): void
    {
        // A walk-in trip with no prior booking is still the common case, and
        // there is no request to read an intake list off.
        $this->actingAs($this->admin)->postJson('/api/conduction-requests', [
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 2, San Fabian',
            'patient_contact_number' => '09172222222',
            'medical_diagnosis' => 'Fractured leg',
            'origin' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'drivers' => ['Pedro Santos'],
        ])->assertStatus(201);

        $this->assertSame([], $this->relativeNamesOn(ConductionRequest::first()));
    }

    // ---- the schema boundary ---------------------------------------------

    public function test_deleting_a_request_takes_its_intake_relatives_with_it(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['Lalaine Ferrer'],
        ]))->assertStatus(201);

        $this->assertSame(1, ServiceRequestRelative::count());

        ServiceRequest::first()->delete();

        $this->assertSame(0, ServiceRequestRelative::count());
    }

    public function test_the_drivers_relation_is_unaffected_by_intake_relatives(): void
    {
        // The resolve gate reads drivers()->exists(). A relative copied onto
        // the trip must not satisfy it.
        $this->actingAs($this->admin)->postJson('/api/admin/service-requests', $this->walkInPayload([
            'patient_relatives' => ['Lalaine Ferrer'],
        ]))->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assignResponder($request);

        $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $this->vehicle->vehicle_id,
        ])->assertOk();

        $trip = ConductionRequest::first();
        $this->assertCount(1, $this->relativeNamesOn($trip));
        $this->assertFalse($trip->drivers()->exists());
        $this->assertSame(
            0,
            ConductionRequestPerson::where('conduction_request_id', $trip->conduction_request_id)
                ->whereIn('role', ['driver', 'passenger'])
                ->count()
        );
    }
}
