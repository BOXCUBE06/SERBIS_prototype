<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes a staff account a super admin from the server.
 *
 * The migration that added `is_super_admin` promotes nobody, so on a fresh
 * deploy there is no super admin and nobody can open Staff Accounts or change
 * anyone's access. This is how the first one is chosen, on purpose, per
 * environment. It is also the way back when the last one is gone by some route
 * the panel's own guards do not cover, such as a direct database edit.
 *
 * Whoever runs this already holds database access, so it asks nothing more. It
 * prints which database it reached first: a promotion against the wrong
 * connection is a silent no-op that reads exactly like success.
 *
 * A deactivated account is refused. It cannot sign in, so promoting it would
 * count as a super admin on paper while leaving the panel with none in practice.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'staff:make-super-admin {email : The staff account\'s sign-in address}';

    protected $description = 'Make a staff account a super admin (opens Staff Accounts and lets it set everyone\'s access)';

    public function handle(): int
    {
        $this->line(sprintf(
            'Connected to %s@%s:%s/%s',
            config('database.connections.'.config('database.default').'.username'),
            config('database.connections.'.config('database.default').'.host'),
            config('database.connections.'.config('database.default').'.port'),
            DB::getDatabaseName(),
        ));

        $admin = User::where('email_address', trim((string) $this->argument('email')))->first();

        if (! $admin || ! $admin->isAdmin()) {
            $this->error('No staff account with that address.');

            return self::FAILURE;
        }

        if ($admin->isDeactivated()) {
            $this->error('That account is deactivated. Reactivate it first (staff:reset-password --reactivate, or Reactivate in the panel), then run this again.');

            return self::FAILURE;
        }

        if ($admin->isSuperAdmin()) {
            $this->info("{$admin->email_address} is already a super admin. Nothing changed.");

            return self::SUCCESS;
        }

        $admin->is_super_admin = true;
        $admin->save();

        $this->info("{$admin->email_address} is now a super admin.");
        $this->line('They can open Staff Accounts and set which sections each account may use.');

        return self::SUCCESS;
    }
}
