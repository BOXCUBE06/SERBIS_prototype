<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the flat JSON shape of a service request across the three read
 * endpoints. The ambulance_bookings split (moving patient/scheduling columns
 * to a separate table) must not change what a client receives — this asserts
 * every pinned field stays at the top level, with its exact value, for both
 * an ambulance request (non-null) and a non-ambulance one (null), regardless
 * of which table backs it afterward.
 */
class AmbulanceRequestContractTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly what the API returns today for the ambulance request seeded below. */
    private const AMBULANCE_EXPECTED = [
        'patient_name' => 'Juan Dela Cruz',
        'patient_age' => 62,
        'patient_sex' => 'male',
        'patient_address' => 'Purok 2, San Fabian',
        'patient_contact_number' => '09189999999',
        'pickup_location' => 'Purok 2, San Fabian',
        'destination' => 'Echague District Hospital',
        'condition_notes' => 'Chest pains',
        'scheduled_at' => '2026-09-15T08:00:00.000000Z',
        'scheduled_end' => '2026-09-15T10:00:00.000000Z',
        'approved_at' => '2026-09-10T09:30:00.000000Z',
    ];

    /**
     * The 8 patient/intake keys only, for actions that legitimately change
     * scheduled_at/scheduled_end/approved_at (approve, reschedule) — those
     * three are not what this subset is protecting.
     */
    private const AMBULANCE_PATIENT_ONLY_EXPECTED = [
        'patient_name' => 'Juan Dela Cruz',
        'patient_age' => 62,
        'patient_sex' => 'male',
        'patient_address' => 'Purok 2, San Fabian',
        'patient_contact_number' => '09189999999',
        'pickup_location' => 'Purok 2, San Fabian',
        'destination' => 'Echague District Hospital',
        'condition_notes' => 'Chest pains',
    ];

    /** Same keys, all null, for the non-ambulance request seeded below. */
    private const PLAIN_EXPECTED = [
        'patient_name' => null,
        'patient_age' => null,
        'patient_sex' => null,
        'patient_address' => null,
        'patient_contact_number' => null,
        'pickup_location' => null,
        'destination' => null,
        'condition_notes' => null,
        'scheduled_at' => null,
        'scheduled_end' => null,
        'approved_at' => null,
    ];

    private Resident $resident;

    private User $admin;

    private ServiceRequest $ambulanceRequest;

    private ServiceRequest $plainRequest;

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

        $ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $roadClearing = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal.',
        ]);

        $this->ambulanceRequest = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
            'scheduled_at' => Carbon::parse('2026-09-15 08:00:00'),
            'scheduled_end' => Carbon::parse('2026-09-15 10:00:00'),
            'approved_at' => Carbon::parse('2026-09-10 09:30:00'),
        ])->fresh();

        AmbulanceBooking::create([
            'request_id' => $this->ambulanceRequest->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'patient_age' => 62,
            'patient_sex' => 'male',
            'patient_address' => 'Purok 2, San Fabian',
            'patient_contact_number' => '09189999999',
            'pickup_location' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Chest pains',
        ]);

        $this->plainRequest = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $roadClearing->getKey(),
            'description' => 'Fallen tree blocking the provincial road.',
            'status' => 'Pending',
        ])->fresh();
    }

    /** @return array<string,mixed> */
    private function findRow(array $rows, int $requestId): array
    {
        foreach ($rows as $row) {
            if ((int) $row['request_id'] === $requestId) {
                return $row;
            }
        }

        $this->fail("no row with request_id {$requestId} in response");
    }

    private function assertFieldsMatch(array $row, array $expected): void
    {
        foreach ($expected as $field => $value) {
            $this->assertArrayHasKey($field, $row, "missing top-level key: {$field}");
            $this->assertSame($value, $row[$field], "field {$field} mismatch");
        }
    }

    public function test_resident_index_keeps_ambulance_fields_flat(): void
    {
        Sanctum::actingAs($this->resident);

        $rows = $this->getJson('/api/service-requests')->assertOk()->json();

        $this->assertCount(2, $rows);
        $this->assertFieldsMatch($this->findRow($rows, $this->ambulanceRequest->getKey()), self::AMBULANCE_EXPECTED);
        $this->assertFieldsMatch($this->findRow($rows, $this->plainRequest->getKey()), self::PLAIN_EXPECTED);
    }

    public function test_resident_show_keeps_ambulance_fields_flat(): void
    {
        Sanctum::actingAs($this->resident);

        $ambulanceRow = $this->getJson("/api/service-requests/{$this->ambulanceRequest->getKey()}")->assertOk()->json();
        $this->assertFieldsMatch($ambulanceRow, self::AMBULANCE_EXPECTED);

        $plainRow = $this->getJson("/api/service-requests/{$this->plainRequest->getKey()}")->assertOk()->json();
        $this->assertFieldsMatch($plainRow, self::PLAIN_EXPECTED);
    }

    public function test_admin_index_keeps_ambulance_fields_flat(): void
    {
        Sanctum::actingAs($this->admin);

        $rows = $this->getJson('/api/admin/service-requests')->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertFieldsMatch($this->findRow($rows, $this->ambulanceRequest->getKey()), self::AMBULANCE_EXPECTED);
        $this->assertFieldsMatch($this->findRow($rows, $this->plainRequest->getKey()), self::PLAIN_EXPECTED);
    }

    public function test_update_response_keeps_ambulance_fields_flat(): void
    {
        Sanctum::actingAs($this->admin);

        // A field update() actually accepts, untouched by the 8 patient
        // columns — the point is that they still come back correctly
        // alongside it, not that this call changes them.
        $row = $this->putJson("/api/service-requests/{$this->ambulanceRequest->getKey()}", [
            'internal_notes' => 'Verified by phone',
        ])->assertOk()->json();

        $this->assertFieldsMatch($row, self::AMBULANCE_EXPECTED);
    }

    public function test_approve_response_keeps_ambulance_fields_flat(): void
    {
        $vehicle = Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);

        Sanctum::actingAs($this->admin);

        $row = $this->patchJson("/api/service-requests/{$this->ambulanceRequest->getKey()}/approve", [
            'vehicle_id' => $vehicle->vehicle_id,
        ])->assertOk()->json();

        // Scheduling fields are approve()'s own concern (vehicle_id claimed,
        // scheduled_end computed) — only the patient/intake columns are this
        // test's business.
        $this->assertFieldsMatch($row, self::AMBULANCE_PATIENT_ONLY_EXPECTED);
    }

    public function test_reschedule_response_keeps_ambulance_fields_flat(): void
    {
        Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);

        Sanctum::actingAs($this->admin);

        $row = $this->patchJson("/api/service-requests/{$this->ambulanceRequest->getKey()}/reschedule", [
            'scheduled_at' => '2026-09-20 08:00:00',
            'scheduled_end' => '2026-09-20 10:00:00',
            'remarks' => 'Moved per resident request',
        ])->assertOk()->json();

        // scheduled_at/scheduled_end are exactly what this call moves —
        // asserting them against the original booking would be wrong. The
        // patient/intake columns are what must survive untouched.
        $this->assertFieldsMatch($row, self::AMBULANCE_PATIENT_ONLY_EXPECTED);
    }

    public function test_cancel_response_keeps_ambulance_fields_flat(): void
    {
        Sanctum::actingAs($this->resident);

        $row = $this->patchJson("/api/service-requests/{$this->ambulanceRequest->getKey()}/cancel")
            ->assertOk()
            ->json();

        $this->assertFieldsMatch($row, self::AMBULANCE_EXPECTED);
    }
}
