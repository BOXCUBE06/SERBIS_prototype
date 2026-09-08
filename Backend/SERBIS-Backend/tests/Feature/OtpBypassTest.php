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
 * The test-only OTP bypass for /resident/login/verify (config/serbis.php,
 * AuthController::otpBypassMatches()/logOtpBypassUse()) and the boot-time
 * guard that refuses to start a production deployment with it configured
 * (AppServiceProvider::assertOtpBypassIsUnsetInProduction()).
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
            'dashboard.philsms.com/*' => fn () => Http::response(['status' => 'success'], 200),
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

        AppServiceProvider::assertOtpBypassIsUnsetInProduction();
    }

    public function test_boot_refuses_when_bypass_is_set_in_production(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSING TO START');

        $this->runGuard('production', '555555');
    }

    public function test_boot_allows_production_when_bypass_is_unset(): void
    {
        $this->runGuard('production', null);

        $this->assertTrue(true, 'The guard allowed a correctly configured production boot.');
    }

    public function test_boot_allows_the_bypass_outside_production(): void
    {
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->runGuard($environment, '555555');
        }

        $this->assertTrue(true, 'No non-production environment was blocked.');
    }
}
