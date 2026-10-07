<?php

namespace Database\Seeders;

use App\Models\Equipment;
use Illuminate\Database\Seeder;

class EquipmentSeeder extends Seeder
{
    /**
     * The MDRRMO's lendable equipment. Runs on production; real data.
     * Create-only, matched by item_name: an existing row is never updated, so
     * stock the office edited in the panel survives a redeploy. Old items were
     * retired once by the 2026_10_07_090000 cleanup migration, not here.
     */
    public const ITEMS = [
        'Modular Tent' => 20,
        'Disaster Tent' => 1,
        'Cot Bed' => 51,
        'Generator Set' => 6,
        'Portable Gasoline Generator' => 1,
        'Emergency Rechargeable Power Station with Solar Panel' => 1,
        'Megaphone' => 2,
        'Water Purifier' => 2,
        'Wheel Chair' => 4,
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $name => $total) {
            Equipment::firstOrCreate(['item_name' => $name], [
                'total_quantity' => $total,
                'available_quantity' => $total,
                'status' => 'Available',
            ]);
        }
    }
}
