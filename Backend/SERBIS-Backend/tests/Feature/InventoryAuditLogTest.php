<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * tbl_system_logs coverage for Equipment and Barangay.
 *
 * Both models imported TracksHistory at the top of the file and never applied
 * it to the class, so every stock correction and every barangay rename wrote
 * no audit row at all. The import made the omission invisible: grepping for
 * the trait found both files, and grouping tbl_system_logs by auditable_type
 * returned six model classes with neither of these among them.
 *
 * Stock is the reason this matters. available_quantity is the number the
 * borrowing desk trusts, an admin can set it to anything, and without a log
 * there is nothing to say who changed it or what it was before.
 */
class InventoryAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function logsFor(string $model, int $id, string $action)
    {
        return DB::table('tbl_system_logs')
            ->where('auditable_type', $model)
            ->where('auditable_id', $id)
            ->where('action_type', $action)
            ->get();
    }

    public function test_adding_equipment_is_written_to_the_system_log(): void
    {
        $this->postJson('/api/equipments', [
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'status' => 'Available',
        ])->assertStatus(201);

        $equipment = Equipment::where('item_name', 'Rubber Boat')->first();
        $logs = $this->logsFor(Equipment::class, $equipment->equipment_id, 'created');

        $this->assertCount(1, $logs);
        $this->assertSame($this->admin->admin_id, (int) $logs->first()->admin_id);
        $this->assertStringContainsString('Rubber Boat', (string) $logs->first()->new_values);
    }

    public function test_a_stock_correction_records_the_quantity_before_and_after(): void
    {
        $equipment = Equipment::create([
            'item_name' => 'Life Vest',
            'total_quantity' => 20,
            'available_quantity' => 20,
            'status' => 'Available',
        ]);

        $this->putJson("/api/equipments/{$equipment->equipment_id}", [
            'total_quantity' => 20,
            'available_quantity' => 12,
        ])->assertStatus(200);

        $logs = $this->logsFor(Equipment::class, $equipment->equipment_id, 'updated');

        $this->assertCount(1, $logs);
        $old = json_decode((string) $logs->first()->old_values, true);
        $new = json_decode((string) $logs->first()->new_values, true);

        // Without the "before", a log says a number changed but not from what,
        // which is the only question anyone asks about missing stock.
        $this->assertSame(20, (int) $old['available_quantity']);
        $this->assertSame(12, (int) $new['available_quantity']);
    }

    public function test_removing_equipment_is_written_to_the_system_log(): void
    {
        $equipment = Equipment::create([
            'item_name' => 'Megaphone',
            'total_quantity' => 2,
            'available_quantity' => 2,
            'status' => 'Available',
        ]);
        $id = $equipment->equipment_id;

        $this->deleteJson("/api/equipments/{$id}")->assertStatus(200);

        $logs = $this->logsFor(Equipment::class, $id, 'deleted');

        $this->assertCount(1, $logs);
        // The row is gone from tbl_equipments, so the log is the only surviving
        // record of what the item was.
        $this->assertStringContainsString('Megaphone', (string) $logs->first()->old_values);
    }

    public function test_adding_a_barangay_is_written_to_the_system_log(): void
    {
        $this->postJson('/api/barangays', ['barangay_name' => 'Test Barangay'])
            ->assertStatus(201);

        $barangay = Barangay::where('barangay_name', 'Test Barangay')->first();
        $logs = $this->logsFor(Barangay::class, $barangay->barangay_id, 'created');

        $this->assertCount(1, $logs);
        $this->assertSame($this->admin->admin_id, (int) $logs->first()->admin_id);
    }

    public function test_renaming_a_barangay_records_the_old_name(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabain']);

        $this->putJson("/api/barangays/{$barangay->barangay_id}", [
            'barangay_name' => 'San Fabian',
        ])->assertStatus(200);

        $logs = $this->logsFor(Barangay::class, $barangay->barangay_id, 'updated');

        $this->assertCount(1, $logs);
        $old = json_decode((string) $logs->first()->old_values, true);
        $new = json_decode((string) $logs->first()->new_values, true);

        // Every resident and every blast is scoped by barangay, so a rename is
        // a change to what a dispatch record means.
        $this->assertSame('San Fabain', $old['barangay_name']);
        $this->assertSame('San Fabian', $new['barangay_name']);
    }

    public function test_removing_a_barangay_is_written_to_the_system_log(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'Dummy']);
        $id = $barangay->barangay_id;

        $this->deleteJson("/api/barangays/{$id}")->assertStatus(200);

        $this->assertCount(1, $this->logsFor(Barangay::class, $id, 'deleted'));
    }

    public function test_a_save_that_changes_only_timestamps_writes_no_log_row(): void
    {
        $equipment = Equipment::create([
            'item_name' => 'Chainsaw',
            'total_quantity' => 1,
            'available_quantity' => 1,
            'status' => 'Available',
        ]);

        $equipment->touch();

        // $ignoreLogging strips created_at/updated_at, and the trait drops an
        // update whose remaining diff is empty. Otherwise the Logs page fills
        // with rows recording that nothing happened.
        $this->assertCount(0, $this->logsFor(Equipment::class, $equipment->equipment_id, 'updated'));
    }
}
