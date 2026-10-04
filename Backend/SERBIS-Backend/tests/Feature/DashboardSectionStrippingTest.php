<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\SystemLog;
use App\Support\AdminSections;
use App\Support\AnalyticsCache;
use App\Support\ReminderFollowUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * GET /api/admin/dashboard is one cached payload for every admin. What an admin
 * sees of it is cut down after the cache read: every list that names a person.
 */
class DashboardSectionStrippingTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private const PRIVATE_NAMES = ['Maria Cruz'];

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'phone_number' => '+639171234567',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $ambulance = Service::create(['service_name' => 'Ambulance/Medical Response']);
        $road = Service::create(['service_name' => 'Road Clearing']);

        foreach ([[$ambulance, 'Ambulance'], [$road, 'Road']] as [$service, $description]) {
            ServiceRequest::create([
                'resident_id' => $resident->resident_id,
                'service_id' => $service->service_id,
                'description' => $description,
                'status' => 'Pending',
            ]);
        }

        $equipment = Equipment::create([
            'item_name' => 'Generator',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);

        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $resident->resident_id,
            'equipment_id' => $equipment->equipment_id,
            'quantity' => 1,
            'purpose' => 'Drill',
            'status' => 'Pending',
        ]);

        SystemLog::create([
            'action_type' => 'created',
            'auditable_type' => ServiceRequest::class,
            'auditable_id' => 1,
        ]);
        ReminderFollowUp::record($borrowing, ReminderFollowUp::DUE_REMINDER);
    }

    private function dashboardAs(array $sections)
    {
        Sanctum::actingAs($this->makeLimitedStaff($sections, 'a'.count($sections).'-'.md5(json_encode($sections)).'@test.local'));

        return $this->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_an_admin_holding_only_the_dashboard_sees_the_aggregates_and_nobody_named(): void
    {
        $response = $this->dashboardAs([AdminSections::DASHBOARD]);

        $this->assertSame([], $response->json('systemLogs'));
        $this->assertSame([], $response->json('followUps'));

        // Aggregates with no one in them stay.
        $this->assertNotEmpty($response->json('charts'));

        foreach (self::PRIVATE_NAMES as $name) {
            $this->assertStringNotContainsString($name, $response->getContent());
        }
        $this->assertStringNotContainsString('+639171234567', $response->getContent());
    }

    public function test_the_activity_feed_needs_the_logs_section(): void
    {
        $this->assertSame([], $this->dashboardAs([AdminSections::DASHBOARD, AdminSections::BORROWINGS])->json('systemLogs'));
        $this->assertNotEmpty($this->dashboardAs([AdminSections::DASHBOARD, AdminSections::LOGS])->json('systemLogs'));
    }

    public function test_the_follow_up_calls_need_borrowings_or_ambulance(): void
    {
        $this->assertSame([], $this->dashboardAs([AdminSections::DASHBOARD, AdminSections::LOGS])->json('followUps'));
        $this->assertSame([], $this->dashboardAs([AdminSections::DASHBOARD, AdminSections::REQUESTS])->json('followUps'));

        $borrowings = $this->dashboardAs([AdminSections::DASHBOARD, AdminSections::BORROWINGS])->json('followUps');
        $this->assertCount(1, $borrowings);
        $this->assertSame('Maria Cruz', $borrowings[0]['name']);

        $this->assertCount(1, $this->dashboardAs([AdminSections::DASHBOARD, AdminSections::AMBULANCE])->json('followUps'));
    }

    public function test_a_super_admin_sees_everything(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        $this->assertNotEmpty($response->json('systemLogs'));
        $this->assertCount(1, $response->json('followUps'));
    }

    public function test_an_account_with_no_permission_list_sees_everything_it_did_before(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        $this->assertNotEmpty($response->json('systemLogs'));
        $this->assertCount(1, $response->json('followUps'));
    }

    public function test_one_admins_narrow_view_does_not_narrow_the_next_admins(): void
    {
        // The cache stays a single whole entry. If the stripped copy were what
        // got stored, the second admin would inherit the first one's gaps.
        $this->dashboardAs([AdminSections::DASHBOARD]);
        $this->assertTrue(Cache::has(AnalyticsCache::DASHBOARD_KEY), 'the payload should be cached');

        Sanctum::actingAs($this->makeSuperAdmin());
        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        $this->assertNotEmpty($response->json('systemLogs'));
        $this->assertCount(1, $response->json('followUps'));
    }

    public function test_the_dashboard_itself_still_needs_its_section(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS, AdminSections::LOGS]));

        $this->getJson('/api/admin/dashboard')->assertStatus(403)->assertJsonPath('code', 'section_forbidden');
    }
}
