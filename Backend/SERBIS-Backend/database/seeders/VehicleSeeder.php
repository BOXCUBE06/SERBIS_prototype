<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $vehicles = [
            // Ambulances (4)
            ['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'TYPE I', 'status' => 'Available'],
            ['unit_identifier' => 'AMB-02', 'type' => 'Ambulance', 'specification' => 'TYPE I', 'status' => 'Available'],
            ['unit_identifier' => 'AMB-03', 'type' => 'Ambulance', 'specification' => 'PTU', 'status' => 'Available'],
            ['unit_identifier' => 'AMB-04', 'type' => 'Ambulance', 'specification' => 'PTU', 'status' => 'Available'],
            
            // Rescue Vehicles (2)
            ['unit_identifier' => 'RES-01', 'type' => 'Rescue Vehicle', 'specification' => 'Pickup', 'status' => 'Available'],
            ['unit_identifier' => 'RES-02', 'type' => 'Rescue Vehicle', 'specification' => 'Pickup', 'status' => 'Available'],
            
            // Fire Trucks (2)
            ['unit_identifier' => 'FTR-01', 'type' => 'Fire Truck', 'specification' => null, 'status' => 'Available'],
            ['unit_identifier' => 'FTR-02', 'type' => 'Fire Truck', 'specification' => null, 'status' => 'Available'],
            
            // Boats (6)
            ['unit_identifier' => 'BOT-01', 'type' => 'Boat', 'specification' => 'Portable Boat', 'status' => 'Available'],
            ['unit_identifier' => 'BOT-02', 'type' => 'Boat', 'specification' => 'Portable Boat', 'status' => 'Available'],
            ['unit_identifier' => 'BOT-03', 'type' => 'Boat', 'specification' => 'Baracuda Boat', 'status' => 'Available'],
            ['unit_identifier' => 'BOT-04', 'type' => 'Boat', 'specification' => 'Baracuda Boat', 'status' => 'Available'],
            ['unit_identifier' => 'BOT-05', 'type' => 'Boat', 'specification' => 'Fiber Glass', 'status' => 'Available'],
            ['unit_identifier' => 'BOT-06', 'type' => 'Boat', 'specification' => 'Unsinkable', 'status' => 'Available'],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::create($vehicle);
        }
    }
}