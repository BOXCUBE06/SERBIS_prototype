<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ServiceSeeder extends Seeder
{
    /**
     * The ten services the MDRRMO offers. Runs on production; this is their
     * data, not test data.
     *
     * Written through the model, not DB::table()->insert(). `tbl_services.code`
     * is NOT NULL and is filled by a `creating` hook on App\Models\Service,
     * and a query-builder insert fires no Eloquent events — so the bulk insert
     * this used to do now fails outright with "Field 'code' doesn't have a
     * default value", on a fresh database, at deploy time.
     */
    public function run(): void
    {
        // service_name carries no unique constraint, so a second run appends a
        // duplicate set and every service appears twice in the mobile picker.
        if (DB::table('tbl_services')->exists()) {
            $this->command?->warn('ServiceSeeder skipped: tbl_services is not empty.');

            return;
        }

        Schema::disableForeignKeyConstraints();

        $services = [
            ['service_name' => 'Flood Evacuation', 'description' => 'Assistance and evacuation services during floods.'],
            ['service_name' => 'Fire Rescue', 'description' => 'Emergency fire rescue operations.'],
            ['service_name' => 'Ambulance/Medical Response', 'description' => 'Emergency medical response and ambulance services.'],
            ['service_name' => 'Relief Goods Distribution', 'description' => 'Distribution of essential relief goods during disasters.'],
            ['service_name' => 'Road Clearing', 'description' => 'Clearing roads of debris and obstacles after natural calamities.'],
            ['service_name' => 'Search and Rescue', 'description' => 'Search and rescue operations for missing persons.'],
            ['service_name' => 'Power Line Repair', 'description' => 'Emergency repair of downed power lines.'],
            ['service_name' => 'Debris Removal', 'description' => 'Removal of hazardous debris from public areas.'],
            ['service_name' => 'Animal Rescue', 'description' => 'Rescue operations for stranded or injured animals.'],
            ['service_name' => 'Sandbagging', 'description' => 'Provision and placement of sandbags for flood prevention.'],
        ];

        foreach ($services as $service) {
            Service::create([
                'service_name' => $service['service_name'],
                'description' => $service['description'],
            ]);
        }

        Schema::enableForeignKeyConstraints();
    }
}
