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
 * /resident/verify-phone (config/serbis.php,
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

        $resident->markPhoneAsVerified();

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
            'phone_number' => $resident->phone_number,
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

    private function beginSignup(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Lito',
            'last_name' => 'Garcia',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234568',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);
    }

    public function test_bypass_code_completes_signup_when_enabled_in_local(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => '555555']);

        $this->beginSignup();

        $this->postJson('/api/resident/verify-phone', [
            'phone_number' => '09171234568',
            'code' => '555555',
        ])->assertStatus(200)
            ->assertJsonStructure(['token'])
            ->assertJsonPath('role', 'resident');

        $resident = Resident::where('phone_number', '+639171234568')->firstOrFail();
        $this->assertTrue($resident->hasVerifiedPhone());
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

        $this->postJson('/api/resident/verify-phone', [
            'phone_number' => '09171234568',
            'code' => '555555',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->assertDatabaseMissing('tbl_residents', ['phone_number' => '+639171234568']);
    }

    public function test_signup_bypass_is_rejected_outside_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['serbis.otp_bypass_code' => '555555']);

        $this->beginSignup();

        $this->postJson('/api/resident/verify-phone', [
            'phone_number' => '09171234568',
            'code' => '555555',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->assertDatabaseMissing('tbl_system_logs', ['action_type' => 'otp_bypass_used']);
    }

    /**
     * A developer machine has no SMS key. Without the bypass that would be
     * `sms_unavailable` on every sign-up, so on a local machine with the bypass
     * code set the missing key is not a failure — the bypass code is how that
     * setup finishes a sign-up. Local only, and only for that one reason.
     */
    public function test_a_local_machine_with_no_sms_key_and_the_bypass_set_still_reaches_the_code_screen(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => '555555', 'services.skysms.api_key' => null]);
        Http::fake();

        $this->beginSignup();

        $this->postJson('/api/resident/verify-phone', ['phone_number' => '09171234568', 'code' => '555555'])
            ->assertStatus(200)
            ->assertJsonStructure(['token']);

        Http::assertNothingSent();
    }

    public function test_no_sms_key_without_the_bypass_is_sms_unavailable_even_locally(): void
    {
        $this->asLocal();
        config(['serbis.otp_bypass_code' => null, 'services.skysms.api_key' => null]);

        $this->postJson('/api/register', [
            'first_name' => 'Lito', 'last_name' => 'Garcia',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234568',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');
    }

    public function test_no_sms_key_is_sms_unavailable_in_production_even_with_the_bypass_configured(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['serbis.otp_bypass_code' => '555555', 'services.skysms.api_key' => null]);

        $this->postJson('/api/register', [
            'first_name' => 'Lito', 'last_name' => 'Garcia',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234568',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');
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
