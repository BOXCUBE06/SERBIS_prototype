<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // 1. Independent Tables
            BarangaySeeder::class,
            AdminSeeder::class,
            ServiceSeeder::class,
            EquipmentSeeder::class,
            VehicleSeeder::class,
            
            // 2. Dependent Tables (Require Foreign Keys)
            ResidentSeeder::class, // Requires tbl_barangay
            InfoMaterialSeeder::class, // Requires tbl_user (uploader_id)
            
            // 3. Relational/Analytics Tables
            ServiceRequestSeeder::class,
            EquipmentBorrowingSeeder::class,
        ]);
    }
}