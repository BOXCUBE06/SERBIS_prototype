<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Providers\AppServiceProvider;
use App\Services\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The test-only SMS suppression flag (config/serbis.php, SkySmsGateway) and
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

        $resident->markPhoneAsVerified();

        return $resident->fresh();
    }

    public function test_faked_send_makes_no_http_call(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => true]);
        Http::fake();

        $result = app(SmsGateway::class)->sendOne('09171234567', 'Test message');

        Http::assertNothingSent();
        $this->assertTrue($result->isAccepted());
    }

    public function test_faked_bulk_send_makes_no_http_call_either(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => true]);
        Http::fake();

        $result = app(SmsGateway::class)->sendBulk(['09171234567', '09171234568'], 'Test message');

        Http::assertNothingSent();
        $this->assertTrue($result->isAccepted());
    }

    public function test_real_send_unaffected_when_flag_is_unset(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => false]);
        Http::fake([
            'skysms.skyio.site/*' => Http::response(['success' => true], 200),
        ]);

        $result = app(SmsGateway::class)->sendOne('09171234567', 'Test message');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'skysms.skyio.site'));
        $this->assertTrue($result->isAccepted());
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

    public function test_a_faked_send_still_lets_a_login_reach_its_code_prompt(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => true]);
        Http::fake();

        $resident = $this->verifiedResident();

        $this->postJson('/api/resident/login', [
            'phone_number' => $resident->phone_number,
            'password' => 'Password123',
        ])->assertStatus(403)->assertJsonPath('code', 'mfa_required');

        Http::assertNothingSent();
    }

    public function test_a_rejected_send_is_sms_unavailable_when_the_flag_is_unset(): void
    {
        $this->asLocal();
        config(['serbis.sms_fake' => false]);
        Http::fake([
            'skysms.skyio.site/*' => Http::response(['success' => false], 200),
        ]);

        $resident = $this->verifiedResident();

        // There is no email to fall back to.
        $this->postJson('/api/resident/login', [
            'phone_number' => $resident->phone_number,
            'password' => 'Password123',
        ])->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');
    }
}
