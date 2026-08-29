<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Admin login is now two requests: password, then a TOTP code. Enrollment has
 * no column of its own — see Totp::isEnrolled()/markEnrolled(), which are
 * Cache::forever, not a migration — so "first login ever" and "every login
 * after" are the two shapes this file has to prove separately.
 */
class AdminMfaLoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $email = 'admin@test.local'): User
    {
        return User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);
    }

    private function currentCodeFor(User $admin): string
    {
        return (new Google2FA())->getCurrentOtp(app(Totp::class)->secretFor($admin->admin_id));
    }

    public function test_first_login_returns_a_qr_code_and_marks_enrollment_required(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->postJson('/api/admin/login', [
            'email_address' => $admin->email_address,
            'password' => 'Password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required')
            ->assertJsonPath('enrollment_required', true)
            ->assertJsonStructure(['challenge_id', 'qr_code', 'secret']);

        // No token anywhere in this response — the whole point of the change.
        $response->assertJsonMissingPath('token');
    }

    public function test_correct_code_signs_in_and_enrollment_sticks(): void
    {
        $admin = $this->makeAdmin();

        $first = $this->postJson('/api/admin/login', [
            'email_address' => $admin->email_address,
            'password' => 'Password123',
        ]);

        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->currentCodeFor($admin),
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'admin');

        // Second login: no more QR, because the first correct code enrolled it.
        $second = $this->postJson('/api/admin/login', [
            'email_address' => $admin->email_address,
            'password' => 'Password123',
        ]);

        $second->assertStatus(403)
            ->assertJsonPath('enrollment_required', false)
            ->assertJsonMissingPath('qr_code');

        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $second->json('challenge_id'),
            'code' => $this->currentCodeFor($admin),
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_wrong_code_is_rejected_and_counts_against_the_attempt_cap(): void
    {
        $admin = $this->makeAdmin();

        $first = $this->postJson('/api/admin/login', [
            'email_address' => $admin->email_address,
            'password' => 'Password123',
        ]);

        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_five_wrong_codes_kill_the_challenge(): void
    {
        $admin = $this->makeAdmin();

        $first = $this->postJson('/api/admin/login', [
            'email_address' => $admin->email_address,
            'password' => 'Password123',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/login/verify', [
                'challenge_id' => $first->json('challenge_id'),
                'code' => '000000',
            ]);
        }

        // The challenge is dead now even with the right code.
        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->currentCodeFor($admin),
        ])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_an_unknown_challenge_id_is_rejected(): void
    {
        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => 'not-a-real-challenge',
            'code' => '123456',
        ])->assertStatus(422)->assertJsonPath('code', 'mfa_challenge_expired');
    }

    public function test_a_resident_challenge_cannot_be_spent_at_the_admin_endpoint(): void
    {
        // Guards against the two challenge types being interchangeable just
        // because they share one cache key namespace.
        $barangay = \App\Models\Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = \App\Models\Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09171111111',
            'email_address' => 'resident-mfa-cross@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        $resident->markEmailAsVerified();

        \Illuminate\Support\Facades\Http::fake([
            'dashboard.philsms.com/*' => fn () => \Illuminate\Support\Facades\Http::response(['status' => 'success'], 200),
        ]);

        $residentFirst = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('code', 'mfa_required');

        $admin = $this->makeAdmin();

        // A real, live challenge id — just the wrong type. Must not verify
        // against an admin's TOTP code just because the string exists.
        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $residentFirst->json('challenge_id'),
            'code' => $this->currentCodeFor($admin),
        ])->assertStatus(422)->assertJsonPath('code', 'mfa_challenge_expired');
    }
}
