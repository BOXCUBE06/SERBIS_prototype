<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The production entry point: `php artisan db:seed --class=ProductionSeeder`.
 *
 * DatabaseSeeder is the local one. It calls everything, including the fake
 * residents, requests and borrowings that exist to give the dashboard something
 * to render, and it is not what a real deployment should ever run.
 *
 * Production seeds exactly this, in order: barangays, the super admin, the
 * three barangay accounts, services, equipment, vehicles, the text blast code
 * and the ambulance destinations. Everything else starts empty and fills up
 * from real use.
 *
 * Re-running this on a redeploy is safe. The catalogue seeders only create
 * rows that are missing (matched by code, item name or unit) and never update
 * existing ones; the admin and barangay account seeders skip what exists, and
 * throw only when an account is missing and its env var is not set; the blast
 * code and destination seeders skip a non-empty table.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Barangays: all 64 come from the 2026_09_30 add_all_echague_barangays migration, so BarangaySeeder is not called.
            ProductionAdminSeeder::class,
            ProductionBarangayAccountSeeder::class,
            ServiceSeeder::class,
            EquipmentSeeder::class,
            VehicleSeeder::class,
            ProductionSmsBlastCodeSeeder::class,
            AmbulanceDestinationSeeder::class,
        ]);
    }
}
