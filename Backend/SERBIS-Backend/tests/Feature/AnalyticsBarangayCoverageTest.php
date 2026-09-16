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
 * GET /api/admin/analytics — section 9, barangay residents vs requests.
 *
 * The case that matters: a barangay with residents but zero requests in the
 * window, and a barangay with neither, must both still appear. A query built
 * from the requests outward can only ever list a barangay that has at least
 * one — that is the bug this section exists to not have.
 */
class AnalyticsBarangayCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        $this->service = Service::create(['service_name' => 'Road Clearing', 'description' => 'Clear a blocked road']);

        $this->actingAs($this->admin);
    }

    private function resident(Barangay $barangay, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'email_address' => $phone.'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
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

    private function coverage(array $query = []): array
    {
        return $this->getJson('/api/admin/analytics?'.http_build_query($query))
            ->assertOk()
            ->json()['barangayCoverage'];
    }

    public function test_a_barangay_with_residents_but_no_requests_still_appears(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->resident($barangay, '09171111111');
        $this->resident($barangay, '09172222222');

        $coverage = $this->coverage(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $row = collect($coverage['barangays'])->firstWhere('name', 'San Fabian');

        $this->assertNotNull($row);
        $this->assertSame(2, $row['residents']);
        $this->assertSame(0, $row['requests']);
    }

    public function test_a_barangay_with_neither_still_appears(): void
    {
        Barangay::create(['barangay_name' => 'Ghost Barangay']);

        $coverage = $this->coverage();

        $row = collect($coverage['barangays'])->firstWhere('name', 'Ghost Barangay');

        $this->assertNotNull($row);
        $this->assertSame(0, $row['residents']);
        $this->assertSame(0, $row['requests']);
    }

    public function test_requests_are_windowed_but_residents_are_not(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = $this->resident($barangay, '09171111111');

        // Filed outside the queried window.
        $this->requestAt($resident, '2026-08-01 00:00:00');

        $coverage = $this->coverage(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);
        $row = collect($coverage['barangays'])->firstWhere('name', 'San Fabian');

        $this->assertSame(1, $row['residents'], 'a resident account does not expire outside the request window');
        $this->assertSame(0, $row['requests'], 'the request was filed outside the window');
    }

    public function test_the_walk_in_row_and_totals_reconcile(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = $this->resident($barangay, '09171111111');

        $this->requestAt($resident, now()->subDay()->toDateTimeString());
        $this->requestAt(null, now()->subDay()->toDateTimeString());

        $coverage = $this->coverage(['preset' => 'year']);

        $this->assertSame(1, $coverage['walkIn']);
        $this->assertSame(2, $coverage['totalRequests']);
        $this->assertSame(1, $coverage['totalResidents']);
    }
}
