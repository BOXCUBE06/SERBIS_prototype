<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * GET /admin/pulse: what the panel polls to notice a list changed. Counts per
 * kind, split the way the boards are, and only for the sections held.
 */
class PulseTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private Resident $resident;

    private Service $ambulance;

    private Service $roadClearing;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->resident = $this->makeResident($barangay->barangay_id, '+639171234567', 'Active');
        $this->makeResident($barangay->barangay_id, '+639171234568', 'Inactive');
        $this->makeResident($barangay->barangay_id, '+639171234569', 'Inactive', Resident::TYPE_ORGANIZATION);
        $this->makeResident($barangay->barangay_id, '+639171234570', 'Deactivated');

        $this->ambulance = Service::create(['service_name' => 'Ambulance/Medical Response']);
        $this->roadClearing = Service::create(['service_name' => 'Road Clearing']);

        $this->file($this->ambulance->service_id);
        $this->file($this->roadClearing->service_id);
        $this->file(null);
    }

    private function makeResident(int $barangayId, string $phone, string $status, ?string $type = null): Resident
    {
        $resident = Resident::create([
            'barangay_id' => $barangayId,
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'phone_number' => $phone,
            'password' => Hash::make('password123'),
            'status' => $status,
        ]);

        if ($type) {
            $resident->forceFill(['account_type' => $type])->save();
        }

        return $resident;
    }

    private function file(?int $serviceId, string $status = 'Pending'): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->resident_id,
            'service_id' => $serviceId,
            'description' => 'Filed from the app',
            'status' => $status,
        ]);
    }

    private function borrow(string $status): EquipmentBorrowing
    {
        $equipment = Equipment::firstOrCreate(['item_name' => 'Generator'], ['total_quantity' => 5, 'available_quantity' => 5]);

        return EquipmentBorrowing::create([
            'resident_id' => $this->resident->resident_id,
            'equipment_id' => $equipment->equipment_id,
            'quantity' => 1,
            'status' => $status,
        ]);
    }

    public function test_counts_each_kind_and_pending_accounts(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $pulse = $this->getJson('/api/admin/pulse')->assertOk()->json();

        $this->assertSame(2, $pulse['requests']['count'], 'Road Clearing and the "Others" request');
        $this->assertSame(1, $pulse['ambulance']['count']);
        $this->assertSame(0, $pulse['trips']['count']);
        $this->assertSame(0, $pulse['borrowings']['count']);
        $this->assertSame(4, $pulse['residents']['count']);
        $this->assertSame(2, $pulse['residents']['pending']);
        $this->assertSame(1, $pulse['residents']['pending_organizations']);
        // The form the list endpoint serialises updated_at in, so the panel can
        // compare the two directly.
        $newest = ServiceRequest::where('service_id', '!=', $this->ambulance->service_id)->orWhereNull('service_id')
            ->get()->map(fn ($r) => $r->toArray()['updated_at'])->max();
        $this->assertSame($newest, $pulse['requests']['latest']);
    }

    public function test_waiting_counts_only_what_needs_a_staff_decision(): void
    {
        $vehicle = Vehicle::create(['unit_identifier' => 'Ambulance 1', 'type' => 'Ambulance', 'status' => 'Available']);

        // Ambulance: setUp filed one Pending. Plus a Booked with no unit
        // (waiting) and a Booked with a unit and a Responding one (not waiting).
        $this->file($this->ambulance->service_id, 'Booked');
        $this->file($this->ambulance->service_id, 'Booked')->forceFill(['vehicle_id' => $vehicle->vehicle_id])->save();
        $this->file($this->ambulance->service_id, 'Responding');

        // Resident Requests: setUp filed two Pending; a Resolved one is not waiting.
        $this->file($this->roadClearing->service_id, 'Resolved');

        $this->borrow('Pending');
        $this->borrow('Pending');
        $this->borrow('Approved');

        Sanctum::actingAs($this->makeStaff());
        $pulse = $this->getJson('/api/admin/pulse')->assertOk()->json();

        $this->assertSame(2, $pulse['requests']['waiting']);
        $this->assertSame(2, $pulse['ambulance']['waiting'], 'Pending, plus Booked without a unit');
        $this->assertSame(2, $pulse['borrowings']['waiting']);
        $this->assertArrayNotHasKey('waiting', $pulse['trips']);
    }

    public function test_waiting_counts_follow_the_sections_held(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::BORROWINGS]));

        $pulse = $this->getJson('/api/admin/pulse')->assertOk()->json();

        $this->assertSame(['borrowings'], array_keys($pulse));
        $this->assertSame(0, $pulse['borrowings']['waiting'], 'zero, not null, on an empty table');
    }

    public function test_a_new_request_moves_its_kind_only(): void
    {
        Sanctum::actingAs($this->makeStaff());
        $before = $this->getJson('/api/admin/pulse')->json();

        $this->file($this->roadClearing->service_id);

        $after = $this->getJson('/api/admin/pulse')->json();
        $this->assertSame($before['requests']['count'] + 1, $after['requests']['count']);
        $this->assertSame($before['ambulance'], $after['ambulance']);
    }

    public function test_only_the_sections_held_are_reported(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::AMBULANCE]));

        $pulse = $this->getJson('/api/admin/pulse')->assertOk()->json();

        $this->assertSame(['ambulance', 'trips'], array_keys($pulse));
    }

    public function test_pending_accounts_need_the_accounts_section(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::DASHBOARD, AdminSections::REQUESTS]));

        $pulse = $this->getJson('/api/admin/pulse')->assertOk()->json();

        $this->assertArrayNotHasKey('residents', $pulse);
        $this->assertArrayHasKey('requests', $pulse);
    }

    public function test_an_account_with_none_of_its_sections_is_refused(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::HOTLINES]));

        $this->getJson('/api/admin/pulse')->assertForbidden();
    }
}
