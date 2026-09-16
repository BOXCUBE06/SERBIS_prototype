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
 * GET /api/admin/analytics — section 10, app adoption (walk-in vs app-filed
 * share by month).
 */
class AnalyticsAppAdoptionTest extends TestCase
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

        $this->service = Service::create(['service_name' => 'Road Clearing', 'description' => 'Clear a blocked road']);

        $this->actingAs($this->admin);
    }

    private function requestAt(?Resident $resident, string $utc): ServiceRequest
    {
        $request = ServiceRequest::create([
            'resident_id' => $resident?->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Blocked road',
            'status' => 'Pending',
        ]);

        DB::table('tbl_service_request')
            ->where('request_id', $request->request_id)
            ->update(['created_at' => $utc]);

        return $request->fresh();
    }

    public function test_walk_in_and_app_filed_are_split_into_separate_series(): void
    {
        $this->requestAt($this->resident, '2026-09-05 00:00:00');
        $this->requestAt($this->resident, '2026-09-06 00:00:00');
        $this->requestAt(null, '2026-09-07 00:00:00');

        $adoption = $this->getJson('/api/admin/analytics?'.http_build_query([
            'preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]))->assertOk()->json()['adoption'];

        $byLabel = collect($adoption['series'])->keyBy('label');

        $this->assertSame(3, $adoption['total']);
        $this->assertSame([2], $byLabel['App']['data']);
        $this->assertSame([1], $byLabel['Walk-in']['data']);
    }

    public function test_a_barangay_filter_zeroes_the_walk_in_series_rather_than_hiding_the_section(): void
    {
        $this->requestAt($this->resident, '2026-09-05 00:00:00');
        $this->requestAt(null, '2026-09-06 00:00:00');

        $adoption = $this->getJson('/api/admin/analytics?'.http_build_query([
            'preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30',
            'barangay_id' => $this->resident->barangay_id,
        ]))->assertOk()->json()['adoption'];

        $byLabel = collect($adoption['series'])->keyBy('label');

        $this->assertSame(1, $adoption['total'], 'a walk-in carries no barangay and cannot match the filter');
        $this->assertArrayNotHasKey('Walk-in', $byLabel);
        $this->assertSame([1], $byLabel['App']['data']);
    }

    /**
     * stackByMonth() (shared with volumeByMonth/outcomeByMonth) truncates to
     * a UTC date before converting to Manila, so a row rolls up under its
     * UTC-date's month even when the window it was matched into is a Manila
     * one — pre-existing behaviour of the shared helper, not something this
     * section changes. This just confirms adoption inherits it rather than
     * silently diverging.
     */
    public function test_rolls_up_by_the_shared_helpers_month_bucketing(): void
    {
        $this->requestAt($this->resident, '2026-09-05 00:00:00');

        $adoption = $this->getJson('/api/admin/analytics?'.http_build_query([
            'preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]))->assertOk()->json()['adoption'];

        $this->assertSame(['Sep 2026'], $adoption['labels']);
        $this->assertSame(1, $adoption['total']);
    }
}
