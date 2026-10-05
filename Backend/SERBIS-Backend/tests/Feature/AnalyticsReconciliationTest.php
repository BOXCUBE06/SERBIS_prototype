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

    private function extraRequest(Barangay $barangay, Service $service, string $phone): void
    {
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Extra',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'email_address' => $phone.'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        ServiceRequest::create(['resident_id' => $resident->getKey(), 'service_id' => $service->getKey(), 'description' => 'Test', 'status' => 'Pending']);
    }

    public function test_several_barangays_can_be_selected_and_still_reconcile(): void
    {
        $second = Barangay::create(['barangay_name' => 'Reconciliation Test Barangay Two']);
        $this->extraRequest($second, $this->service, '09173333333');

        $report = $this->report('2026-08-12', '2026-09-10', ['barangay_id' => [$second->barangay_id, $this->barangay->barangay_id]]);
        $coverage = $report['barangayCoverage'];

        // The two barangays' requests, and no walk-in: a walk-in has no barangay.
        $this->assertSame(3, $report['totals']['serviceRequests']);
        $this->assertSame(0, $report['totals']['walkIn']);
        $this->assertSame(3, array_sum(array_column($coverage['barangays'], 'requests')) + $coverage['walkIn']);
        $this->assertSame(1, collect($coverage['barangays'])->firstWhere('name', $second->barangay_name)['requests']);
    }

    public function test_several_services_can_be_selected_and_still_reconcile(): void
    {
        $second = Service::create(['service_name' => 'Fire Rescue', 'description' => 'Fire']);
        $this->extraRequest($this->barangay, $second, '09174444444');

        $report = $this->report('2026-08-12', '2026-09-10', ['service_id' => [$second->getKey(), $this->service->getKey()]]);
        $coverage = $report['barangayCoverage'];

        // The no-service walk-in is outside both services; any service filter leaves no loans.
        $this->assertSame(3, $report['totals']['serviceRequests']);
        $this->assertSame(3, array_sum(array_column($coverage['barangays'], 'requests')) + $coverage['walkIn']);
        $this->assertSame(0, $coverage['totalLoans']);

        $tooMany = range(1, 11);
        $this->getJson('/api/admin/analytics?'.http_build_query(['service_id' => $tooMany]))->assertStatus(422);
    }

    public function test_walk_in_can_be_selected_alone_or_with_a_barangay_and_still_reconcile(): void
    {
        // Walk-in alone: the one request with no barangay, and no loans.
        $alone = $this->report('2026-08-12', '2026-09-10', ['barangay_id' => ['walkin']]);
        $coverage = $alone['barangayCoverage'];

        $this->assertSame(1, $alone['totals']['serviceRequests']);
        $this->assertSame(1, $alone['totals']['walkIn']);
        $this->assertSame(1, array_sum(array_column($coverage['barangays'], 'requests')) + $coverage['walkIn']);
        $this->assertSame(0, $coverage['totalLoans']);
        // Walk-in only selects no barangay: no resident figure anywhere.
        $this->assertSame([null], array_unique(array_column($coverage['barangays'], 'residents')));
        $this->assertSame(0, $coverage['totalResidents']);

        // Walk-in plus a barangay: its two requests and the walk-in, and its loan.
        $both = $this->report('2026-08-12', '2026-09-10', ['barangay_id' => ['walkin', $this->barangay->barangay_id]]);
        $coverage = $both['barangayCoverage'];
        $row = collect($coverage['barangays'])->firstWhere('name', $this->barangay->barangay_name);

        $this->assertSame(3, $both['totals']['serviceRequests']);
        $this->assertSame(1, $both['totals']['walkIn']);
        $this->assertSame(3, array_sum(array_column($coverage['barangays'], 'requests')) + $coverage['walkIn']);
        $this->assertSame(1, $row['loans']);
        // Residents only for the chosen barangay (the requester and the borrower); the footer total follows.
        $this->assertSame(2, $row['residents']);
        $this->assertTrue($row['selected']);
        $this->assertSame(2, $coverage['totalResidents']);
    }
}
