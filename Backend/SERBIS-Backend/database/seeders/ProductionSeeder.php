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
 * What is here is the data the MDRRMO supplied — their barangays, services,
 * equipment and vehicles — plus the single admin account without which nobody
 * can log into the panel. Everything else starts empty and fills up from real
 * use.
 *
 * Every seeder below skips a table that already has rows, so re-running this on
 * a redeploy is safe and does nothing.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BarangaySeeder::class,
            ProductionAdminSeeder::class,
            ServiceSeeder::class,
            EquipmentSeeder::class,
            VehicleSeeder::class,

            // After ServiceSeeder: keyed on the English service name.
            ServiceTranslationSeeder::class,
        ]);
    }
}
