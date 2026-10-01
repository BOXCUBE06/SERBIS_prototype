<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CompletesAdminMfa;
use Tests\Concerns\FakesSkySms;
use Tests\TestCase;

/**
 * ADMIN_MFA_ENABLED: off, staff sign in with username + password; on, a code
 * is texted to tbl_user.phone_number and must come back to /admin/login/verify.
 * Same code, expiry, attempt cap and resend cooldown as resident login.
 */
class AdminSmsMfaTest extends TestCase
{
    use CompletesAdminMfa, FakesSkySms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSkySms();
    }

    private function staff(string $username, ?string $phone = '+639171234567', bool $super = false): User
    {
        $user = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'username' => $username,
            'phone_number' => $phone,
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);
        $user->forceFill(['is_super_admin' => $super])->save();

        return $user->fresh();
    }

    private function login(string $username = 'ana')
    {
        return $this->postJson('/api/admin/login', ['username' => $username, 'password' => 'Password123']);
    }

    private function enable(): void
    {
        config(['serbis.admin_mfa_enabled' => true]);
    }

    public function test_flag_off_signs_in_without_a_code(): void
    {
        $this->staff('ana');

        $this->login()->assertOk()->assertJsonStructure(['token']);
        $this->assertSame([], $this->numbersTexted());
    }

    public function test_flag_on_requires_the_texted_code(): void
    {
        $this->enable();
        $this->staff('ana');

        $first = $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required')
            ->assertJsonPath('sent_to', '4567')
            ->assertJsonPath('sent_to_masked', '0917•••4567')
            ->assertJsonMissingPath('token');

        $this->assertSame(['+639171234567'], $this->numbersTexted());

        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->lastCodeTexted(),
        ])->assertOk()->assertJsonStructure(['token', 'sections']);
    }

    public function test_a_wrong_code_is_refused_and_five_kill_the_challenge(): void
    {
        $this->enable();
        $this->staff('ana');
        $challenge = $this->login()->json('challenge_id');
        $wrong = $this->lastCodeTexted() === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/login/verify', ['challenge_id' => $challenge, 'code' => $wrong])
                ->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        }

        $this->postJson('/api/admin/login/verify', ['challenge_id' => $challenge, 'code' => $this->lastCodeTexted()])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->enable();
        $this->staff('ana');
        $challenge = $this->login()->json('challenge_id');

        $this->travel(6)->minutes();

        $this->postJson('/api/admin/login/verify', ['challenge_id' => $challenge, 'code' => $this->lastCodeTexted()])
            ->assertStatus(422)->assertJsonPath('code', 'mfa_challenge_expired');
    }

    public function test_resend_waits_out_the_resident_cooldown_and_the_new_code_works(): void
    {
        $this->enable();
        $this->staff('ana');
        $challenge = $this->login()->json('challenge_id');

        $this->postJson('/api/admin/login/resend', ['challenge_id' => $challenge])
            ->assertStatus(429)->assertJsonPath('code', 'resend_too_soon');

        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/admin/login/resend', ['challenge_id' => $challenge])
            ->assertOk()->assertJsonPath('code', 'code_sent')->assertJsonPath('sent_to_masked', '0917•••4567');

        $this->assertCount(2, $this->codesTexted());

        $this->postJson('/api/admin/login/verify', ['challenge_id' => $challenge, 'code' => $this->lastCodeTexted()])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_resident_challenge_cannot_be_resent_or_verified_as_staff(): void
    {
        $this->enable();
        $this->staff('ana');
        $challenge = $this->login()->json('challenge_id');

        $this->postJson('/api/resident/login/resend', ['challenge_id' => $challenge])
            ->assertStatus(422)->assertJsonPath('code', 'mfa_challenge_expired');
        $this->postJson('/api/resident/login/verify', ['challenge_id' => $challenge, 'code' => $this->lastCodeTexted()])
            ->assertStatus(422)->assertJsonPath('code', 'mfa_challenge_expired');
    }

    public function test_staff_without_a_phone_are_told_why_and_nobody_else_is_locked_out(): void
    {
        $this->enable();
        $this->staff('nophone', null);
        $this->staff('ana');

        $this->login('nophone')
            ->assertStatus(403)
            ->assertJsonPath('code', 'phone_missing')
            ->assertJsonPath('message', 'Ask a super admin to add your mobile number.');
        $this->assertSame([], $this->numbersTexted());

        $this->loginAdmin('ana', 'Password123')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_phone_check_comes_after_the_password(): void
    {
        $this->enable();
        $this->staff('nophone', null);

        $this->postJson('/api/admin/login', ['username' => 'nophone', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_a_super_admin_can_change_any_staff_phone_and_the_code_goes_there(): void
    {
        $super = $this->staff('boss', '+639170000001', true);
        $target = $this->staff('nophone', null);
        Sanctum::actingAs($super);

        $this->putJson("/api/admins/{$target->admin_id}", [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'username' => 'nophone',
            'phone_number' => '0918 765 4321',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');

        $this->putJson("/api/admins/{$target->admin_id}", [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'username' => 'nophone',
            'phone_number' => '09187654321',
        ])->assertOk()->assertJsonPath('phone_number', '+639187654321');

        $this->app['auth']->forgetGuards();
        $this->enable();
        $this->login('nophone')->assertStatus(403)->assertJsonPath('code', 'mfa_required');
        $this->assertSame(['+639187654321'], $this->numbersTexted());
    }

    public function test_editing_without_the_phone_field_keeps_the_stored_number(): void
    {
        $super = $this->staff('boss', '+639170000001', true);
        $target = $this->staff('ana');
        Sanctum::actingAs($super);

        $this->putJson("/api/admins/{$target->admin_id}", [
            'first_name' => 'Anna',
            'last_name' => 'Cruz',
            'username' => 'ana',
        ])->assertOk()->assertJsonPath('phone_number', '+639171234567');
    }

    public function test_a_new_staff_account_needs_a_phone(): void
    {
        Sanctum::actingAs($this->staff('boss', '+639170000001', true));

        $payload = [
            'first_name' => 'Ben',
            'last_name' => 'Lim',
            'username' => 'ben',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ];

        $this->postJson('/api/admins', $payload)->assertStatus(422)->assertJsonValidationErrors('phone_number');
        $this->postJson('/api/admins', $payload + ['phone_number' => '639171112222'])
            ->assertCreated()->assertJsonPath('phone_number', '+639171112222');
    }

    public function test_the_list_says_whether_the_switch_is_on(): void
    {
        Sanctum::actingAs($this->staff('boss', '+639170000001', true));

        $this->getJson('/api/admins')->assertOk()->assertJsonPath('admin_mfa_enabled', false);
        $this->enable();
        $this->getJson('/api/admins')->assertOk()->assertJsonPath('admin_mfa_enabled', true);
    }

    public function test_set_phone_command_is_the_server_side_fallback(): void
    {
        $this->staff('boss', null, true);

        $this->artisan('staff:set-phone', ['username' => 'boss', 'number' => 'nope'])->assertFailed();
        $this->artisan('staff:set-phone', ['username' => 'ghost', 'number' => '09171234567'])->assertFailed();
        $this->artisan('staff:set-phone', ['username' => 'BOSS', 'number' => '09171234567'])->assertSuccessful();

        $this->assertSame('+639171234567', User::where('username', 'boss')->value('phone_number'));
    }

    public function test_usernames_missing_lists_only_staff_without_a_number(): void
    {
        $this->staff('ana');
        $this->staff('nophone', null);

        $this->artisan('staff:usernames', ['--missing' => true])
            ->expectsOutputToContain('nophone')
            ->doesntExpectOutputToContain('09171234567')
            ->assertSuccessful();
    }
}
