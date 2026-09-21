<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Staff accounts for tests that care about section access.
 *
 * `is_super_admin` and `permissions` are deliberately not mass-assignable, so
 * they are set with forceFill after the row exists — the same route the
 * production writers take, one attribute at a time.
 */
trait MakesAdmins
{
    /** An ordinary admin, with the null permissions every pre-existing account has: every assignable section. */
    protected function makeStaff(string $email = 'admin@test.local', array $attributes = []): User
    {
        $admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            // Explicit: create() returns the in-memory model without reading
            // column defaults back, and is.admin refuses a null-status account
            // handed to Sanctum::actingAs only when it is deactivated, so this
            // is for clarity rather than need.
            'status' => 'Active',
        ]);

        if ($attributes !== []) {
            $admin->forceFill($attributes)->save();
        }

        return $admin;
    }

    protected function makeSuperAdmin(string $email = 'super@test.local', array $attributes = []): User
    {
        return $this->makeStaff($email, array_merge(['is_super_admin' => true], $attributes));
    }

    /**
     * An admin limited to $sections. An empty list is an account that may open
     * nothing, which is what the panel gives a newly created one.
     *
     * @param  list<string>  $sections
     */
    protected function makeLimitedStaff(array $sections, string $email = 'limited@test.local'): User
    {
        return $this->makeStaff($email, ['permissions' => $sections]);
    }
}
