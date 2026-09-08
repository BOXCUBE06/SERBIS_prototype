<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/dashboard — the map, the barangay ranking and the category
 * breakdown each got their own Today/Week/Month/All-time filter (previously
 * a single shared toggle drove only the hero card and the trend chart; these
 * three were silently always all-time). The point of these tests is that a
 * request placed outside a period's window is excluded from that period but
 * still counted under 'all' — a bug here would count everything as 'today'
 * or nothing as 'all', both of which would look fine on an empty dev DB.
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

    public function test_a_request_outside_the_week_window_is_excluded_from_week_but_counted_in_all(): void
    {
        $this->requestDatedAt(now()->subDays(20));

        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        $weekTotal = collect($response->json('mapDataByPeriod.week'))->sum('requests');
        $allTotal = collect($response->json('mapDataByPeriod.all'))->sum('requests');

        $this->assertSame(0, $weekTotal);
        $this->assertSame(1, $allTotal);
    }

    public function test_a_request_from_today_is_counted_in_every_period(): void
    {
        $this->requestDatedAt(now());

        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        foreach (['today', 'week', 'month', 'all'] as $period) {
            $total = collect($response->json("mapDataByPeriod.{$period}"))->sum('requests');
            $this->assertSame(1, $total, "expected 1 request counted in period '{$period}'");
        }
    }

    public function test_pie_data_is_scoped_the_same_way_as_the_map(): void
    {
        $this->requestDatedAt(now()->subDays(20));

        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        $weekServices = collect($response->json('charts.pieByPeriod.week.services.data'))->sum();
        $allServices = collect($response->json('charts.pieByPeriod.all.services.data'))->sum();

        $this->assertSame(0, $weekServices);
        $this->assertSame(1, $allServices);
    }

    public function test_all_four_periods_are_present_for_both_map_and_pie(): void
    {
        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        foreach (['today', 'week', 'month', 'all'] as $period) {
            $this->assertIsArray($response->json("mapDataByPeriod.{$period}"));
            $this->assertIsArray($response->json("charts.pieByPeriod.{$period}.services.data"));
            $this->assertIsArray($response->json("charts.pieByPeriod.{$period}.items.data"));
        }
    }
}
