<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AdminSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@serbis.com';

    public function run(): void
    {
        // This seeder plants a publicly-known credential, so it must never
        // reach a real deployment.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'AdminSeeder skipped: refuses to seed a default admin outside local/testing (env: '
                . app()->environment() . ').'
            );

            return;
        }

        // email_address is unique, so a blind insert would abort the whole
        // seed run on a populated database.
        if (DB::table('tbl_user')->where('email_address', self::ADMIN_EMAIL)->exists()) {
            $this->command?->info(
                'AdminSeeder skipped: ' . self::ADMIN_EMAIL . ' already exists; password left untouched.'
            );

            return;
        }

        $now = Carbon::now();

        DB::table('tbl_user')->insert([
            'first_name'    => 'SERBIS',
            'last_name'     => 'Administrator',
            'role'          => 'Admin',
            'email_address' => self::ADMIN_EMAIL,
            'password'      => Hash::make('password123'),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $this->command?->info('AdminSeeder: created ' . self::ADMIN_EMAIL . ' with the default password.');
    }
}
