<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Last resort for when no admin can sign in to the panel, so nobody is left to
 * press "Reset password" there. Does what that button does — temporary
 * password, printed once, account flagged so its owner must replace it, every
 * session ended — from the server instead.
 *
 * Whoever runs this already holds database access, so it asks nothing more.
 * It prints which database it reached first: a reset against the wrong
 * connection is a silent no-op that reads exactly like success.
 *
 * A closed account is refused unless --reactivate is given, so a departed
 * employee's account is never brought back by accident.
 */
class ResetStaffPassword extends Command
{
    protected $signature = 'staff:reset-password {username : The staff account\'s username} {--reactivate : Also reopen the account if it is deactivated}';

    protected $description = 'Set a temporary password on a staff account (last resort when no admin can sign in)';

    public function handle(): int
    {
        $this->line(sprintf(
            'Connected to %s@%s:%s/%s',
            config('database.connections.'.config('database.default').'.username'),
            config('database.connections.'.config('database.default').'.host'),
            config('database.connections.'.config('database.default').'.port'),
            DB::getDatabaseName(),
        ));

        $admin = User::where('username', strtolower(trim((string) $this->argument('username'))))->first();

        if (! $admin) {
            $this->error('No staff account with that username.');

            return self::FAILURE;
        }

        if ($admin->isDeactivated()) {
            if (! $this->option('reactivate')) {
                $this->error('That account is deactivated. Run again with --reactivate to reopen it.');

                return self::FAILURE;
            }

            $admin->status = 'Active';
        }

        $temporary = User::generateTemporaryPassword();

        $admin->password = $temporary;
        $admin->must_change_password = true;
        $admin->save();
        $admin->tokens()->delete();

        $this->newLine();
        $this->info("Temporary password for {$admin->username}:");
        $this->line($temporary);
        $this->newLine();
        $this->line('Shown once. They must set their own password at the next sign-in.');

        return self::SUCCESS;
    }
}
