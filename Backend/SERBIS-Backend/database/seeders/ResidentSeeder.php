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
                .app()->environment().').'
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
                'barangay_id' => fake()->randomElement($barangayIds),
                'first_name' => fake()->firstName(),
                'middle_name' => fake()->optional()->lastName(),
                'last_name' => fake()->lastName(),
                'phone_number' => fake()->phoneNumber(),
                'password' => Hash::make($password),
                'photo' => null,
                'status' => fake()->randomElement(['Active', 'Inactive']),
                'email_address' => $email,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $credentials[] = ['email' => $email, 'password' => $password];
        }

        // One Barangay hall and one Organization account, so the panel's
        // account-type tabs have something to show beyond head_of_family.
        // Different barangay ids from each other: ResidentController enforces
        // one barangay account per barangay_id, and this seeder's fixtures
        // must stay valid against that rule (an org account carries no such
        // restriction, so it may share a barangay_id with the hall).
        DB::table('tbl_residents')->insert([
            [
                'barangay_id' => $barangayIds[0],
                'first_name' => 'Barangay',
                'middle_name' => null,
                'last_name' => 'Hall',
                'phone_number' => fake()->unique()->phoneNumber(),
                'password' => Hash::make(Str::password(16)),
                'photo' => null,
                'status' => 'Active',
                'email_address' => fake()->unique()->safeEmail(),
                'account_type' => 'barangay',
                'organization_name' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'barangay_id' => $barangayIds[1] ?? $barangayIds[0],
                'first_name' => fake()->firstName(),
                'middle_name' => null,
                'last_name' => fake()->lastName(),
                'phone_number' => fake()->unique()->phoneNumber(),
                'password' => Hash::make(Str::password(16)),
                'photo' => null,
                'status' => 'Active',
                'email_address' => fake()->unique()->safeEmail(),
                'account_type' => 'organization',
                'organization_name' => 'Echague Community Council',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->command?->info(
            'ResidentSeeder: created '.self::RESIDENT_COUNT.' residents across barangay ids '
            .implode(', ', $barangayIds).', plus one barangay hall and one organization account.'
        );

        // Passwords are random and hashed on insert, so this is the only
        // chance to see them. Local only — noise in the test suite otherwise.
        if (app()->environment('local')) {
            $this->command?->table(['email_address', 'password'], $credentials);
        }
    }
}
