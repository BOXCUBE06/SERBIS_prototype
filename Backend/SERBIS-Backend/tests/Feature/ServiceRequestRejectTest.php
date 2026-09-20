<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} rejecting a booking — extends update()
 * rather than a new route, since it only touches columns update() already
 * writes and syncFleet() already reconciles.
 *
 * preventStrayRequests() is on regardless — most tests here never configure
 * Fcm, so notifyResidentDevices() no-ops before any HTTP call, same as it
 * did for SkySMS before push replaced it.
 */
class ServiceRequestRejectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create([
            'request_id' => $this->request->getKey(),
            'scheduled_at' => Carbon::now('UTC')->addDays(2),
        ]);

        $this->actingAs($this->admin);
    }

    public function test_rejecting_without_remarks_is_refused(): void
    {
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
        ])->assertStatus(422)->assertJsonValidationErrors('remarks');

        $this->assertSame('Booked', $this->request->fresh()->status);
    }

    public function test_rejecting_with_remarks_succeeds(): void
    {
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk()->assertJsonPath('status', 'Disapproved');

        $fresh = $this->request->fresh();
        $this->assertSame('Disapproved', $fresh->status);
        $this->assertSame('No unit free for the requested window.', $fresh->remarks);
    }

    /**
     * The panel resends the current status on every PUT (see
     * ServiceRequestQueue.vue's updateStatus()), so a same-status resend of
     * Disapproved with a different remark must still update it.
     */
    public function test_resending_disapproved_updates_the_remarks(): void
    {
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk();

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'Duplicate submission, closing out.',
        ])->assertOk();

        $this->assertSame('Duplicate submission, closing out.', $this->request->fresh()->remarks);
    }

    /**
     * Bypasses the real OAuth2 mint the same way FcmSendTest does: primes
     * its cache key directly rather than trying to fake google/auth's own
     * Guzzle client, which Http::fake() cannot see.
     */
    private function configureFcm(): void
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

    public function test_rejecting_pushes_every_device_token_the_resident_has(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        DeviceToken::create([
            'resident_id' => $this->resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
        DeviceToken::create([
            'resident_id' => $this->resident->getKey(),
            'token' => 'device-2',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['message']['token'] === 'device-1'
            && str_contains($request['message']['notification']['body'], 'not approved')
            && str_contains($request['message']['notification']['body'], 'No unit free for the requested window.'));
        Http::assertSent(fn ($request) => $request['message']['token'] === 'device-2');

        $this->assertSame('Disapproved', $this->request->fresh()->status);
    }

    public function test_rejecting_with_no_device_tokens_is_a_no_op(): void
    {
        $this->configureFcm();

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertSame('Disapproved', $this->request->fresh()->status);
    }

    public function test_a_push_failure_does_not_affect_the_rejection(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response([
            'error' => ['status' => 'UNAVAILABLE', 'message' => 'Server is overloaded.'],
        ], 503)]);

        $deviceToken = DeviceToken::create([
            'resident_id' => $this->resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk()->assertJsonPath('status', 'Disapproved');

        $this->assertSame('Disapproved', $this->request->fresh()->status);
        // UNAVAILABLE is transient — the token itself is still good.
        $this->assertNotNull(DeviceToken::find($deviceToken->getKey()));
    }

    /** Walk-ins carry no resident_id — nothing to push to, same as the SMS path this replaced. */
    public function test_rejecting_a_walk_in_with_no_resident_sends_no_push(): void
    {
        $this->configureFcm();

        $walkIn = ServiceRequest::create([
            'service_id' => $this->service->service_id,
            'description' => 'Staff-filed walk-in',
            'status' => 'Booked',
        ]);
        AmbulanceBooking::create([
            'request_id' => $walkIn->getKey(),
            'scheduled_at' => Carbon::now('UTC')->addDays(2),
        ]);

        $this->putJson("/api/service-requests/{$walkIn->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'Duplicate walk-in entry.',
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertSame('Disapproved', $walkIn->fresh()->status);
    }
}
