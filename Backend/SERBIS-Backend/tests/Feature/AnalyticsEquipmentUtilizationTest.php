<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/analytics — section 6, equipment utilization.
 *
 * The one case that matters: an item nobody has ever borrowed must still
 * appear, at zero. A query that starts at tbl_equipment_borrowing and groups
 * by equipment_id can only ever list items borrowed at least once — that is
 * the bug this section exists to not have.
 */
class AnalyticsEquipmentUtilizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

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

        $this->actingAs($this->admin);
    }

    private function borrowAt(Equipment $equipment, string $utc, int $quantity = 1, string $status = 'Released'): EquipmentBorrowing
    {
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $equipment->getKey(),
            'quantity' => $quantity,
            'status' => $status,
        ]);

        DB::table('tbl_equipment_borrowing')
            ->where('borrow_id', $borrowing->borrow_id)
            ->update(['created_at' => $utc]);

        return $borrowing->fresh();
    }

    private function report(array $query = []): array
    {
        return $this->getJson('/api/admin/analytics?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    public function test_an_item_never_borrowed_still_appears_at_zero(): void
    {
        Equipment::create(['item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available']);

        $items = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'])['equipmentUtilization']['items'];

        $this->assertCount(1, $items);
        $this->assertSame('Rubber Boat', $items[0]['label']);
        $this->assertSame(0, $items[0]['timesBorrowed']);
        $this->assertSame(0, $items[0]['quantityBorrowed']);
    }

    public function test_times_and_quantity_borrowed_are_counted_within_the_window(): void
    {
        $boat = Equipment::create(['item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available']);
        $chainsaw = Equipment::create(['item_name' => 'Chainsaw', 'total_quantity' => 2, 'available_quantity' => 2, 'status' => 'Available']);

        $this->borrowAt($boat, '2026-09-05 00:00:00', 2);
        $this->borrowAt($boat, '2026-09-10 00:00:00', 1);
        // Outside the window — must not be counted.
        $this->borrowAt($boat, '2026-08-01 00:00:00', 5);
        // A different item never borrowed in-window.
        $this->borrowAt($chainsaw, '2026-08-01 00:00:00', 1);

        $report = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'])['equipmentUtilization'];

        $byLabel = collect($report['items'])->keyBy('label');

        $this->assertSame(2, $byLabel['Rubber Boat']['timesBorrowed']);
        $this->assertSame(3, $byLabel['Rubber Boat']['quantityBorrowed']);
        $this->assertSame(0, $byLabel['Chainsaw']['timesBorrowed']);
        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['zeroBorrowCount']);
    }

    public function test_only_loans_that_left_the_shelf_are_counted(): void
    {
        $boat = Equipment::create(['item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available']);

        $this->borrowAt($boat, '2026-09-05 00:00:00', 1, 'Released');
        $this->borrowAt($boat, '2026-09-06 00:00:00', 1, 'Returned');
        foreach (['Pending', 'Approved', 'Denied', 'Cancelled'] as $status) {
            $this->borrowAt($boat, '2026-09-07 00:00:00', 1, $status);
        }

        $report = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'])['equipmentUtilization'];

        $this->assertSame(2, $report['items'][0]['timesBorrowed']);
    }

    public function test_a_status_change_is_visible_on_the_next_read(): void
    {
        $boat = Equipment::create(['item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available']);

        $before = $this->report(['preset' => 'year'])['equipmentUtilization']['total'];
        $this->assertSame(0, $before);

        $this->borrowAt($boat, now()->subDay()->toDateTimeString());

        $after = $this->report(['preset' => 'year'])['equipmentUtilization']['total'];
        $this->assertSame(1, $after, 'the report must not serve a pre-write payload');
    }
}
