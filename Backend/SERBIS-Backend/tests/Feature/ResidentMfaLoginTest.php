<?php

namespace Tests\Feature;

use App\Mail\ResidentLoginCode;
use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Resident login is now two requests: password, then an SMS (or mail
 * fallback) code — separate from the signup verification_code columns on
 * purpose, so a login attempt can never spend or clobber an in-flight signup
 * code.
 */
class ResidentMfaLoginTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    private string $smsStatus = 'success';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake([
            'dashboard.philsms.com/*' => fn () => Http::response(['status' => $this->smsStatus], 200),
        ]);
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function verifiedResident(array $overrides = []): Resident
    {
        $resident = Resident::create(array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
            'email_address' => 'grace@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ], $overrides));

        $resident->markEmailAsVerified();

        return $resident->fresh();
    }

    private function lastCodeTexted(): string
    {
        preg_match('/[0-9]{6}/', Http::recorded()->last()[0]['message'] ?? '', $match);

        return $match[0] ?? '';
    }

    public function test_login_sends_a_code_instead_of_a_token(): void
    {
        $resident = $this->verifiedResident();

        $response = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required')
            ->assertJsonPath('channel', 'sms')
            ->assertJsonStructure(['challenge_id', 'sent_to', 'retry_after']);

        $response->assertJsonMissingPath('token');
    }

    /**
     * The suite runs on CACHE_STORE=array (phpunit.xml), which keeps live PHP
     * objects and never serializes, so every other test here passed while
     * production 500d. Production runs the file store, and config/cache.php
     * sets 'serializable_classes' => false, so FileStore::get() unserializes
     * with allowed_classes => false: any object put in the challenge comes back
     * as __PHP_Incomplete_Class. This test pins the login to that store so a
     * Carbon (or any other object) put in the challenge payload fails here
     * rather than on a resident's phone.
     */
    public function test_login_survives_a_cache_store_that_cannot_restore_objects(): void
    {
        config(['cache.default' => 'file']);
        Cache::store('file')->flush();

        $resident = $this->verifiedResident();

        $response = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required')
            ->assertJsonPath('retry_after', 60);

        // The resend cooldown has to survive the round trip too — it is read
        // back out of the cache, which is where the TypeError came from.
        $this->postJson('/api/resident/login/resend', [
            'challenge_id' => $response->json('challenge_id'),
        ])->assertStatus(429)->assertJsonPath('code', 'resend_too_soon');

        Cache::store('file')->flush();
    }

    public function test_correct_code_completes_login(): void
    {
        $resident = $this->verifiedResident();

        $first = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->lastCodeTexted(),
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'resident');
    }

    public function test_wrong_code_is_rejected(): void
    {
        $resident = $this->verifiedResident();

        $first = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_five_wrong_codes_kill_the_challenge(): void
    {
        $resident = $this->verifiedResident();

        $first = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/resident/login/verify', [
                'challenge_id' => $first->json('challenge_id'),
                'code' => '000000',
            ]);
        }

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->lastCodeTexted(),
        ])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_resend_respects_the_cooldown(): void
    {
        $resident = $this->verifiedResident();

        $first = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        $this->postJson('/api/resident/login/resend', [
            'challenge_id' => $first->json('challenge_id'),
        ])->assertStatus(429)->assertJsonPath('code', 'resend_too_soon');
    }

    public function test_resend_issues_a_new_code_once_the_cooldown_clears(): void
    {
        $resident = $this->verifiedResident();

        $first = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);
        $firstCode = $this->lastCodeTexted();

        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/resident/login/resend', [
            'challenge_id' => $first->json('challenge_id'),
        ])->assertStatus(200)->assertJsonPath('code', 'code_sent');

        $secondCode = $this->lastCodeTexted();
        $this->assertNotSame($firstCode, $secondCode);

        // The old code no longer works — resend replaced it, not added to it.
        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $firstCode,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $secondCode,
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_falls_back_to_email_when_the_number_will_not_normalize(): void
    {
        $resident = $this->verifiedResident(['phone_number' => 'not-a-number', 'email_address' => 'nolanding@test.local']);

        $response = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        $response->assertStatus(403)->assertJsonPath('channel', 'email');
        Mail::assertSent(ResidentLoginCode::class);
    }

    public function test_an_unknown_challenge_id_is_rejected(): void
    {
        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => 'not-a-real-challenge',
            'code' => '123456',
        ])->assertStatus(422)->assertJsonPath('code', 'mfa_challenge_expired');
    }
}
