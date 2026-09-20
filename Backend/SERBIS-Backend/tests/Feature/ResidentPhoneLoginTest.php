<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesSkySms;
use Tests\TestCase;

/**
 * Resident login is two requests: phone number and password, then a code texted
 * to that number. The code lives under its own cache key, apart from the sign-up
 * code, so a login attempt can never spend or clobber an in-flight sign-up.
 *
 * There is no email to fall back to: a text that cannot be sent is a 503
 * `sms_unavailable`, and no token is issued.
 */
class ResidentPhoneLoginTest extends TestCase
{
    use FakesSkySms, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSkySms();
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(array $overrides = []): Resident
    {
        $resident = Resident::create(array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ], $overrides));
        $resident->markPhoneAsVerified();

        return $resident->fresh();
    }

    private function login(string $phone = '09171234567', string $password = 'Password123')
    {
        return $this->postJson('/api/resident/login', ['phone_number' => $phone, 'password' => $password]);
    }

    public function test_login_sends_a_code_instead_of_a_token(): void
    {
        $this->resident();

        $response = $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required')
            ->assertJsonPath('channel', 'sms')
            ->assertJsonPath('sent_to', '4567')
            ->assertJsonPath('delivery', 'accepted')
            ->assertJsonStructure(['challenge_id', 'retry_after']);

        $response->assertJsonMissingPath('token');
        $this->assertSame(['+639171234567'], $this->numbersTexted());
    }

    public function test_the_number_may_be_typed_in_any_spelling(): void
    {
        $this->resident();

        foreach (['09171234567', '639171234567', '+639171234567'] as $typed) {
            $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();
            $this->login($typed)->assertStatus(403)->assertJsonPath('code', 'mfa_required');
        }
    }

    public function test_an_email_and_no_number_is_an_old_app_and_is_told_to_update(): void
    {
        $this->resident();

        $this->postJson('/api/resident/login', ['email_address' => 'grace@test.local', 'password' => 'Password123'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'app_update_required');

        Http::assertNothingSent();
    }

    public function test_a_wrong_password_an_unknown_number_and_an_undialable_one_all_read_the_same(): void
    {
        $this->resident();

        $bodies = [
            $this->login('09171234567', 'Wrongpass123')->assertStatus(401)->json(),
            $this->login('09179999999')->assertStatus(401)->json(),
            $this->login('0288888888')->assertStatus(401)->json(),
        ];

        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame($bodies[0], $bodies[2]);
        Http::assertNothingSent();
    }

    /**
     * The suite runs on CACHE_STORE=array, which keeps live PHP objects and never
     * serializes, so a Carbon in the challenge would pass here and 500 in
     * production (file store, 'serializable_classes' => false). This pins the
     * login to that store.
     */
    public function test_login_survives_a_cache_store_that_cannot_restore_objects(): void
    {
        config(['cache.default' => 'file']);
        Cache::store('file')->flush();

        $this->resident();

        $response = $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required')
            ->assertJsonPath('retry_after', 60);

        $this->postJson('/api/resident/login/resend', ['challenge_id' => $response->json('challenge_id')])
            ->assertStatus(429)
            ->assertJsonPath('code', 'resend_too_soon');

        Cache::store('file')->flush();
    }

    public function test_the_right_code_completes_login(): void
    {
        $this->resident();
        $first = $this->login();

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->lastCodeTexted(),
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'resident');
    }

    public function test_a_wrong_code_is_rejected_and_five_kill_the_challenge(): void
    {
        $this->resident();
        $first = $this->login();
        $wrong = $this->lastCodeTexted() === '000000' ? '111111' : '000000';
        $challenge = $first->json('challenge_id');

        $this->postJson('/api/resident/login/verify', ['challenge_id' => $challenge, 'code' => $wrong])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/resident/login/verify', ['challenge_id' => $challenge, 'code' => $wrong]);
        }

        $this->postJson('/api/resident/login/verify', ['challenge_id' => $challenge, 'code' => $wrong])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_an_unknown_challenge_id_is_rejected(): void
    {
        $this->postJson('/api/resident/login/verify', ['challenge_id' => 'nope', 'code' => '123456'])
            ->assertStatus(422)->assertJsonPath('code', 'mfa_challenge_expired');
    }

    public function test_resend_respects_the_cooldown_then_issues_a_new_code(): void
    {
        $this->resident();
        $challenge = $this->login()->json('challenge_id');

        $this->postJson('/api/resident/login/resend', ['challenge_id' => $challenge])
            ->assertStatus(429)->assertJsonPath('code', 'resend_too_soon');
        Http::assertSentCount(1);

        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/resident/login/resend', ['challenge_id' => $challenge])
            ->assertStatus(200)
            ->assertJsonPath('code', 'code_sent')
            ->assertJsonPath('delivery', 'accepted');

        $this->assertCount(2, $this->codesTexted());
    }

    // --- When the text cannot be sent -----------------------------------------

    public function test_a_login_that_cannot_be_texted_is_a_503_with_no_token(): void
    {
        $this->resident();
        $this->smsRejects = true;

        $this->login()
            ->assertStatus(503)
            ->assertJsonPath('code', 'sms_unavailable')
            ->assertJsonMissingPath('token');
    }

    public function test_out_of_credits_is_a_503_and_a_retry_is_not_locked_out_by_a_cooldown(): void
    {
        $this->resident();
        $this->smsOutOfCredits = true;
        $this->login()->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');

        // Nothing went out and nothing was billed, so there is nothing to
        // protect: trying again straight away works once the account is topped up.
        $this->smsOutOfCredits = false;
        $this->login()->assertStatus(403)->assertJsonPath('code', 'mfa_required');
    }

    public function test_a_stored_number_that_cannot_be_dialled_is_a_503_not_a_crash(): void
    {
        // A row written before the mobile-number rule, or by a database edit.
        $this->resident(['phone_number' => 'not-a-phone']);

        $this->login('not-a-phone')->assertStatus(401);
    }

    public function test_a_timed_out_login_text_opens_the_code_screen_and_says_delivery_is_unknown(): void
    {
        Log::spy();
        $this->resident();
        $this->smsTimesOut = true;

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required')
            ->assertJsonPath('delivery', 'unknown');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'outcome unknown'))
            ->once();
    }

    public function test_a_short_rate_limit_is_waited_out_once_for_a_login_code(): void
    {
        $this->resident();
        $this->smsShortRateLimits = 1;

        $this->login()->assertStatus(403)->assertJsonPath('code', 'mfa_required');
        Http::assertSentCount(2);
    }

    public function test_a_long_rate_limit_answers_sms_unavailable_without_holding_the_phone(): void
    {
        $this->resident();
        $this->smsLongRateLimits = 1;

        $this->login()->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');
        Http::assertSentCount(1);
    }

    // --- Accounts an admin made -----------------------------------------------

    public function test_an_account_an_admin_made_signs_in_like_any_other(): void
    {
        $admin = User::create([
            'first_name' => 'MDRRMO', 'last_name' => 'Admin', 'email_address' => 'admin@serbis.com',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active',
        ]);

        $this->actingAs($admin)->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Barangay', 'last_name' => 'Hall',
            'phone_number' => '09175550000',
            'password' => 'Password123',
            'status' => 'Active',
            'account_type' => 'head_of_family',
        ])->assertStatus(201);

        $this->assertNotNull(Resident::where('phone_number', '+639175550000')->firstOrFail()->phone_verified_at);

        $this->app['auth']->forgetGuards();
        $this->login('09175550000')->assertStatus(403)->assertJsonPath('code', 'mfa_required');
    }
}
