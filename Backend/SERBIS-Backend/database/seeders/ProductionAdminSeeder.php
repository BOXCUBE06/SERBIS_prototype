<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The one real admin account, for a deployment where tbl_user starts empty.
 *
 * Distinct from AdminSeeder, which plants admin@serbis.com / password123 and
 * refuses to run outside local/testing precisely because that credential is
 * public. This one carries no password of its own: it reads ADMIN_SEED_PASSWORD
 * from the environment and refuses to run without it, so nothing that could log
 * anyone in is ever committed to the repository.
 *
 * Idempotent on the email. A second run leaves the existing password alone
 * rather than resetting it to whatever the env var happens to hold — after the
 * admin has changed their password in the panel, a redeploy must not put the
 * old one back.
 */
class ProductionAdminSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'jilmarferrer29@gmail.com';

    public function run(): void
    {
        $password = env('ADMIN_SEED_PASSWORD');

        if (! is_string($password) || $password === '') {
            $this->command?->error(
                'ProductionAdminSeeder skipped: ADMIN_SEED_PASSWORD is not set. '
                .'Set it in the host dashboard and re-run, or the panel will have no account to log into.'
            );

            return;
        }

        if (DB::table('tbl_user')->where('email_address', self::ADMIN_EMAIL)->exists()) {
            $this->command?->info(
                'ProductionAdminSeeder skipped: '.self::ADMIN_EMAIL.' already exists; password left untouched.'
            );

            return;
        }

        $now = Carbon::now();

        // 'Admin' with a capital A is the value AdminController writes and the
        // value User::isAdmin() compares against. Lowercase 'admin' reads as a
        // resident to every admin-scoped query — see ServiceRequestController.
        DB::table('tbl_user')->insert([
            'first_name' => 'Jilmar',
            'last_name' => 'Ferrer',
            'role' => 'Admin',
            'status' => 'Active',
            'email_address' => self::ADMIN_EMAIL,
            'password' => Hash::make($password),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info('ProductionAdminSeeder: created '.self::ADMIN_EMAIL.'.');
    }
}
