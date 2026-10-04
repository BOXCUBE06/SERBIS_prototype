<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/dashboard — the "Most requested" ranking has its own
 * Today/Week/Month/All-time filter. The point of these tests is that a
 * request placed outside a period's window is excluded from that period but
 * still counted under 'all' — a bug here would count everything as 'today'
 * or nothing as 'all', both of which would look fine on an empty dev DB.
 * Days are Manila days: created_at is UTC, and a UTC midnight is 08:00 there.
 */
class AnalyticsDashboardTest extends TestCase
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
            'service_name' => 'Ambulance',
            'description' => 'Pick-up and drop-off',
        ]);

        $this->actingAs($this->admin);
    }

    private function requestDatedAt(\DateTimeInterface $date): ServiceRequest
    {
        $req = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Test request',
            'status' => 'Pending',
        ]);
        // created_at is auto-set by the model; back-date it directly so the
        // period boundaries below have something to actually filter on.
        $req->created_at = $date;
        $req->saveQuietly();

        return $req;
    }

    private function servicesIn(string $period): int
    {
        return array_sum($this->getJson('/api/admin/dashboard')->assertOk()->json("charts.pieByPeriod.{$period}.services.data"));
    }

    public function test_a_request_outside_the_week_window_is_excluded_from_week_but_counted_in_all(): void
    {
        $this->requestDatedAt(now()->subDays(20));

        $this->assertSame(0, $this->servicesIn('week'));
        $this->assertSame(1, $this->servicesIn('all'));
    }

    public function test_a_request_from_today_is_counted_in_every_period(): void
    {
        $this->requestDatedAt(now());

        foreach (['today', 'week', 'month', 'all'] as $period) {
            $this->assertSame(1, $this->servicesIn($period), "expected 1 request counted in period '{$period}'");
        }
    }

    public function test_the_day_starts_at_manila_midnight_not_utc_midnight(): void
    {
        // 12:00 UTC on 10 Sep is 20:00 in Manila.
        Carbon::setTestNow('2026-09-10 12:00:00');

        // 01:00 Manila on the 10th: the previous UTC date, but office-today.
        $this->requestDatedAt(Carbon::parse('2026-09-09 17:00:00'));
        // 23:00 Manila on the 9th: office-yesterday.
        $this->requestDatedAt(Carbon::parse('2026-09-09 15:00:00'));

        $this->assertSame(1, $this->servicesIn('today'));
        $this->assertSame(2, $this->servicesIn('week'));
    }

    public function test_a_request_with_no_service_is_ranked_as_others_not_dropped(): void
    {
        $this->requestDatedAt(now());
        $other = $this->requestDatedAt(now());
        $other->service_id = null;
        $other->saveQuietly();

        $services = $this->getJson('/api/admin/dashboard')->assertOk()->json('charts.pieByPeriod.all.services');

        $this->assertSame(2, array_sum($services['data']));
        $this->assertContains('Others', $services['labels']);
    }

    public function test_all_four_periods_are_present_for_the_pie(): void
    {
        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        foreach (['today', 'week', 'month', 'all'] as $period) {
            $this->assertIsArray($response->json("charts.pieByPeriod.{$period}.services.data"));
            $this->assertIsArray($response->json("charts.pieByPeriod.{$period}.items.data"));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
