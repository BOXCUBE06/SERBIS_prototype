<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The add_barangay_id migration backfills existing requests from each
 * resident's current barangay and leaves walk-ins null.
 *
 * Own migrate:fresh rather than RefreshDatabase: the rollback is DDL, which
 * implicit-commits on MySQL and would escape the per-test transaction (same as
 * DropAmbulanceColumnsMigrationTest).
 */
class ServiceRequestBarangayMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_03_090000_add_barangay_id_to_tbl_service_request.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION]);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_existing_requests_take_the_residents_barangay_and_walk_ins_stay_null(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        // Raw inserts: the model would write barangay_id, which does not exist yet.
        $filed = DB::table('tbl_service_request')->insertGetId([
            'resident_id' => $resident->getKey(),
            'status' => 'Resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $walkIn = DB::table('tbl_service_request')->insertGetId([
            'walk_in_name' => 'Counter visitor',
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION]);

        $this->assertSame($barangay->barangay_id, (int) DB::table('tbl_service_request')->where('request_id', $filed)->value('barangay_id'));
        $this->assertNull(DB::table('tbl_service_request')->where('request_id', $walkIn)->value('barangay_id'));
    }
}
