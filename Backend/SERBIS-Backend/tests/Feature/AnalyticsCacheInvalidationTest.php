<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\AnalyticsCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The dashboard payload is cached for five minutes. Before
 * InvalidatesAnalyticsCache that TTL was the only invalidation, so a status
 * change could take five minutes to reach the panel and nothing on screen
 * said the number was stale.
 *
 * CACHE_STORE is `database`, which supports neither tags nor pattern deletes,
 * so analytics keys carry a version that flush() bumps. The dashboard key is
 * a single fixed string and is simply forgotten.
 *
 * The load-bearing test is the first one: it reads the endpoint twice and
 * requires the second read to reflect a write made in between. Removing the
 * trait from ServiceRequest makes it fail.
 */
class AnalyticsCacheInvalidationTest extends TestCase
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

    private function requestTotal(): int
    {
        $data = $this->getJson('/api/admin/dashboard')->assertOk()->json('charts.pieByPeriod.all.services.data');

        return array_sum($data);
    }

    public function test_a_new_request_is_visible_on_the_next_dashboard_read(): void
    {
        $payload = [
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Blocked road',
            'status' => 'Pending',
        ];
        ServiceRequest::create($payload);

        // Warms the cache. Without invalidation this value is what the second
        // read returns as well.
        $this->assertSame(1, $this->requestTotal());

        ServiceRequest::create($payload);

        $this->assertSame(2, $this->requestTotal(), 'the dashboard must not serve a count from before the write');
    }

    public function test_a_write_to_any_counted_model_invalidates_the_dashboard_key(): void
    {
        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->assertTrue(Cache::has(AnalyticsCache::DASHBOARD_KEY), 'the endpoint should have cached a payload');

        // Resident is in the model set because the dashboard counts residents.
        $this->resident->update(['status' => 'Inactive']);

        $this->assertFalse(Cache::has(AnalyticsCache::DASHBOARD_KEY));
    }

    public function test_flush_strands_versioned_analytics_keys(): void
    {
        $before = AnalyticsCache::key('example');
        Cache::put($before, 'stale', 300);

        AnalyticsCache::flush();

        $after = AnalyticsCache::key('example');

        $this->assertNotSame($before, $after, 'the version must change so old keys are unreachable');
        $this->assertFalse(Cache::has($after), 'the new key starts empty rather than inheriting the old value');
    }

    /**
     * A fresh install has no counter row at all, and the first flush has to
     * move the version anyway — otherwise every analytics key built before it
     * stays reachable.
     *
     * This deliberately does not go through Cache::increment(). The suite
     * runs the `array` store and production runs `database` (phpunit.xml vs
     * .env), and the two return different things for a missing key, so a test
     * written against either one's semantics would prove nothing about the
     * other. Asserting on the version the app actually reads back is store-
     * agnostic.
     */
    public function test_the_version_counter_advances_from_a_cold_cache(): void
    {
        Cache::flush();

        $this->assertSame(1, AnalyticsCache::version(), 'an absent counter reads as version 1');

        AnalyticsCache::flush();
        $this->assertSame(2, AnalyticsCache::version());

        AnalyticsCache::flush();
        $this->assertSame(3, AnalyticsCache::version());
    }
}
