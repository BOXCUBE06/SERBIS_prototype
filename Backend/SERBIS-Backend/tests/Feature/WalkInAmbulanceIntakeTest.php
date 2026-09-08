<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/admin/service-requests, ambulance only — the structured intake
 * fields added alongside `description` (see the migration). Deliberately a
 * separate file from WalkInServiceRequestTest: that one's fixture service
 * ("Medical Transport / Ambulance") slugifies to a code that is NOT
 * `ambulance-medical-response`, so none of this behaviour is reachable from
 * it — the real service name is what AMBULANCE_SERVICE_CODE keys off, both
 * here and in ServiceRequestQueue.vue.
 */
class WalkInAmbulanceIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Service $ambulance;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        // Slugifies to 'ambulance-medical-response' via Service::booted().
        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->actingAs($this->admin);
    }

    public function test_structured_fields_are_required_for_a_walk_in_ambulance_request(): void
    {
        $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Pedro Ramos',
            'walk_in_contact_number' => '09179876543',
            'service_id' => $this->ambulance->service_id,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'patient_name', 'patient_address', 'pickup_location', 'destination', 'condition_notes',
        ]);
    }

    public function test_description_is_not_required_for_an_ambulance_request(): void
    {
        // The opposite of the rule above: description is the ONE field this
        // service no longer needs from the client, because it is composed
        // server-side from the structured fields.
        $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Pedro Ramos',
            'walk_in_contact_number' => '09179876543',
            'service_id' => $this->ambulance->service_id,
            'patient_name' => 'Pedro Ramos',
            'patient_address' => 'Purok 3, San Fabian',
            'pickup_location' => 'Purok 3, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Chest pain, conscious',
        ])->assertStatus(201);
    }

    public function test_a_walk_in_ambulance_request_stores_structured_columns_and_composes_description(): void
    {
        $response = $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Pedro Ramos',
            'walk_in_contact_number' => '09179876543',
            'service_id' => $this->ambulance->service_id,
            'patient_name' => 'Pedro Ramos',
            'patient_age' => 67,
            'patient_sex' => 'male',
            'patient_address' => 'Purok 3, San Fabian',
            'pickup_location' => 'Purok 3, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Chest pain, conscious, breathing shallow',
        ])->assertStatus(201);

        $response->assertJsonPath('patient_name', 'Pedro Ramos')
            ->assertJsonPath('patient_age', 67)
            ->assertJsonPath('patient_sex', 'male')
            ->assertJsonPath('pickup_location', 'Purok 3, San Fabian')
            ->assertJsonPath('destination', 'Echague District Hospital');

        // Same shape parseAmbulanceDescription (ConductionRequestView.vue)
        // and the mobile app's AmbulanceFormData.metaLines() both produce —
        // an admin-filed walk-in must dispatch through the exact same path
        // an app submission does, not a second format only this endpoint
        // writes.
        $expected = implode("\n", [
            'Patient: Pedro Ramos',
            'Purok 3, San Fabian → Echague District Hospital',
            'Condition: Chest pain, conscious, breathing shallow',
            'Contact: 09179876543',
        ]);
        $this->assertSame($expected, $response->json('description'));
    }

    public function test_resident_linked_ambulance_request_uses_the_accounts_phone_number(): void
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171112222',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $response = $this->postJson('/api/admin/service-requests', [
            'resident_id' => $resident->getKey(),
            'service_id' => $this->ambulance->service_id,
            'patient_name' => 'Maria Santos',
            'patient_address' => 'Purok 3, San Fabian',
            'pickup_location' => 'Purok 3, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Fever',
        ])->assertStatus(201);

        $this->assertStringContainsString('Contact: 09171112222', $response->json('description'));
    }

    public function test_non_ambulance_walk_in_is_unaffected_and_carries_no_structured_columns(): void
    {
        $other = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris and obstacles',
        ]);

        $response = $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Jose Cruz',
            'walk_in_contact_number' => '09170001111',
            'service_id' => $other->service_id,
            'description' => 'Fallen tree blocking the road.',
        ])->assertStatus(201);

        $response->assertJsonPath('description', 'Fallen tree blocking the road.')
            ->assertJsonPath('patient_name', null)
            ->assertJsonPath('pickup_location', null);
    }
}
