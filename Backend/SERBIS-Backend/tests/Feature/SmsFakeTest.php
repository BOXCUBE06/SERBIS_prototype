<?php

namespace Tests\Feature;

use App\Mail\ResidentLoginCode;
use App\Models\Barangay;
use App\Models\Resident;
use App\Providers\AppServiceProvider;
use App\Services\PhilSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The test-only SMS suppression flag (config/serbis.php, PhilSms::send()) and
 * the boot-time guard that refuses to start a production deployment with it
 * configured (AppServiceProvider::assertSmsFakeIsUnsetInProduction()).
 *
 * Same environment-swap mechanism as OtpBypassTest: detectEnvironment() to
 * 'local' for the send-path tests, restored in tearDown so a failure midway
 * cannot leak a different environment into whatever test runs next.
 */
class SmsFakeTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironment;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->app->environment();

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

    public function test_faked_send_makes_no_http_call(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => true]);
        Http::fake();

        $response = app(PhilSms::class)->send(['09171234567'], 'Test message');

        Http::assertNothingSent();
        $this->assertTrue(PhilSms::accepted($response));
    }

    public function test_real_send_unaffected_when_flag_is_unset(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => false]);
        Http::fake([
            'dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200),
        ]);

        $response = app(PhilSms::class)->send(['09171234567'], 'Test message');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'dashboard.philsms.com'));
        $this->assertTrue(PhilSms::accepted($response));
    }

    public function test_flag_is_rejected_in_production_even_if_configured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSING TO START');

        $this->app->detectEnvironment(fn () => 'production');
        config(['serbis.sms_fake' => true]);

        AppServiceProvider::assertSmsFakeIsUnsetInProduction();
    }

    public function test_boot_allows_production_when_flag_is_unset(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['serbis.sms_fake' => false]);

        AppServiceProvider::assertSmsFakeIsUnsetInProduction();

        $this->assertTrue(true, 'The guard allowed a correctly configured production boot.');
    }

    public function test_mail_fallback_does_not_fire_on_a_faked_send(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => true]);
        Mail::fake();
        Http::fake();

        $resident = $this->verifiedResident();

        $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ])->assertStatus(403)->assertJsonPath('code', 'mfa_required');

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_mail_fallback_still_fires_when_flag_is_unset_and_sms_is_rejected(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => false]);
        Mail::fake();
        Http::fake([
            'dashboard.philsms.com/*' => Http::response(['status' => 'error'], 200),
        ]);

        $resident = $this->verifiedResident();

        $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'Password123',
        ])->assertStatus(403)->assertJsonPath('code', 'mfa_required');

        Mail::assertSent(ResidentLoginCode::class);
    }
}
