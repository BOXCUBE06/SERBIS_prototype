<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * php artisan staff:make-super-admin — how the first super admin is chosen.
 */
class MakeSuperAdminCommandTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    public function test_it_promotes_the_named_account_and_only_that_one(): void
    {
        $target = $this->makeStaff('boss@serbis.com');
        $other = $this->makeStaff('other@serbis.com');

        $this->artisan('staff:make-super-admin', ['email' => 'boss@serbis.com'])
            ->expectsOutputToContain('boss@serbis.com is now a super admin.')
            ->assertExitCode(0);

        $this->assertTrue($target->fresh()->isSuperAdmin());
        $this->assertFalse($other->fresh()->isSuperAdmin());
    }

    public function test_it_says_which_database_it_reached(): void
    {
        $this->makeStaff('boss@serbis.com');

        $this->artisan('staff:make-super-admin', ['email' => 'boss@serbis.com'])
            ->expectsOutputToContain('Connected to')
            ->assertExitCode(0);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->makeSuperAdmin('boss@serbis.com');

        $this->artisan('staff:make-super-admin', ['email' => 'boss@serbis.com'])
            ->expectsOutputToContain('already a super admin')
            ->assertExitCode(0);

        $this->assertSame(1, User::activeSuperAdminCount());
    }

    public function test_an_unknown_address_fails_and_promotes_nobody(): void
    {
        $this->makeStaff('boss@serbis.com');

        $this->artisan('staff:make-super-admin', ['email' => 'nobody@serbis.com'])
            ->expectsOutputToContain('No staff account with that address.')
            ->assertExitCode(1);

        $this->assertSame(0, User::activeSuperAdminCount());
    }

    public function test_a_deactivated_account_is_refused(): void
    {
        $closed = $this->makeStaff('closed@serbis.com', ['status' => 'Inactive']);

        $this->artisan('staff:make-super-admin', ['email' => 'closed@serbis.com'])
            ->expectsOutputToContain('deactivated')
            ->assertExitCode(1);

        $this->assertFalse($closed->fresh()->isSuperAdmin());
    }

    public function test_promoting_leaves_the_permission_list_alone(): void
    {
        $account = $this->makeLimitedStaff(['sms'], 'boss@serbis.com');

        $this->artisan('staff:make-super-admin', ['email' => 'boss@serbis.com'])->assertExitCode(0);

        $this->assertSame(['sms'], $account->fresh()->permissions);
    }
}
