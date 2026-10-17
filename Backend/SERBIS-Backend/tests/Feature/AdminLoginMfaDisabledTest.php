<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesSkySms;
use Tests\TestCase;

/** ADMIN_MFA_ENABLED=false (the default): the password alone issues the token. */
class AdminLoginMfaDisabledTest extends TestCase
{
    use FakesSkySms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSkySms();
        config(['serbis.admin_mfa_enabled' => false]);
    }

    private function staff(?string $phone = '+639171234567'): User
    {
        return User::create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'username' => 'ana',
            'phone_number' => $phone,
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);
    }

    private function login(string $password = 'Password123')
    {
        return $this->postJson('/api/admin/login', ['username' => 'ana', 'password' => $password]);
    }

    public function test_password_alone_returns_a_token_and_texts_nothing(): void
    {
        $this->staff();

        $this->login()
            ->assertOk()
            ->assertJsonStructure(['token', 'role', 'user', 'sections'])
            ->assertJsonMissingPath('challenge_id');
        $this->assertSame([], $this->numbersTexted());
    }

    public function test_staff_without_a_phone_still_sign_in(): void
    {
        $this->staff(null);

        $this->login()->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_token_opens_an_admin_route(): void
    {
        $this->staff();
        $token = $this->login()->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk();
    }

    public function test_a_wrong_password_still_gets_no_token(): void
    {
        $this->staff();

        $this->login('Wrong12345')->assertStatus(401)->assertJsonMissingPath('token');
    }

    public function test_the_verify_step_is_not_a_way_in(): void
    {
        $this->staff();

        $this->postJson('/api/admin/login/verify', ['challenge_id' => str_repeat('a', 64), 'code' => '123456'])
            ->assertStatus(422)->assertJsonMissingPath('token');
    }
}
