<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/** Read-only: who signs in as what, so each staff member can be told their username. */
class ListStaffUsernames extends Command
{
    protected $signature = 'staff:usernames {--missing : Only staff with no mobile number (check before ADMIN_MFA_ENABLED)}';

    protected $description = 'List every staff account with its username, phone (and old email, if any)';

    public function handle(): int
    {
        $staff = User::orderBy('admin_id')->get();

        if ($this->option('missing')) {
            $staff = $staff->filter(fn (User $u) => PhoneNumber::normalize((string) $u->phone_number) === '');
        }

        $this->table(
            ['ID', 'Name', 'Username', 'Phone', 'Old email', 'Status'],
            $staff->map(fn (User $u) => [
                $u->admin_id,
                trim("{$u->first_name} {$u->last_name}"),
                $u->username,
                $u->phone_number ? PhoneNumber::display($u->phone_number) : '',
                $u->email_address ?? '',
                $u->status,
            ])->values()->all()
        );

        return self::SUCCESS;
    }
}
