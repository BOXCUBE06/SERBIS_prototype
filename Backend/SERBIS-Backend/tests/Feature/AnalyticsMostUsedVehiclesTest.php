<?php

namespace Tests\Feature;

use App\Models\ConductionRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/analytics — section 8, most used vehicles.
 *
 * Unlike every other section, this one ignores the page's shared date filter
 * entirely and computes its own Today / This week / This month, in Manila
 * calendar time — so every test here freezes the clock rather than passing a
 * preset/from/to query.
 *
 * tbl_conduction_requests carries no resident_id (filed by MDRRMO staff, see
 * the table's own migration comment), so this is not barangay-filterable —
 * nothing here tests that filter because there is nothing for it to narrow.
 */
class AnalyticsMostUsedVehiclesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $ambulance1;

    private Vehicle $ambulance2;

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

        $this->ambulance1 = Vehicle::create([
            'unit_identifier' => 'Ambulance 1',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $this->ambulance2 = Vehicle::create([
            'unit_identifier' => 'Ambulance 2',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);

        // Thursday 10 Sep 2026, noon Manila (04:00 UTC).
        Carbon::setTestNow(Carbon::parse('2026-09-10 04:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A trip log row. $departedUtc null means never dispatched; the log date defaults to the departure. */
    private function trip(array $overrides, ?string $departedUtc, ?string $createdAtUtc = null): ConductionRequest
    {
        $trip = ConductionRequest::create(array_merge([
            'vehicle_id' => $this->ambulance1->getKey(),
            'departed_office_at' => $departedUtc,
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 1',
            'patient_contact_number' => '09171234567',
            'medical_diagnosis' => 'Fever',
            'origin' => 'Barangay Hall',
            'destination' => 'Echague Hospital',
        ], $overrides));

        DB::table('tbl_conduction_requests')
            ->where('conduction_request_id', $trip->conduction_request_id)
            ->update(['created_at' => $createdAtUtc ?? $departedUtc ?? '2026-09-10 01:00:00']);

        return $trip->fresh();
    }

    /** Vehicle trips for September 2026, Manila. */
    private function vehicleTrips(): array
    {
        return $this->getJson('/api/admin/analytics?preset=custom&from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->json()['vehicleTrips'];
    }

    private function tripsOf(string $label): int
    {
        return collect($this->vehicleTrips())->firstWhere('label', $label)['trips'];
    }

    public function test_range_follows_the_date_filter(): void
    {
        // 09:00 Manila, 2 Sep — inside the range. 25 Aug — outside it.
        $this->trip([], '2026-09-02 01:00:00');
        $this->trip([], '2026-08-25 01:00:00');

        $this->assertSame(1, $this->tripsOf('Ambulance 1'));
    }

    public function test_the_range_edges_are_manila_days(): void
    {
        // 00:30 Manila on 1 Sep is 16:30 UTC on 31 Aug: inside. 00:30 Manila on 1 Oct: outside.
        $this->trip([], '2026-08-31 16:30:00');
        $this->trip([], '2026-09-30 16:30:00');

        $this->assertSame(1, $this->tripsOf('Ambulance 1'));
    }

    public function test_vehicles_are_ranked_highest_trip_count_first(): void
    {
        $this->trip(['vehicle_id' => $this->ambulance1->getKey()], '2026-09-10 01:00:00');
        $this->trip(['vehicle_id' => $this->ambulance1->getKey()], '2026-09-10 02:00:00');
        $this->trip(['vehicle_id' => $this->ambulance2->getKey()], '2026-09-10 03:00:00');

        $trips = $this->vehicleTrips();

        $this->assertSame(['Ambulance 1', 2], [$trips[0]['label'], $trips[0]['trips']]);
        $this->assertSame(['Ambulance 2', 1], [$trips[1]['label'], $trips[1]['trips']]);
    }

    public function test_a_vehicle_with_no_trips_in_the_period_still_shows_at_zero(): void
    {
        // Only Ambulance 1 has a trip; Ambulance 2 has none at all.
        $this->trip(['vehicle_id' => $this->ambulance1->getKey()], '2026-09-10 01:00:00');

        $this->assertSame(0, $this->tripsOf('Ambulance 2'), 'a never-used vehicle must still appear, not drop off the chart');
    }

    public function test_a_trip_with_no_vehicle_assigned_is_excluded(): void
    {
        $this->trip(['vehicle_id' => null], '2026-09-10 01:00:00');

        $this->assertSame(0, array_sum(array_column($this->vehicleTrips(), 'trips')), 'an unassigned trip cannot be credited to any vehicle');
    }

    public function test_a_log_with_no_departure_is_not_a_trip(): void
    {
        $this->trip([], '2026-09-10 01:00:00');
        // Logged in September but never dispatched.
        $this->trip([], null, '2026-09-10 02:00:00');

        $this->assertSame(1, $this->tripsOf('Ambulance 1'));
    }

    public function test_the_window_follows_the_departure_date_not_the_log_date(): void
    {
        // Left in September, logged in October: counts.
        $this->trip([], '2026-09-10 01:00:00', '2026-10-02 01:00:00');
        // Logged in September, left in August: does not.
        $this->trip([], '2026-08-25 01:00:00', '2026-09-10 01:00:00');

        $this->assertSame(1, $this->tripsOf('Ambulance 1'));
    }
}
