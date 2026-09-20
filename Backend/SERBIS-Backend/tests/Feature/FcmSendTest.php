<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Resident;
use App\Services\Fcm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * App\Services\Fcm — HTTP v1 send, best-effort. The OAuth2 token mint
 * (google/auth's own HTTP client, not Laravel's Http facade) is bypassed by
 * priming its cache key directly: nothing here should ever make a real
 * network call to Google.
 */
class FcmSendTest extends TestCase
{
    use RefreshDatabase;

    private DeviceToken $deviceToken;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::put('fcm_access_token', 'fake-access-token', 3000);

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($this->credentialsPath, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $this->credentialsPath]);

        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->deviceToken = DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => 'fcm-device-token',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    public function test_configured_is_false_without_a_credentials_path(): void
    {
        config(['services.firebase.credentials' => null]);

        $this->assertFalse(Fcm::configured());
    }

    public function test_configured_is_false_when_the_file_does_not_exist(): void
    {
        config(['services.firebase.credentials' => '/nowhere/does-not-exist.json']);

        $this->assertFalse(Fcm::configured());
    }

    public function test_configured_is_true_with_a_real_file(): void
    {
        $this->assertTrue(Fcm::configured());
    }

    public function test_not_configured_sends_nothing_and_does_not_throw(): void
    {
        config(['services.firebase.credentials' => null]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        Http::assertNothingSent();
        $this->assertNotNull(DeviceToken::find($this->deviceToken->getKey()));
    }

    /**
     * The one branch that used to leave zero trace — every other failure
     * path logs, this one silently returned. A missing credential on a real
     * deploy must not look identical to "everything is fine, nothing to
     * send" in the logs.
     */
    public function test_logs_a_warning_when_no_credentials_path_is_set(): void
    {
        Log::spy();
        config(['services.firebase.credentials' => null]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'FCM push skipped: not configured'
                && $context['reason'] === 'FIREBASE_CREDENTIALS is not set');
    }

    public function test_logs_a_warning_naming_the_missing_file_when_the_path_is_set_but_wrong(): void
    {
        Log::spy();
        config(['services.firebase.credentials' => '/nowhere/does-not-exist.json']);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'FCM push skipped: not configured'
                && str_contains($context['reason'], '/nowhere/does-not-exist.json')
                && str_contains($context['reason'], 'does not exist'));
    }

    public function test_sends_to_the_projects_endpoint_with_the_token_title_and_body(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Booking approved', 'Unit AMB-01 is on the way.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/serbis-test-project/messages:send'
                && $request->hasHeader('Authorization', 'Bearer fake-access-token')
                && $request['message']['token'] === 'fcm-device-token'
                && $request['message']['notification']['title'] === 'Booking approved'
                && $request['message']['notification']['body'] === 'Unit AMB-01 is on the way.';
        });

        $this->assertNotNull(DeviceToken::find($this->deviceToken->getKey()));
    }

    /**
     * The one success path that used to leave zero trace — "FCM accepted
     * this" and "never attempted" were both silence in the logs.
     */
    public function test_logs_the_message_name_on_a_successful_send(): void
    {
        Log::spy();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'FCM send accepted'
                && $context['device_token_id'] === $this->deviceToken->getKey()
                && $context['message_name'] === 'projects/x/messages/0:1');
    }

    public function test_sends_the_data_payload_when_given_one(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        (new Fcm)->sendToDevice(
            $this->deviceToken,
            'Booking approved',
            'Unit AMB-01 is on the way.',
            ['request_id' => '42', 'service_type' => 'Ambulance/Medical Response'],
        );

        Http::assertSent(function ($request) {
            return $request['message']['data']['request_id'] === '42'
                && $request['message']['data']['service_type'] === 'Ambulance/Medical Response';
        });
    }

    /** No data key at all, not an empty one — an absent key and {} are not the same wire shape for "nothing extra". */
    public function test_omits_the_data_key_entirely_when_none_is_given(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        Http::assertSent(fn ($request) => ! array_key_exists('data', $request['message']));
    }

    public function test_deletes_the_token_when_fcm_reports_it_unregistered(): void
    {
        Log::spy();
        $tokenId = $this->deviceToken->getKey();
        $residentId = $this->deviceToken->resident_id;
        Http::fake(['fcm.googleapis.com/*' => Http::response([
            'error' => ['status' => 'UNREGISTERED', 'message' => 'Requested entity was not found.'],
        ], 404)]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        $this->assertNull(DeviceToken::find($tokenId));
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'FCM device token deleted'
                && $context['device_token_id'] === $tokenId
                && $context['resident_id'] === $residentId
                && $context['reason'] === 'UNREGISTERED');
    }

    public function test_deletes_the_token_when_fcm_reports_it_invalid(): void
    {
        Log::spy();
        $tokenId = $this->deviceToken->getKey();
        Http::fake(['fcm.googleapis.com/*' => Http::response([
            'error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token.'],
        ], 400)]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        $this->assertNull(DeviceToken::find($tokenId));
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'FCM device token deleted'
                && $context['reason'] === 'INVALID_ARGUMENT');
    }

    /** UNAVAILABLE is FCM saying "try again later", not "this token is dead". */
    public function test_leaves_the_token_alone_on_a_transient_fcm_error(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response([
            'error' => ['status' => 'UNAVAILABLE', 'message' => 'Server is overloaded.'],
        ], 503)]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        $this->assertNotNull(DeviceToken::find($this->deviceToken->getKey()));
    }

    public function test_a_network_failure_does_not_throw_and_leaves_the_token_alone(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        $this->assertNotNull(DeviceToken::find($this->deviceToken->getKey()));
    }

    public function test_a_malformed_credentials_file_does_not_throw(): void
    {
        $badPath = tempnam(sys_get_temp_dir(), 'fcm_bad_');
        // Missing client_email/private_key — ServiceAccountCredentials throws
        // building the object, before any HTTP call is attempted.
        file_put_contents($badPath, json_encode(['project_id' => 'serbis-test-project']));
        config(['services.firebase.credentials' => $badPath]);

        (new Fcm)->sendToDevice($this->deviceToken, 'Title', 'Body');

        @unlink($badPath);
        Http::assertNothingSent();
        $this->assertNotNull(DeviceToken::find($this->deviceToken->getKey()));
    }
}
