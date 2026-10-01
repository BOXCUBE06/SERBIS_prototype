<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    private const ADMIN_USERNAME = 'admin';

    public function run(): void
    {
        // This seeder plants a publicly-known credential, so it must never
        // reach a real deployment.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'AdminSeeder skipped: refuses to seed a default admin outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        // username is unique, so a blind insert would abort the whole seed run
        // on a populated database.
        if (DB::table('tbl_user')->where('username', self::ADMIN_USERNAME)->exists()) {
            $this->command?->info(
                'AdminSeeder skipped: '.self::ADMIN_USERNAME.' already exists; password left untouched.'
            );

            return;
        }

        $now = Carbon::now();

        DB::table('tbl_user')->insert([
            'first_name' => 'SERBIS',
            'last_name' => 'Administrator',
            'role' => 'Admin',
            'username' => self::ADMIN_USERNAME,
            'password' => Hash::make('password123'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info('AdminSeeder: created '.self::ADMIN_USERNAME.' with the default password.');
    }
}
