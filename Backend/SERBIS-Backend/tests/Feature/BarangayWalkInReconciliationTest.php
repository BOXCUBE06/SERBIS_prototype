<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\BarangayRequestCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The barangay breakdown must not lose rows.
 *
 * tbl_service_request.resident_id is nullable — null on a walk-in filed at
 * the counter — and the dashboard's old inner join through tbl_residents
 * dropped those rows silently. Locally that was 20 of 50 requests: the map
 * and the ranking both under-reported by 40% and every number on the card
 * still looked internally consistent.
 *
 * The load-bearing assertion here is that the section total equals the raw
 * row count. Under the old inner join it does not, so restoring that join
 * fails this test rather than quietly shrinking the numbers again.
 */
class BarangayWalkInReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

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

        $this->service = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Clear a blocked road',
        ]);

        $this->actingAs($this->admin);
    }

    private function appRequest(): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Filed from the app',
            'status' => 'Pending',
        ]);
    }

    /**
     * Exactly what adminStore() writes for a counter filing: no resident_id,
     * a name and a contact number instead. A fixture that set resident_id
     * here would not be a walk-in at all and the test would pass on data
     * production never produces.
     */
    private function walkInRequest(): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => null,
            'walk_in_name' => 'Jose Cruz',
            'walk_in_contact_number' => '09180000000',
            'service_id' => $this->service->getKey(),
            'description' => 'Filed at the counter',
            'status' => 'Pending',
        ]);
    }

    public function test_walk_in_requests_are_counted_but_not_placed_on_a_barangay(): void
    {
        $this->appRequest();
        $this->appRequest();
        $this->walkInRequest();
        $this->walkInRequest();
        $this->walkInRequest();

        $counts = BarangayRequestCounts::forWindow();

        // The bucket has to be non-empty or this test cannot tell a LEFT JOIN
        // from an inner one.
        $this->assertSame(3, $counts['walkIn'], 'walk-in bucket must hold every request with no resident');

        $this->assertSame(
            [['name' => 'San Fabian', 'requests' => 2]],
            $counts['barangays'],
            'only the two app-filed requests carry a barangay'
        );

        $this->assertSame(5, $counts['total']);
    }

    public function test_section_total_equals_the_raw_row_count(): void
    {
        $this->appRequest();
        $this->walkInRequest();
        $this->walkInRequest();

        $equipment = Equipment::create([
            'item_name' => 'Generator',
            'total_quantity' => 2,
            'available_quantity' => 2,
            'status' => 'Available',
        ]);

        EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $equipment->getKey(),
            'quantity' => 1,
            'status' => 'Pending',
        ]);

        $raw = DB::table('tbl_service_request')->count()
            + DB::table('tbl_equipment_borrowing')->count();

        $counts = BarangayRequestCounts::forWindow();

        $this->assertSame($raw, $counts['total'], 'no row may be dropped by the join');

        $placed = array_sum(array_column($counts['barangays'], 'requests'));
        $this->assertSame($counts['total'], $placed + $counts['walkIn'], 'placed + unplaced must reconcile');
    }

    public function test_dashboard_endpoint_reports_the_reconciled_total(): void
    {
        $this->appRequest();
        $this->walkInRequest();
        $this->walkInRequest();
        $this->walkInRequest();

        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        $raw = DB::table('tbl_service_request')->count();

        $this->assertSame($raw, $response->json('totalsByPeriod.all'));
        $this->assertSame(3, $response->json('walkInByPeriod.all'));
        $this->assertSame(1, $response->json('mapDataByPeriod.all.0.requests'));
    }

    /**
     * A walk-in filed outside the window must not leak into it. Without the
     * date predicate every period would report the all-time figure, which
     * reads as plausible on a small dev database.
     */
    public function test_the_walk_in_bucket_respects_the_period_window(): void
    {
        $old = $this->walkInRequest();
        $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();

        $this->walkInRequest();

        $this->assertSame(2, BarangayRequestCounts::forWindow()['walkIn']);
        $this->assertSame(1, BarangayRequestCounts::forWindow(now()->subDays(6))['walkIn']);
    }
}
