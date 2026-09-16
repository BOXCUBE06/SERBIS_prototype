<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/analytics.
 *
 * The cases that matter here are the ones where a plausible-looking
 * implementation is silently wrong:
 *
 * - created_at is stored UTC but an office day is a Manila day, so a request
 *   filed late in the Manila evening is stored on the previous UTC date. Read
 *   without converting, it lands on the wrong weekday and the wrong hour, and
 *   the resulting heatmap still looks like a heatmap.
 * - turnaround reads partly-backfilled columns, so a null has to be excluded
 *   rather than counted as zero, and the sample size has to travel with the
 *   number.
 * - the page and the dashboard must agree on the same window, which is what
 *   the shared barangay helper is for.
 */
class AnalyticsReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

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

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
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

    /**
     * Writes created_at directly: the point of most of these tests is a row
     * filed at a specific instant, which a normal create() cannot produce.
     */
    private function requestAt(string $utc, array $overrides = []): ServiceRequest
    {
        $request = ServiceRequest::create(array_merge([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Blocked road',
            'status' => 'Pending',
        ], $overrides));

        DB::table('tbl_service_request')
            ->where('request_id', $request->request_id)
            ->update(['created_at' => $utc]);

        return $request->fresh();
    }

    private function report(array $query = []): array
    {
        return $this->getJson('/api/admin/analytics?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function wholeOf(string $from, string $to): array
    {
        return ['preset' => 'custom', 'from' => $from, 'to' => $to];
    }

    /**
     * 2026-09-02 17:30 UTC is 2026-09-03 01:30 in Manila — a different date,
     * a different weekday (Wednesday to Thursday) and a different hour.
     * Reading the stored value without converting puts this request in
     * Wed 17:00, which is a cell a real office would have been closed for.
     */
    public function test_the_demand_grid_is_bucketed_in_manila_not_utc(): void
    {
        $this->requestAt('2026-09-02 17:30:00');

        $grid = $this->report($this->wholeOf('2026-09-01', '2026-09-30'))['demand']['grid'];

        // Mon=0 .. Thu=3, Wed=2.
        $this->assertSame(1, $grid[3][1], 'must land on Thursday 01:00 Manila');
        $this->assertSame(0, $grid[2][17], 'must not land on Wednesday 17:00 UTC');
    }

    public function test_the_demand_grid_totals_every_request_in_range(): void
    {
        $this->requestAt('2026-09-02 17:30:00');
        $this->requestAt('2026-09-03 01:00:00');
        $this->requestAt('2026-09-04 23:59:00');

        $demand = $this->report($this->wholeOf('2026-09-01', '2026-09-30'))['demand'];

        $this->assertSame(3, $demand['total']);
        $this->assertSame(3, array_sum(array_map('array_sum', $demand['grid'])));
    }

    /**
     * The window boundary is taken in Manila too. 2026-08-31 16:30 UTC is
     * already 2026-09-01 00:30 in Manila, so a September filter must include
     * it — and an August filter must not.
     */
    public function test_range_boundaries_are_manila_days(): void
    {
        $this->requestAt('2026-08-31 16:30:00');

        $september = $this->report($this->wholeOf('2026-09-01', '2026-09-30'));
        $august = $this->report($this->wholeOf('2026-08-01', '2026-08-31'));

        $this->assertSame(1, $september['demand']['total'], 'Manila already says September');
        $this->assertSame(0, $august['demand']['total']);
    }

    public function test_the_last_day_of_a_custom_range_is_included(): void
    {
        // 23:30 Manila on the final day, i.e. 15:30 UTC the same date.
        $this->requestAt('2026-09-30 15:30:00');

        $report = $this->report($this->wholeOf('2026-09-01', '2026-09-30'));

        $this->assertSame(1, $report['demand']['total'], 'the closing boundary must be inclusive of its whole day');
    }

    public function test_turnaround_excludes_nulls_and_publishes_the_sample_size(): void
    {
        // Answered after exactly 4 hours.
        $answered = $this->requestAt('2026-09-02 00:00:00');
        DB::table('tbl_service_request')->where('request_id', $answered->request_id)
            ->update(['first_responded_at' => '2026-09-02 04:00:00']);

        // Two rows that were never answered; they must not count as zero.
        $this->requestAt('2026-09-03 00:00:00');
        $this->requestAt('2026-09-04 00:00:00');

        $turnaround = $this->report($this->wholeOf('2026-09-01', '2026-09-30'))['turnaround'];

        $this->assertSame(1, $turnaround['firstResponse']['n']);
        // Delta, not identity: JSON has no int/float distinction, so a whole
        // number of hours comes back as 4 rather than 4.0.
        $this->assertEqualsWithDelta(4, $turnaround['firstResponse']['medianHours'], 0.01);
        $this->assertSame(3, $turnaround['coverage']['requests']);
        $this->assertSame(1, $turnaround['coverage']['withFirstResponse']);
    }

    public function test_turnaround_reports_no_median_rather_than_zero_on_an_empty_sample(): void
    {
        $this->requestAt('2026-09-02 00:00:00');

        $turnaround = $this->report($this->wholeOf('2026-09-01', '2026-09-30'))['turnaround'];

        $this->assertNull($turnaround['firstResponse']['medianHours'], 'no data is not the same as zero hours');
        $this->assertSame(0, $turnaround['firstResponse']['n']);
    }

    /**
     * Median, not mean. One request left open for 100 days alongside three
     * same-day ones has a mean near 25 days and a median of 1 — and no real
     * request took 25 days.
     */
    public function test_turnaround_uses_the_median_so_one_outlier_cannot_move_it(): void
    {
        foreach ([1, 1, 1, 100] as $i => $days) {
            $request = $this->requestAt('2026-09-01 00:00:00');
            DB::table('tbl_service_request')->where('request_id', $request->request_id)->update([
                'resolved_at' => now()->parse('2026-09-01 00:00:00')->addDays($days)->toDateTimeString(),
            ]);
        }

        $turnaround = $this->report($this->wholeOf('2026-09-01', '2026-09-30'))['turnaround'];

        $this->assertSame(4, $turnaround['resolution']['n']);
        $this->assertEqualsWithDelta(1, $turnaround['resolution']['medianDays'], 0.01);
    }

    public function test_aging_counts_only_open_requests(): void
    {
        $this->requestAt(now()->subDays(10)->toDateTimeString());
        $this->requestAt(now()->subHours(2)->toDateTimeString());
        $this->requestAt(now()->subDays(30)->toDateTimeString(), ['status' => 'Resolved']);

        $aging = $this->report()['aging'];

        $this->assertSame(2, $aging['total'], 'a resolved request is not aging');
        $this->assertSame(1, $aging['data'][0], 'under a day');
        $this->assertSame(1, $aging['data'][3], '7+ days');
        $this->assertSame(10, $aging['oldestDays']);
    }

    /**
     * The condition this whole pass exists for: both endpoints run the same
     * window through BarangayRequestCounts, so they cannot disagree about how
     * many requests there were.
     */
    public function test_the_dashboard_and_the_report_agree_on_the_total(): void
    {
        $this->requestAt(now()->subDay()->toDateTimeString());
        $this->requestAt(now()->subDay()->toDateTimeString(), ['resident_id' => null, 'walk_in_name' => 'Jose Cruz']);
        $this->requestAt(now()->subDay()->toDateTimeString(), ['resident_id' => null, 'walk_in_name' => 'Ana Lim']);

        $dashboardTotal = $this->getJson('/api/admin/dashboard')->assertOk()->json('totalsByPeriod.all');
        $reportTotals = $this->report(['preset' => 'year'])['totals'];

        $raw = DB::table('tbl_service_request')->count();

        $this->assertSame($raw, $dashboardTotal);
        $this->assertSame($raw, $reportTotals['combined']);
        $this->assertSame(2, $reportTotals['walkIn']);
    }

    public function test_a_status_change_is_visible_on_the_next_report_read(): void
    {
        $request = $this->requestAt(now()->subDay()->toDateTimeString());

        $before = $this->report(['preset' => 'year'])['aging']['total'];
        $this->assertSame(1, $before);

        $request->update(['status' => 'Resolved']);

        $this->assertSame(0, $this->report(['preset' => 'year'])['aging']['total'], 'the report must not serve a pre-write payload');
    }

    public function test_the_service_filter_narrows_every_section(): void
    {
        $other = Service::create(['service_name' => 'Fire Rescue', 'description' => 'Fire']);

        $this->requestAt('2026-09-02 01:00:00');
        $this->requestAt('2026-09-02 02:00:00', ['service_id' => $other->getKey()]);

        $filtered = $this->report($this->wholeOf('2026-09-01', '2026-09-30') + ['service_id' => $other->getKey()]);

        $this->assertSame(1, $filtered['demand']['total']);
        $this->assertSame(1, $filtered['volume']['total']);
        $this->assertSame([['label' => 'Fire Rescue', 'data' => [1]]], $filtered['volume']['series']);
    }

    public function test_the_barangay_filter_excludes_walk_ins(): void
    {
        $this->requestAt('2026-09-02 01:00:00');
        $this->requestAt('2026-09-02 02:00:00', ['resident_id' => null, 'walk_in_name' => 'Jose Cruz']);

        $filtered = $this->report(
            $this->wholeOf('2026-09-01', '2026-09-30') + ['barangay_id' => $this->barangay->barangay_id]
        );

        $this->assertSame(1, $filtered['demand']['total'], 'a walk-in records no barangay, so it cannot match one');
    }

    public function test_it_defaults_to_this_quarter(): void
    {
        $report = $this->report();

        $this->assertSame('quarter', $report['range']['preset']);
        $this->assertSame('Asia/Manila', $report['range']['timezone']);
    }

    public function test_it_rejects_an_unknown_filter_value(): void
    {
        $this->getJson('/api/admin/analytics?barangay_id=999999')->assertStatus(422);
        $this->getJson('/api/admin/analytics?from=31-09-2026&preset=custom&to=2026-09-30')->assertStatus(422);
    }

    public function test_it_is_admin_only(): void
    {
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->resident, 'sanctum')
            ->getJson('/api/admin/analytics')
            ->assertForbidden();
    }
}
