<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResidentSeeder extends Seeder
{
    private const RESIDENT_COUNT = 20;

    public function run(): void
    {
        // Seeds accounts with generated credentials, so it must never reach
        // a real deployment.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'ResidentSeeder skipped: refuses to seed test residents outside local/testing (env: '
                . app()->environment() . ').'
            );

            return;
        }

        // Draw from the barangays that actually exist rather than a literal
        // range: a hardcoded range silently orphans residents whenever the
        // barangay list changes, and the FK will not catch it.
        $barangayIds = DB::table('tbl_barangay')->pluck('barangay_id')->all();

        if (empty($barangayIds)) {
            $this->command?->warn('ResidentSeeder skipped: no barangays exist — run BarangaySeeder first.');

            return;
        }

        $credentials = [];

        for ($i = 0; $i < self::RESIDENT_COUNT; $i++) {
            $email = fake()->unique()->safeEmail();
            $password = Str::password(16);

            DB::table('tbl_residents')->insert([
                'barangay_id'     => fake()->randomElement($barangayIds),
                'first_name'      => fake()->firstName(),
                'middle_name'     => fake()->optional()->lastName(),
                'last_name'       => fake()->lastName(),
                'phone_number'    => fake()->phoneNumber(),
                'password'        => Hash::make($password),
                'photo'           => null,
                'status'          => fake()->randomElement(['Active', 'Inactive']),
                'email_address'   => $email,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $credentials[] = ['email' => $email, 'password' => $password];
        }

        $this->command?->info(
            'ResidentSeeder: created ' . self::RESIDENT_COUNT . ' residents across barangay ids '
            . implode(', ', $barangayIds) . '.'
        );

        // Passwords are random and hashed on insert, so this is the only
        // chance to see them. Local only — noise in the test suite otherwise.
        if (app()->environment('local')) {
            $this->command?->table(['email_address', 'password'], $credentials);
        }
    }
}
