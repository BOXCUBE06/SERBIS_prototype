<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;

class ResidentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        for ($i = 0; $i < 20; $i++) {
            DB::table('tbl_residents')->insert([
                'barangay_id' => rand(1, 6),
                'first_name' => fake()->firstName(),
                'middle_name' => fake()->optional()->lastName(),
                'last_name' => fake()->lastName(),
                'phone_number' => fake()->phoneNumber(),
                'password' => Hash::make('password'),
                'photo' => null,
                'status' => fake()->randomElement(['Active', 'Inactive']),
                'email_address' => fake()->unique()->safeEmail(),
                'otp' => null,
                'otp_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::enableForeignKeyConstraints();
    }
}
