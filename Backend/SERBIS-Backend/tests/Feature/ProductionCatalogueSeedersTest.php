<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Service;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\ProductionAdminSeeder;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\VehicleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The catalogue seeders run on every production boot: create-only, never update. */
class ProductionCatalogueSeedersTest extends TestCase
{
    use RefreshDatabase;

    private function seedAll(): void
    {
        $this->seed([ServiceSeeder::class, EquipmentSeeder::class, VehicleSeeder::class]);
    }

    public function test_running_twice_gives_the_same_catalogue(): void
    {
        $this->seedAll();
        $this->seedAll();

        $this->assertSame(9, Equipment::count());
        $this->assertSame(51, Equipment::where('item_name', 'Cot Bed')->value('available_quantity'));
        $this->assertSame(19, Vehicle::count());
        $this->assertSame(10, Vehicle::where('type', 'Ambulance')->count());
        $this->assertSame(5, Vehicle::where('type', 'Boat')->count());
        $this->assertSame(9, Service::count());
        $this->assertFalse(Service::where('code', 'animal-rescue')->exists());
    }

    public function test_a_second_run_leaves_edited_rows_untouched(): void
    {
        $this->seedAll();
        Service::where('code', 'road-clearing')->update(['service_name' => 'Road Clearing Operations']);
        Equipment::where('item_name', 'Cot Bed')->update(['total_quantity' => 40, 'available_quantity' => 12, 'status' => 'Unavailable']);
        Vehicle::where('unit_identifier', 'Ambulance 1')->update(['type' => 'Rescue Vehicle', 'status' => 'Maintenance']);
        Equipment::where('item_name', 'Megaphone')->delete();

        $this->seedAll();

        $this->assertSame(1, Service::where('code', 'road-clearing')->count());
        $this->assertSame('Road Clearing Operations', Service::where('code', 'road-clearing')->value('service_name'));
        $cot = Equipment::where('item_name', 'Cot Bed')->first();
        $this->assertSame([40, 12, 'Unavailable'], [$cot->total_quantity, $cot->available_quantity, $cot->status]);
        $amb = Vehicle::where('unit_identifier', 'Ambulance 1')->first();
        $this->assertSame(['Rescue Vehicle', 'Maintenance'], [$amb->type, $amb->status]);
        // Missing rows are still created.
        $this->assertTrue(Equipment::where('item_name', 'Megaphone')->exists());
    }

    public function test_rows_not_in_the_list_are_left_to_the_migration(): void
    {
        Equipment::create(['item_name' => 'Nebulizer', 'total_quantity' => 5, 'available_quantity' => 5, 'status' => 'Available']);
        Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);

        $this->seedAll();

        $this->assertTrue(Equipment::where('item_name', 'Nebulizer')->exists());
        $this->assertTrue(Vehicle::where('unit_identifier', 'AMB-01')->exists());
    }

    public function test_retired_service_side_rows_are_cleared_without_the_service_row(): void
    {
        DB::table('tbl_service_audience')->insertOrIgnore(['service_code' => 'animal-rescue', 'account_type' => 'head_of_family']);
        DB::table('tbl_service_vehicle_types')->insertOrIgnore(['service_code' => 'animal-rescue', 'vehicle_type' => 'Rescue Vehicle']);

        $this->seedAll();

        $this->assertFalse(DB::table('tbl_service_audience')->where('service_code', 'animal-rescue')->exists());
        $this->assertFalse(DB::table('tbl_service_vehicle_types')->where('service_code', 'animal-rescue')->exists());
    }

    public function test_cleanup_migration_retires_old_rows_but_skips_open_work(): void
    {
        $this->seedAll();
        $unused = Equipment::create(['item_name' => 'Nebulizer', 'total_quantity' => 5, 'available_quantity' => 5, 'status' => 'Available']);
        $oldVan = Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);
        $dispatched = Vehicle::create(['unit_identifier' => 'AMB-02', 'type' => 'Ambulance', 'status' => 'Dispatched']);
        $trip = Vehicle::create(['unit_identifier' => 'AMB-03', 'type' => 'Ambulance', 'status' => 'Available']);
        DB::table('tbl_conduction_requests')->insert(['vehicle_id' => $trip->vehicle_id, 'patient_name' => 'P', 'patient_address' => 'A', 'patient_contact_number' => '09171234567', 'medical_diagnosis' => 'D', 'origin' => 'O', 'destination' => 'D', 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_10_07_090000_retire_old_equipment_and_vehicles.php'))->up();

        $this->assertFalse(Equipment::whereKey($unused->equipment_id)->exists());
        $this->assertFalse(Vehicle::whereKey($oldVan->vehicle_id)->exists());
        $this->assertSame('Dispatched', Vehicle::whereKey($dispatched->vehicle_id)->value('status'));
        $this->assertSame('Available', Vehicle::whereKey($trip->vehicle_id)->value('status'));
        $this->assertSame(9, Equipment::count());
    }

    public function test_admin_seeder_skips_when_an_admin_exists(): void
    {
        $_SERVER['ADMIN_SEED_PASSWORD'] = 'SeedPass123!';
        DB::table('tbl_user')->insert([
            'first_name' => 'Other', 'last_name' => 'Admin', 'role' => 'Admin', 'status' => 'Active',
            'username' => 'otheradmin', 'email_address' => 'other@example.com', 'password' => Hash::make('x'),
        ]);

        try {
            $this->seed(ProductionAdminSeeder::class);
        } finally {
            unset($_SERVER['ADMIN_SEED_PASSWORD']);
        }

        $this->assertSame(1, DB::table('tbl_user')->count());
        $this->assertSame('otheradmin', DB::table('tbl_user')->value('username'));
    }

    public function test_admin_seeder_creates_a_super_admin(): void
    {
        $_SERVER['ADMIN_SEED_PASSWORD'] = 'SeedPass123!';

        try {
            $this->seed(ProductionAdminSeeder::class);
        } finally {
            unset($_SERVER['ADMIN_SEED_PASSWORD']);
        }

        $this->assertSame(1, (int) DB::table('tbl_user')->where('username', 'jilmarferrer29')->value('is_super_admin'));
    }

    public function test_production_seeds_only_the_production_set(): void
    {
        $env = ['ADMIN_SEED_PASSWORD' => 'SeedPass123!', 'SMS_BLAST_CODE_SEED' => '123456'];
        $_SERVER = $env + $_SERVER;
        $this->app['env'] = 'production';

        try {
            // A plain db:seed on production must route to ProductionSeeder only.
            $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        } finally {
            foreach (array_keys($env) as $key) {
                unset($_SERVER[$key]);
            }
        }

        $this->assertSame(1, DB::table('tbl_user')->count());
        $this->assertSame(0, DB::table('tbl_residents')->where('account_type', 'barangay')->count());
        $this->assertSame(0, DB::table('tbl_residents')->count());
        $this->assertSame(9, Service::count());
        $this->assertSame(9, Equipment::count());
        $this->assertSame(19, Vehicle::count());
        $this->assertSame(1, DB::table('tbl_sms_blast_code')->count());
        $this->assertSame(['Echague District Hospital', 'Isabela Southern Specialist Hospital Inc.', 'Southern Isabela Medical Center'], DB::table('tbl_ambulance_destinations')->orderBy('id')->pluck('name')->all());
        $this->assertSame(0, DB::table('tbl_service_request')->count());
    }
}
