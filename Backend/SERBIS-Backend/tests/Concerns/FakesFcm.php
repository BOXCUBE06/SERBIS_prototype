<?php

namespace Tests\Concerns;

use App\Models\DeviceToken;
use App\Models\Resident;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * What a test needs to push through App\Services\Fcm without a Google account:
 * a credentials file that exists, a cached access token so no OAuth exchange is
 * attempted, and a device row to send to. FCM's own host still has to be faked
 * per test (fcmAccepts() / fcmRefuses()), and Http::preventStrayRequests() makes
 * a send that escapes the fake fail instead of leaving the machine.
 */
trait FakesFcm
{
    protected function configureFcm(): void
    {
        Cache::put('fcm_access_token', 'fake-access-token', 3000);

        $path = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($path, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $path]);
    }

    protected function deviceFor(Resident $resident, string $token = 'device-1'): DeviceToken
    {
        return DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
    }

    protected function fcmAccepts(): PromiseInterface
    {
        return Http::response(['name' => 'projects/x/messages/0:1'], 200);
    }

    /** A transient refusal: the token stays, the push is not accepted. */
    protected function fcmRefuses(): PromiseInterface
    {
        return Http::response(['error' => ['status' => 'UNAVAILABLE', 'message' => 'Server is overloaded.']], 503);
    }
}
