<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The test-only OTP bypass for /resident/login/verify and
 * /resident/verify-email (config/serbis.php,
 * AuthController::otpBypassMatches()/logOtpBypassUse()) and the boot-time
 * guard that refuses to start anywhere but `local` with it configured
 * (AppServiceProvider::assertOtpBypassIsLocalOnly()).
 *
 * The login-flow tests swap the environment to 'local' with
 * detectEnvironment(), the same mechanism ProductionDebugGuardTest uses for
 * its own guard, and restore it in tearDown — the suite itself runs as
 * 'testing' (phpunit.xml) and must not leak a different environment into
 * whatever test runs next if one of these fails midway.
 */
class OtpBypassTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironment;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->app->environment();

        Mail::fake();
        Http::fake([
            'skysms.skyio.site/*' => fn () => Http::response(['status' => 'success'], 200),
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    protected function tearDown(): void
    {
        $restore = $this->originalEnvironment;
        $this->app->detectEnvironment(fn () => $restore);

        parent::tearDown();
    }

    private function asLocal(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
    }

    private function verifiedResident(): Resident
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
            'email_address' => 'grace@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $resident->markEmailAsVerified();

        return $resident->fresh();
    }

    private function lastCodeTexted(): string
    {
        preg_match('/[0-9]{6}/', Http::recorded()->last()[0]['message'] ?? '', $match);

        return $match[0] ?? '';
    }

    private function beginLogin(Resident $resident): string
    {
        $response = $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ]);

        return $response->json('challenge_id');
    }

    public function test_bypass_code_completes_login_when_enabled_in_local(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => '555555']);

        $resident = $this->verifiedResident();
        $challengeId = $this->beginLogin($resident);

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $challengeId,
            'code' => '555555',
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'resident');

        $this->assertDatabaseHas('tbl_system_logs', [
            'action_type' => 'otp_bypass_used',
            'auditable_type' => Resident::class,
            'auditable_id' => $resident->resident_id,
            'resident_id' => $resident->resident_id,
        ]);
    }

    public function test_bypass_code_is_rejected_when_config_is_unset(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => null]);

        $resident = $this->verifiedResident();
        $challengeId = $this->beginLogin($resident);

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $challengeId,
            'code' => '555555',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->assertDatabaseMissing('tbl_system_logs', [
            'action_type' => 'otp_bypass_used',
        ]);
    }

    public function test_bypass_code_is_rejected_in_production_even_if_configured(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['serbis.otp_bypass_code' => '555555']);

        $resident = $this->verifiedResident();
        $challengeId = $this->beginLogin($resident);

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $challengeId,
            'code' => '555555',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_bypass_code_is_rejected_outside_local_even_if_configured(): void
    {
        foreach (['staging', 'testing'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            config(['serbis.otp_bypass_code' => '555555']);

            $resident = Resident::where('email_address', 'grace@test.local')->first() ?? $this->verifiedResident();
            $challengeId = $this->beginLogin($resident);

            $this->postJson('/api/resident/login/verify', [
                'challenge_id' => $challengeId,
                'code' => '555555',
            ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        }
    }

    private function beginSignup(string $email = 'new@test.local'): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Lito',
            'last_name' => 'Garcia',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234568',
            'email_address' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);
    }

    public function test_bypass_code_completes_signup_when_enabled_in_local(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => '555555']);

        $this->beginSignup();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'new@test.local',
            'code' => '555555',
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'resident');

        $resident = Resident::where('email_address', 'new@test.local')->firstOrFail();
        $this->assertTrue($resident->hasVerifiedEmail());
        $this->assertDatabaseHas('tbl_system_logs', [
            'action_type' => 'otp_bypass_used',
            'auditable_id' => $resident->resident_id,
        ]);
    }

    public function test_signup_bypass_is_rejected_when_config_is_unset(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => null]);

        $this->beginSignup();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'new@test.local',
            'code' => '555555',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->assertDatabaseMissing('tbl_residents', ['email_address' => 'new@test.local']);
    }

    public function test_signup_bypass_is_rejected_outside_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['serbis.otp_bypass_code' => '555555']);

        $this->beginSignup();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'new@test.local',
            'code' => '555555',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->assertDatabaseMissing('tbl_system_logs', ['action_type' => 'otp_bypass_used']);
    }

    public function test_real_code_still_works_when_bypass_is_configured(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => '555555']);

        $resident = $this->verifiedResident();
        $challengeId = $this->beginLogin($resident);

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $challengeId,
            'code' => $this->lastCodeTexted(),
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'resident');

        $this->assertDatabaseMissing('tbl_system_logs', [
            'action_type' => 'otp_bypass_used',
        ]);
    }

    public function test_real_code_still_works_when_bypass_is_unset(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => null]);

        $resident = $this->verifiedResident();
        $challengeId = $this->beginLogin($resident);

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $challengeId,
            'code' => $this->lastCodeTexted(),
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'resident');
    }

    private function runGuard(string $environment, ?string $bypassCode): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['serbis.otp_bypass_code' => $bypassCode]);

        AppServiceProvider::assertOtpBypassIsLocalOnly();
    }

    public function test_boot_refuses_when_bypass_is_set_outside_local(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            try {
                $this->runGuard($environment, '555555');
                $this->fail("The guard allowed the bypass in {$environment}.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('REFUSING TO START', $e->getMessage());
            }
        }
    }

    public function test_boot_allows_any_environment_when_bypass_is_unset(): void
    {
        foreach (['production', 'staging', 'testing', 'local'] as $environment) {
            $this->runGuard($environment, null);
        }

        $this->assertTrue(true, 'No environment was blocked without the bypass set.');
    }

    public function test_boot_allows_the_bypass_in_local(): void
    {
        $this->runGuard('local', '555555');

        $this->assertTrue(true, 'The guard allowed the bypass in local.');
    }
}
