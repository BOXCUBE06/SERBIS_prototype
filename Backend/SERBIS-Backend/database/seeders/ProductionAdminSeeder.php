<?php

namespace Database\Seeders;

use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The one real admin account, for a deployment where tbl_user starts empty.
 *
 * Distinct from AdminSeeder, which plants admin@serbis.com / password123 and
 * refuses to run outside local/testing precisely because that credential is
 * public. This one carries no password of its own: it reads ADMIN_SEED_PASSWORD
 * from the environment and throws without it while no Admin exists, so nothing that could log
 * anyone in is ever committed to the repository.
 *
 * Skipped whenever any Admin row exists, so a second run never inserts again
 * and never resets a password the admin has since changed in the panel. The
 * account is created as the super admin. Name, email and username come from
 * ADMIN_SEED_NAME / ADMIN_SEED_EMAIL / ADMIN_SEED_USERNAME (defaults below);
 * ADMIN_SEED_PHONE, when set, becomes the number sign-in codes go to.
 */
class ProductionAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('tbl_user')->where('role', 'Admin')->exists()) {
            $this->command?->info(
                'ProductionAdminSeeder skipped: an admin account already exists; password left untouched.'
            );

            return;
        }

        $password = env('ADMIN_SEED_PASSWORD');

        // Thrown, not logged: under the entrypoint's `set -e` a missing password
        // with no admin stops the boot, rather than serving a panel nobody can log into.
        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'ProductionAdminSeeder: no Admin account exists and ADMIN_SEED_PASSWORD is not set. '
                .'Set it on the host and redeploy; it can be unset once the admin exists.'
            );
        }

        [$first, $last] = array_pad(explode(' ', trim((string) env('ADMIN_SEED_NAME', 'Jilmar Ferrer')), 2), 2, '');
        $username = strtolower(trim((string) env('ADMIN_SEED_USERNAME', 'jilmarferrer29')));
        $phone = env('ADMIN_SEED_PHONE');

        $now = Carbon::now();

        // 'Admin' with a capital A is the value AdminController writes and the
        // value User::isAdmin() compares against. Lowercase 'admin' reads as a
        // resident to every admin-scoped query — see ServiceRequestController.
        DB::table('tbl_user')->insert([
            'first_name' => $first,
            'last_name' => $last,
            'role' => 'Admin',
            'status' => 'Active',
            'username' => $username,
            'email_address' => trim((string) env('ADMIN_SEED_EMAIL', 'jilmarferrer29@gmail.com')),
            'phone_number' => is_string($phone) && $phone !== '' ? (PhoneNumber::normalize($phone) ?: null) : null,
            'password' => Hash::make($password),
            'is_super_admin' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info('ProductionAdminSeeder: created '.$username.'.');
    }
}
