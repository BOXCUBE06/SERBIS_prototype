<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Read-only: who signs in as what, so each staff member can be told their username. */
class ListStaffUsernames extends Command
{
    protected $signature = 'staff:usernames';

    protected $description = 'List every staff account with its username (and old email, if any)';

    public function handle(): int
    {
        $this->table(
            ['ID', 'Name', 'Username', 'Old email', 'Status'],
            User::orderBy('admin_id')->get()->map(fn (User $u) => [
                $u->admin_id,
                trim("{$u->first_name} {$u->last_name}"),
                $u->username,
                $u->email_address ?? '',
                $u->status,
            ])->all()
        );

        return self::SUCCESS;
    }
}
