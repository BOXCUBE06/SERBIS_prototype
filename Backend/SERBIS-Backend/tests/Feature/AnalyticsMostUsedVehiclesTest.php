<?php

namespace Tests\Feature;

use App\Models\ConductionRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/analytics — section 8, fleet usage.
 *
 * tbl_conduction_requests carries no resident_id (filed by MDRRMO staff, see
 * the table's own migration comment), so unlike most sections this one is not
 * barangay-filterable — nothing here tests that filter because there is
 * nothing for it to narrow.
 */
class AnalyticsFleetUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $ambulance;

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

        $this->ambulance = Vehicle::create([
            'unit_identifier' => 'Ambulance 1',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);
    }

    private function trip(array $overrides, string $createdAtUtc): ConductionRequest
    {
        $trip = ConductionRequest::create(array_merge([
            'vehicle_id' => $this->ambulance->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 1',
            'patient_contact_number' => '09171234567',
            'medical_diagnosis' => 'Fever',
            'origin' => 'Barangay Hall',
            'destination' => 'Echague Hospital',
        ], $overrides));

        DB::table('tbl_conduction_requests')
            ->where('conduction_request_id', $trip->conduction_request_id)
            ->update(['created_at' => $createdAtUtc]);

        return $trip->fresh();
    }

    private function report(array $query = []): array
    {
        return $this->getJson('/api/admin/analytics?'.http_build_query($query))
            ->assertOk()
            ->json()['fleet'];
    }

    public function test_trips_are_counted_per_vehicle_within_the_window(): void
    {
        $this->trip([], '2026-09-05 00:00:00');
        $this->trip([], '2026-09-10 00:00:00');
        // Outside the window.
        $this->trip([], '2026-08-01 00:00:00');

        $fleet = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(2, $fleet['totalTrips']);
        $this->assertCount(1, $fleet['units']);
        $this->assertSame('Ambulance 1', $fleet['units'][0]['label']);
        $this->assertSame(2, $fleet['units'][0]['trips']);
    }

    public function test_a_trip_still_out_does_not_count_toward_duration(): void
    {
        $this->trip([
            'departed_office_at' => '2026-09-05 08:00:00',
            'returned_office_at' => '2026-09-05 10:00:00',
        ], '2026-09-05 00:00:00');

        // Departed, not yet returned.
        $this->trip([
            'departed_office_at' => '2026-09-06 08:00:00',
        ], '2026-09-06 00:00:00');

        $fleet = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(2, $fleet['totalTrips']);
        $this->assertSame(1, $fleet['duration']['n']);
        $this->assertEqualsWithDelta(2, $fleet['duration']['medianHours'], 0.01);
    }

    public function test_distance_is_counted_only_when_both_odometer_readings_exist(): void
    {
        $this->trip(['odometer_start' => 100, 'odometer_end' => 150], '2026-09-05 00:00:00');
        // Only one reading — must not count.
        $this->trip(['odometer_start' => 200], '2026-09-06 00:00:00');

        $fleet = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(1, $fleet['distance']['n']);
        $this->assertEqualsWithDelta(50, $fleet['distance']['medianKm'], 0.01);
    }

    public function test_a_trip_with_no_vehicle_assigned_gets_its_own_bucket(): void
    {
        $this->trip(['vehicle_id' => null], '2026-09-05 00:00:00');

        $fleet = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame('No unit recorded', $fleet['units'][0]['label']);
        $this->assertSame(1, $fleet['units'][0]['trips']);
    }
}
