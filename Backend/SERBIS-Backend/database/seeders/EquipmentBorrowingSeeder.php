<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EquipmentBorrowingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $borrowings = [
            [
                'resident_id' => 1, 
                'equipment_id' => 1, // Wheelchair
                'quantity' => 1,
                'status' => 'Pending',
                'released_at' => null,
                'returned_at' => null,
                'created_at' => clone $now->subHours(2),
                'updated_at' => clone $now->subHours(2),
            ],
            [
                'resident_id' => 2, 
                'equipment_id' => 3, // First Aid Kit
                'quantity' => 2,
                'status' => 'Approved',
                'released_at' => null,
                'returned_at' => null,
                'created_at' => clone $now->subDays(1),
                'updated_at' => clone $now->subHours(5),
            ],
            [
                'resident_id' => 3, 
                'equipment_id' => 4, // Oxygen Tank
                'quantity' => 1,
                'status' => 'Released',
                'released_at' => clone $now->subDays(2),
                'returned_at' => null,
                'created_at' => clone $now->subDays(3),
                'updated_at' => clone $now->subDays(2),
            ],
            [
                'resident_id' => 4, 
                'equipment_id' => 2, // Stretcher
                'quantity' => 1,
                'status' => 'Returned',
                'released_at' => clone $now->subDays(5),
                'returned_at' => clone $now->subDays(1),
                'created_at' => clone $now->subDays(6),
                'updated_at' => clone $now->subDays(1),
            ],
            [
                'resident_id' => 5, 
                'equipment_id' => 5, // Generator
                'quantity' => 1,
                'status' => 'Denied',
                'released_at' => null,
                'returned_at' => null,
                'created_at' => clone $now->subDays(2),
                'updated_at' => clone $now->subDays(1),
            ],
            [
                'resident_id' => 6, 
                'equipment_id' => 7, // Rescue Tools
                'quantity' => 3,
                'status' => 'Pending',
                'released_at' => null,
                'returned_at' => null,
                'created_at' => clone $now->subMinutes(30),
                'updated_at' => clone $now->subMinutes(30),
            ]
        ];

        // Ensure you change the table name if it differs in your migration
        DB::table('tbl_equipment_borrowing')->insert($borrowings);
    }
}