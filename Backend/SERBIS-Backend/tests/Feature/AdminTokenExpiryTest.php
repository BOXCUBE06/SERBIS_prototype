<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/** The server caps a panel token at 8 hours whatever "Remember me" says in the browser. */
class AdminTokenExpiryTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private function token(): string
    {
        User::create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'username' => 'ana',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        return $this->postJson('/api/admin/login', ['username' => 'ana', 'password' => 'Password123'])->json('token');
    }

    private function me(string $token)
    {
        // The guard remembers the user it resolved; a later request must resolve afresh.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/me');
    }

    public function test_the_token_is_stamped_to_expire_in_eight_hours(): void
    {
        $this->token();

        $expiresAt = DB::table('personal_access_tokens')->value('expires_at');

        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, strtotime((string) $expiresAt), 5);
    }

    public function test_a_token_still_works_just_inside_eight_hours(): void
    {
        $token = $this->token();

        $this->travel(7)->hours();
        $this->travel(59)->minutes();

        $this->me($token)->assertOk();
    }

    public function test_a_token_older_than_eight_hours_is_rejected(): void
    {
        $token = $this->token();

        $this->me($token)->assertOk();

        $this->travel(8)->hours();
        $this->travel(1)->minutes();

        $this->me($token)->assertUnauthorized();
    }

    public function test_an_expired_token_cannot_reach_an_admin_route_either(): void
    {
        $token = $this->token();

        $this->travel(9)->hours();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/admins')->assertUnauthorized();
    }

    public function test_the_cap_follows_the_configured_minutes(): void
    {
        config(['sanctum.admin_expiration' => 30]);
        $token = $this->token();

        $this->travel(31)->minutes();

        $this->me($token)->assertUnauthorized();
    }
}
