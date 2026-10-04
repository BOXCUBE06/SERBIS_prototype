<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every surface that counts requests must agree on the same rows. The seed is
 * the awkward set: no service, no barangay, a request at 07:00 Manila on the
 * 1st (stored 23:00 UTC the day before), and a loan, which must not be
 * mistaken for a request or a walk-in.
 */
class AnalyticsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        // 12:00 UTC on 10 Sep is 20:00 in Manila.
        Carbon::setTestNow('2026-09-10 12:00:00');

        $this->actingAs(User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]));

        $this->service = Service::create(['service_name' => 'Road Clearing', 'description' => 'Clear a road']);
        $this->barangay = Barangay::create(['barangay_name' => 'Reconciliation Test Barangay']);

        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $request = fn (array $with) => ServiceRequest::create($with + ['description' => 'Test', 'status' => 'Pending']);

        // Walk-in with no service and no barangay, filed now.
        $request(['resident_id' => null, 'walk_in_name' => 'Jose Cruz']);
        // Filed in the app, now.
        $request(['resident_id' => $resident->getKey(), 'service_id' => $this->service->getKey()]);
        // 07:00 Manila on 1 Sep.
        $first = $request(['resident_id' => $resident->getKey(), 'service_id' => $this->service->getKey()]);
        DB::table('tbl_service_request')->where('request_id', $first->request_id)->update(['created_at' => '2026-08-31 23:00:00']);

        // A loan by an account in a real barangay.
        $loaner = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Pedro',
            'last_name' => 'Ramos',
            'phone_number' => '09172222222',
            'email_address' => 'pedro@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        $boat = Equipment::create(['item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available']);
        EquipmentBorrowing::create(['resident_id' => $loaner->getKey(), 'equipment_id' => $boat->getKey(), 'quantity' => 1, 'status' => 'Released']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function report(string $from, string $to, array $filter = []): array
    {
        return $this->getJson('/api/admin/analytics?'.http_build_query(['preset' => 'custom', 'from' => $from, 'to' => $to] + $filter))
            ->assertOk()
            ->json();
    }

    public function test_dashboard_and_report_count_the_same_requests_in_the_day_and_30_day_windows(): void
    {
        $dashboard = $this->getJson('/api/admin/dashboard')->assertOk();
        $stacked = fn (array $s) => array_sum(array_map('array_sum', array_column($s['series'], 'data')));

        // Today holds the two filed now; 30 days adds the one at 07:00 Manila on the 1st.
        foreach (['today' => ['2026-09-10', 2], 'month' => ['2026-08-12', 3]] as $period => [$from, $expected]) {
            $report = $this->report($from, '2026-09-10');

            $this->assertSame($expected, array_sum($dashboard->json("charts.pieByPeriod.{$period}.services.data")), "{$period}: dashboard pie");
            $this->assertSame($expected, $report['totals']['serviceRequests'], "{$period}: requests filed");
            $this->assertSame($expected, $stacked($report['volume']), "{$period}: volume by service");
            $this->assertSame($expected, $stacked($report['outcomes']), "{$period}: outcomes");
        }
    }

    public function test_barangay_counts_plus_walk_ins_equal_requests_filed_under_every_filter(): void
    {
        // Expected loans in the borrower's barangay; a service filter leaves none.
        $filters = [
            'unfiltered' => [[], 1],
            'barangay' => [['barangay_id' => $this->barangay->barangay_id], 1],
            'service' => [['service_id' => $this->service->getKey()], 0],
        ];

        foreach ($filters as $name => [$filter, $loans]) {
            $report = $this->report('2026-08-12', '2026-09-10', $filter);
            $coverage = $report['barangayCoverage'];
            $row = collect($coverage['barangays'])->firstWhere('name', $this->barangay->barangay_name);

            $this->assertSame(
                $report['totals']['serviceRequests'],
                array_sum(array_column($coverage['barangays'], 'requests')) + $coverage['walkIn'],
                "{$name}: barangay counts + walk-ins"
            );
            // The loan shows in its barangay's own column and nowhere else.
            $this->assertSame(2, $row['requests'], "{$name}: the loan is not a request");
            $this->assertSame($loans, $row['loans'], "{$name}: loans");
        }

        // One walk-in request; the loan is not one.
        $unfiltered = $this->report('2026-08-12', '2026-09-10');
        $this->assertSame(1, $unfiltered['totals']['walkIn'], 'totals.walkIn');
        $this->assertSame(1, $unfiltered['barangayCoverage']['walkIn'], 'a loan must not change walkIn');
    }
}
