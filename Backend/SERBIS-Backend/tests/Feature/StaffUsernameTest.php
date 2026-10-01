<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff sign in with a username (2026-09-30). The backfill rules are the
 * migration's own static helpers, tested directly: re-running a schema
 * migration inside a test commits MySQL's transaction and leaks rows.
 */
class StaffUsernameTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_30_110000_add_username_to_tbl_user.php');
    }

    public function test_the_backfill_takes_the_part_before_the_at(): void
    {
        $m = $this->migration();

        $this->assertSame('maria.pascual', $m::usernameFrom('maria.pascual@serbis.com', 2));
        $this->assertSame('admin', $m::usernameFrom('Admin@serbis.com', 1));
        $this->assertSame('jilmarferrer29', $m::usernameFrom('jilmarferrer29@gmail.com', 3));
    }

    public function test_the_backfill_fits_the_username_rule(): void
    {
        $m = $this->migration();

        // Characters outside a-z 0-9 . _ are dropped.
        $this->assertSame('juandelacruz', $m::usernameFrom('juan-dela+cruz@x.com', 4));
        // Too short: padded with "staff" and the id.
        $this->assertSame('jostaff7', $m::usernameFrom('jo@x.com', 7));
        // Too long: cut to 30.
        $this->assertSame(30, strlen($m::usernameFrom(str_repeat('a', 40).'@x.com', 8)));

        foreach (['maria.pascual@serbis.com', 'jo@x.com', 'x!@y.com', str_repeat('b', 40).'@x.com'] as $email) {
            $this->assertMatchesRegularExpression(User::USERNAME_REGEX, $m::usernameFrom($email, 9));
        }
    }

    public function test_the_backfill_de_duplicates(): void
    {
        $m = $this->migration();

        $this->assertSame('admin', $m::uniqueUsername('admin', []));
        $this->assertSame('admin_2', $m::uniqueUsername('admin', ['admin' => true]));
        $this->assertSame('admin_3', $m::uniqueUsername('admin', ['admin' => true, 'admin_2' => true]));

        $long = str_repeat('c', 30);
        $this->assertSame(str_repeat('c', 28).'_2', $m::uniqueUsername($long, [$long => true]));
    }

    public function test_staff_created_from_an_email_alone_get_a_unique_username(): void
    {
        $first = User::create(['first_name' => 'A', 'last_name' => 'B', 'email_address' => 'ana@one.test', 'password' => 'x', 'role' => 'Admin']);
        $second = User::create(['first_name' => 'A', 'last_name' => 'B', 'email_address' => 'ana@two.test', 'password' => 'x', 'role' => 'Admin']);

        $this->assertSame('ana', $first->username);
        $this->assertSame('ana_2', $second->username);
    }

    public function test_login_ignores_the_case_of_the_username(): void
    {
        User::create([
            'first_name' => 'Maria', 'last_name' => 'Pascual', 'username' => 'maria.pascual',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active',
        ]);

        $this->postJson('/api/admin/login', ['username' => ' Maria.Pascual ', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('user.username', 'maria.pascual');

        $this->postJson('/api/admin/login', ['username' => 'maria.pascual', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized. MDRRMO Admin access only.');
    }

    public function test_an_old_client_sending_an_email_is_told_to_use_the_username(): void
    {
        $this->postJson('/api/admin/login', ['email_address' => 'admin@serbis.com', 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_staff_are_named_with_their_username(): void
    {
        $user = new User(['first_name' => 'Maria', 'last_name' => 'Pascual', 'username' => 'maria.pascual']);

        $this->assertSame('Maria Pascual (maria.pascual)', $user->displayName());
    }
}
