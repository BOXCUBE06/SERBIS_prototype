<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class MockRequestSeeder extends Seeder
{
    public function run()
    {
        // This seeder TRUNCATES tbl_service_request and tbl_equipment_borrowings
        // before inserting fifty fabricated rows. Run once against a live
        // database and every real request the MDRRMO has taken is gone, with no
        // undo. Nothing else in this repository destroys data.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'MockRequestSeeder skipped: refuses to truncate and re-seed outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        $faker = Faker::create();

        // 1. Fetch existing foreign keys
        $residentIds = Resident::pluck('resident_id')->toArray();
        $serviceIds = Service::pluck('service_id')->toArray();
        $equipmentIds = Equipment::pluck('equipment_id')->toArray();

        // Prevent seeding if core data is missing
        if (empty($residentIds) || empty($serviceIds) || empty($equipmentIds)) {
            $this->command->error('You must have at least 1 resident, 1 service, and 1 equipment in the database to run this seeder.');

            return;
        }

        // 2. Safely wipe the old disconnected data
        Schema::disableForeignKeyConstraints();
        ServiceRequest::truncate();
        EquipmentBorrowing::truncate();
        Schema::enableForeignKeyConstraints();

        $this->command->info('Old records cleared. Generating new connected mock data...');

        // 3. Generate 50 Service Requests
        $serviceStatuses = ['Pending', 'Responding', 'Resolved', 'Cancelled', 'Disapproved'];

        for ($i = 0; $i < 50; $i++) {
            $date = $faker->dateTimeBetween('-3 months', 'now');

            ServiceRequest::create([
                'resident_id' => $faker->randomElement($residentIds),
                'service_id' => $faker->randomElement($serviceIds),
                'status' => $faker->randomElement($serviceStatuses),
                'description' => $faker->sentence(6),
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }

        // 4. Generate 50 Equipment Borrowings
        $borrowStatuses = ['Pending', 'Approved', 'Released', 'Returned', 'Denied'];

        for ($i = 0; $i < 50; $i++) {
            $status = $faker->randomElement($borrowStatuses);

            // Scatter the dates across the last 3 months so the dashboard charts look active
            $createdDate = Carbon::instance($faker->dateTimeBetween('-3 months', '-1 week'));
            $releasedDate = null;
            $returnedDate = null;

            // Logically attach timestamps based on the status
            if (in_array($status, ['Released', 'Returned'])) {
                $releasedDate = (clone $createdDate)->addDays(rand(1, 2));
            }
            if ($status === 'Returned') {
                $returnedDate = (clone $releasedDate)->addDays(rand(1, 5));
            }

            EquipmentBorrowing::create([
                'resident_id' => $faker->randomElement($residentIds),
                'equipment_id' => $faker->randomElement($equipmentIds),
                'quantity' => rand(1, 3),
                'status' => $status,
                'released_at' => $releasedDate,
                'returned_at' => $returnedDate,
                'created_at' => $createdDate,
                'updated_at' => $returnedDate ?? ($releasedDate ?? $createdDate),
            ]);
        }

        $this->command->info('Successfully seeded 50 Service Requests and 50 Borrow Requests!');
    }
}
