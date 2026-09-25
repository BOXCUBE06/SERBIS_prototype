<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Database\Seeders\EchagueBarangaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/** GET /api/admin/analytics/barangays — the map's hover card. */
class AnalyticsBarangayStatsTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Service::create(['service_name' => 'Road Clearing', 'description' => 'Clear a blocked road']);
        $this->actingAs($this->makeStaff());
        $this->seed(EchagueBarangaySeeder::class);
    }

    private function resident(string $code, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => Barangay::where('psgc_code', $code)->value('barangay_id'),
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    private function request(?Resident $resident, string $status): void
    {
        ServiceRequest::create([
            'resident_id' => $resident?->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Blocked road',
            'status' => $status,
        ]);
    }

    private function stats(): Collection
    {
        return collect($this->getJson('/api/admin/analytics/barangays')->assertOk()->json('data'))->keyBy('psgc_code');
    }

    public function test_returns_every_barangay_including_empty_ones(): void
    {
        $rows = $this->stats();

        $this->assertCount(64, $rows);
        $this->assertSame(['name' => 'Angoluan', 'residents_count' => 0, 'pending_requests_count' => 0], [
            'name' => $rows['0203112001']['name'],
            'residents_count' => $rows['0203112001']['residents_count'],
            'pending_requests_count' => $rows['0203112001']['pending_requests_count'],
        ]);
    }

    public function test_counts_residents_and_only_pending_requests_per_barangay(): void
    {
        $fabian = $this->resident('0203112045', '09171111111');
        $this->resident('0203112045', '09172222222');
        $miguel = $this->resident('0203112049', '09173333333');

        $this->request($fabian, 'Pending');
        $this->request($fabian, 'Pending');
        $this->request($fabian, 'Resolved');
        $this->request($miguel, 'Booked');
        $this->request(null, 'Pending'); // walk-in: no barangay, in no row

        $rows = $this->stats();

        $this->assertSame(2, $rows['0203112045']['residents_count']);
        $this->assertSame(2, $rows['0203112045']['pending_requests_count']);
        $this->assertSame(1, $rows['0203112049']['residents_count']);
        $this->assertSame(0, $rows['0203112049']['pending_requests_count']);
        $this->assertSame(2, $rows->sum('pending_requests_count'));
    }

    public function test_an_admin_without_the_analytics_section_is_refused(): void
    {
        $this->actingAs($this->makeStaff('nolist@test.local', ['permissions' => ['requests']]));

        $this->getJson('/api/admin/analytics/barangays')->assertForbidden();
    }
}
