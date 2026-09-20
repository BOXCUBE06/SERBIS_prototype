<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * php artisan staff:reset-password — the way back in when no admin can sign in.
 */
class ResetStaffPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $email, string $status = 'Active'): User
    {
        return User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => $status,
        ]);
    }

    public function test_it_sets_a_temporary_password_and_flags_the_account(): void
    {
        $admin = $this->makeAdmin('admin@serbis.com');
        $admin->createToken('admin-token');

        $this->artisan('staff:reset-password', ['email' => 'admin@serbis.com'])
            ->expectsOutputToContain('Temporary password for admin@serbis.com')
            ->assertExitCode(0);

        $admin->refresh();
        $this->assertTrue($admin->must_change_password);
        $this->assertFalse(Hash::check('Password123', $admin->password));
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_the_printed_password_is_the_one_that_signs_in(): void
    {
        $this->makeAdmin('admin@serbis.com');

        Artisan::call('staff:reset-password', ['email' => 'admin@serbis.com']);

        // The line after the "Temporary password for ..." heading.
        $lines = array_values(array_filter(array_map('trim', explode('
', Artisan::output()))));
        $heading = array_search('Temporary password for admin@serbis.com:', $lines, true);
        $this->assertNotFalse($heading);
        $printed = $lines[$heading + 1];

        $this->postJson('/api/admin/login', [
            'email_address' => 'admin@serbis.com',
            'password' => 'Password123',
        ])->assertStatus(401);

        $this->postJson('/api/admin/login', [
            'email_address' => 'admin@serbis.com',
            'password' => $printed,
        ])->assertStatus(200)->assertJsonPath('user.must_change_password', true);
    }

    public function test_an_unknown_address_fails_and_changes_nothing(): void
    {
        $admin = $this->makeAdmin('admin@serbis.com');

        $this->artisan('staff:reset-password', ['email' => 'nobody@serbis.com'])
            ->expectsOutputToContain('No staff account with that address.')
            ->assertExitCode(1);

        $this->assertFalse($admin->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Password123', $admin->fresh()->password));
    }

    public function test_a_deactivated_account_is_refused_unless_reactivate_is_given(): void
    {
        $closed = $this->makeAdmin('closed@serbis.com', 'Inactive');

        $this->artisan('staff:reset-password', ['email' => 'closed@serbis.com'])
            ->expectsOutputToContain('deactivated')
            ->assertExitCode(1);

        $this->assertFalse($closed->fresh()->must_change_password);
        $this->assertSame('Inactive', $closed->fresh()->status);

        $this->artisan('staff:reset-password', ['email' => 'closed@serbis.com', '--reactivate' => true])
            ->assertExitCode(0);

        $this->assertSame('Active', $closed->fresh()->status);
        $this->assertTrue($closed->fresh()->must_change_password);
    }

    public function test_the_password_is_not_written_to_the_system_log(): void
    {
        $this->makeAdmin('admin@serbis.com');

        $this->artisan('staff:reset-password', ['email' => 'admin@serbis.com'])->assertExitCode(0);

        $logs = DB::table('tbl_system_logs')->get()->toJson();
        $this->assertStringNotContainsString('"password"', $logs);
    }
}
