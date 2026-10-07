<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    /**
     * The MDRRMO's fleet. Runs on production; real data.
     * Create-only, matched by unit_identifier: an existing unit's type and
     * status are never touched. Old units were retired once by the
     * 2026_10_07_090000 cleanup migration, not here.
     * tbl_vehicles has no plate column (dropped 2026_09_02), so none is set.
     *
     * @return array<string, string> unit_identifier => type (a Vehicle::TYPES value)
     */
    public static function units(): array
    {
        $units = [];
        foreach (range(1, 10) as $n) {
            $units["Ambulance {$n}"] = 'Ambulance';
        }
        foreach (range(1, 2) as $n) {
            $units["Rescue Vehicle (Hilux) {$n}"] = 'Rescue Vehicle';
            $units["Fire Truck {$n}"] = 'Fire Truck';
            $units["Baracuda {$n}"] = 'Boat';
            $units["Unsinkable Boat {$n}"] = 'Boat';
        }
        $units['Portaboat 1'] = 'Boat';

        return $units;
    }

    public function run(): void
    {
        foreach (self::units() as $name => $type) {
            Vehicle::firstOrCreate(['unit_identifier' => $name], ['type' => $type, 'status' => 'Available']);
        }
    }
}
