<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
            DB::table('tbl_services')->insert([
                'service_name' => $service['service_name'],
                'description' => $service['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::enableForeignKeyConstraints();
    }
}
