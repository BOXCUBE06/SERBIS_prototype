<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Equipment;

class EquipmentSeeder extends Seeder
{
    /**
     * The MDRRMO's equipment inventory. Runs on production; real data.
     */
    public function run(): void
    {
        // item_name is not unique, so a second run doubles the inventory and
        // every borrowing count is read off the wrong total.
        if (DB::table('tbl_equipments')->exists()) {
            $this->command?->warn('EquipmentSeeder skipped: tbl_equipments is not empty.');

            return;
        }

        $equipments = [
            ['item_name' => 'Wheelchair', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available'],
            ['item_name' => 'Stretcher', 'total_quantity' => 5, 'available_quantity' => 5, 'status' => 'Available'],
            ['item_name' => 'First Aid Kit', 'total_quantity' => 25, 'available_quantity' => 25, 'status' => 'Available'],
            ['item_name' => 'Oxygen Tank', 'total_quantity' => 45, 'available_quantity' => 45, 'status' => 'Available'],
            ['item_name' => 'Generator', 'total_quantity' => 2, 'available_quantity' => 2, 'status' => 'Available'],
            ['item_name' => 'Megaphone', 'total_quantity' => 2, 'available_quantity' => 2, 'status' => 'Available'],
            ['item_name' => 'Rescue Tools', 'total_quantity' => 10, 'available_quantity' => 10, 'status' => 'Available'],
        ];

        foreach ($equipments as $equipment) {
            Equipment::create($equipment);
        }
    }
}