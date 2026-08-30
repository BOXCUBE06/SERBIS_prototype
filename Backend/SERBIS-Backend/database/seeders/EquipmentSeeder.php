<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Equipment;

class EquipmentSeeder extends Seeder
{
    /**
     * The MDRRMO's equipment inventory. Runs on production; real data.
     *
     * Household medical durables only, and that is the point. An earlier list
     * mixed three different things under one "borrow" verb and none of them
     * answered the same question:
     *
     *  - Consumables. A First Aid Kit is used up, not brought back. Twenty-five
     *    of them on a loan ledger is a supply hand-out wearing a loan's
     *    clothes, and the Released -> Returned transition has no meaning for it.
     *  - Barangay-level response gear. A stretcher, a generator, a megaphone or
     *    rescue tools are lent to a *barangay* for a drill or an operation. The
     *    borrower this app knows is a head of the family, so half the catalogue
     *    had no plausible requester.
     *  - Household durables, which is what is left below. Every one of these is
     *    borrowed by a family caring for someone at home, and every one has an
     *    obvious reason to come back: the next family needs it.
     *
     * If barangay-level lending is ever wanted, it needs a borrower that is a
     * barangay — not a wider list under the same resident-facing screen.
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
            ['item_name' => 'Crutches (pair)', 'total_quantity' => 6, 'available_quantity' => 6, 'status' => 'Available'],
            ['item_name' => 'Walker', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available'],
            ['item_name' => 'Hospital Bed', 'total_quantity' => 3, 'available_quantity' => 3, 'status' => 'Available'],
            ['item_name' => 'Oxygen Tank', 'total_quantity' => 10, 'available_quantity' => 10, 'status' => 'Available'],
            ['item_name' => 'Nebulizer', 'total_quantity' => 5, 'available_quantity' => 5, 'status' => 'Available'],
        ];

        foreach ($equipments as $equipment) {
            Equipment::create($equipment);
        }
    }
}