<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets the number a staff member's sign-in code is texted to. The panel's Staff
 * Accounts page does the same; this is for when nobody can sign in to use it —
 * a super admin with no number once ADMIN_MFA_ENABLED is on, say.
 */
class SetStaffPhone extends Command
{
    protected $signature = 'staff:set-phone {username : The staff account\'s username} {number : Mobile number, e.g. 09171234567}';

    protected $description = 'Set the mobile number a staff account\'s sign-in code is texted to';

    public function handle(): int
    {
        // A write against the wrong connection reads exactly like success.
        $this->line('Connected to '.DB::getDatabaseName());

        $admin = User::where('username', strtolower(trim((string) $this->argument('username'))))->first();

        if (! $admin) {
            $this->error('No staff account with that username.');

            return self::FAILURE;
        }

        $number = trim((string) $this->argument('number'));

        if (preg_match(PhoneNumber::REGEX, $number) !== 1) {
            $this->error('Use a mobile number like 09171234567, 639171234567 or +639171234567.');

            return self::FAILURE;
        }

        $admin->phone_number = PhoneNumber::normalize($number);
        $admin->save();

        $this->info("{$admin->username} now receives sign-in codes at ".PhoneNumber::display($admin->phone_number).'.');

        return self::SUCCESS;
    }
}
