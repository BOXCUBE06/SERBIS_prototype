<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The pre-check in 2026_10_02_100000. Runs its own migrate:fresh (DDL commits
 * implicitly on MySQL/MariaDB, so RefreshDatabase's transaction cannot wrap
 * it), same as AmbulanceBookingsMigrationTest.
 */
class ActiveVehicleUniqueMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_02_100000_add_active_vehicle_unique_to_tbl_service_request.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION]);
    }

    protected function tearDown(): void
    {
        // Tells the next RefreshDatabase class to migrate fresh for itself.
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_the_pre_check_throws_listing_the_vehicle_and_request_ids(): void
    {
        $service = Service::create(['service_name' => 'Road Clearing', 'description' => 'Debris removal.']);
        $unit = Vehicle::create(['unit_identifier' => 'RES-01', 'type' => 'Rescue Vehicle', 'status' => 'Dispatched']);

        $ids = collect([1, 2])->map(fn () => DB::table('tbl_service_request')->insertGetId([
            'walk_in_name' => 'Juan Dela Cruz',
            'service_id' => $service->service_id,
            'description' => 'Test',
            'status' => 'Responding',
            'vehicle_id' => $unit->vehicle_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $migration = require base_path(self::MIGRATION);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("vehicle_id {$unit->vehicle_id} (requests: {$ids->implode(', ')})");

        $migration->up();
    }
}
