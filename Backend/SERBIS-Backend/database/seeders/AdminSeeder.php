<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('tbl_user')->insert([
            'first_name'    => 'SERBIS',
            'last_name'     => 'Administrator',
            'role'          => 'Admin',
            'email_address' => 'admin@serbis.com',
            'password'      => Hash::make('password123'),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }
}