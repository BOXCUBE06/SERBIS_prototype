<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class BarangaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        $now = Carbon::now();
        
        DB::table('tbl_barangay')->insert([
            ['barangay_name' => 'Brgy. San Fabian', 'created_at' => $now, 'updated_at' => $now],
            ['barangay_name' => 'Brgy. Annafunan', 'created_at' => $now, 'updated_at' => $now],
            ['barangay_name' => 'Brgy. Gucab', 'created_at' => $now, 'updated_at' => $now],
            ['barangay_name' => 'Brgy. Soyung', 'created_at' => $now, 'updated_at' => $now],
            ['barangay_name' => 'Brgy. Tuguegarao', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::enableForeignKeyConstraints();
    }
}
