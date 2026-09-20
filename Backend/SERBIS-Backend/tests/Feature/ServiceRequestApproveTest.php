<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PATCH /api/service-requests/{id}/approve — its own route, not update(),
 * because it re-checks ambulance availability under a lock that update() was
 * never built to take.
 *
 * preventStrayRequests() is on regardless — most tests here never configure
 * Fcm, so notifyResidentDevices() no-ops before any HTTP call, same as it
 * did for SkySMS before push replaced it.
 */
class ServiceRequestApproveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

    private Vehicle $amb01;

    private Vehicle $amb02;

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

        $this->amb01 = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $this->amb02 = Vehicle::create([
            'unit_identifier' => 'AMB-02',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);
    }

    private function bookedRequest(?Carbon $scheduledAt = null): ServiceRequest
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => $scheduledAt ?? Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0),
        ]);

        return $request;
    }

    public function test_approving_assigns_the_unit_and_keeps_status_booked(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($target);

        $response = $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertOk();

        $response->assertJsonPath('status', 'Booked')
            ->assertJsonPath('vehicle_id', $this->amb01->vehicle_id);

        $fresh = $request->fresh();
        $this->assertSame($this->amb01->vehicle_id, $fresh->vehicle_id);
        $this->assertNotNull($fresh->ambulanceBooking->approved_at);
        $this->assertSame($this->admin->getKey(), $fresh->processed_by);
        $this->assertTrue($fresh->ambulanceBooking->scheduled_end->utc()->equalTo($target->copy()->addHours(2)));

        // Approval reserves the window, not the vehicle physically leaving —
        // it must stay Available until an actual dispatch.
        $this->assertSame('Available', $this->amb01->fresh()->status);
    }

    public function test_approving_accepts_a_staff_adjusted_scheduled_end(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($target);
        $customEnd = $target->copy()->addHours(3);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
            'scheduled_end' => $customEnd->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertTrue($request->fresh()->ambulanceBooking->scheduled_end->utc()->equalTo($customEnd));
    }

    public function test_approving_refuses_a_maintenance_unit(): void
    {
        $this->amb01->update(['status' => 'Maintenance']);
        $request = $this->bookedRequest();

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);
    }

    public function test_approving_refuses_a_request_that_is_not_booked(): void
    {
        $request = $this->bookedRequest();
        $request->update(['status' => 'Pending']);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertStatus(422);
    }

    /** The window filled between submission and approval — the exact race this endpoint's lock exists for. */
    public function test_approving_into_a_window_that_filled_after_submission_is_refused(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($target);

        // Someone else got AMB-01 for the same window in the meantime.
        $conflicting = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Another booking that beat this one to approval',
            'status' => 'Booked',
            'vehicle_id' => $this->amb01->vehicle_id,
        ]);

        AmbulanceBooking::create([
            'request_id' => $conflicting->getKey(),
            'scheduled_at' => $target->copy(),
            'scheduled_end' => $target->copy()->addHours(2),
        ]);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);

        // AMB-02 was never touched — still assignable in a follow-up call.
        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb02->vehicle_id,
        ])->assertOk();
    }

    /**
     * approve() can be called again on an already-Booked request — swapping
     * the assigned unit before dispatch is legitimate — and approved_at
     * still records the original approval, not the swap.
     */
    public function test_re_approving_to_swap_the_unit_keeps_the_original_approved_at(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($target);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertOk();

        $firstApprovedAt = $request->fresh()->ambulanceBooking->approved_at;
        $this->assertNotNull($firstApprovedAt);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb02->vehicle_id,
        ])->assertOk()->assertJsonPath('vehicle_id', $this->amb02->vehicle_id);

        // The swap itself still happened...
        $this->assertSame($this->amb02->vehicle_id, $request->fresh()->vehicle_id);
        $this->assertSame('Available', $this->amb01->fresh()->status);
        // ...but approved_at records the original approval, not the swap.
        $this->assertTrue($firstApprovedAt->equalTo($request->fresh()->ambulanceBooking->approved_at));
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

    public function test_approving_pushes_every_device_token_the_resident_has(): void
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

        $request = $this->bookedRequest();

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-1'
            && str_contains($sent['message']['notification']['body'], 'AMB-01')
            && str_contains($sent['message']['notification']['body'], 'approved'));
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-2');

        $this->assertSame($this->amb01->vehicle_id, $request->fresh()->vehicle_id);
    }

    public function test_approving_with_no_device_tokens_is_a_no_op(): void
    {
        $this->configureFcm();

        $request = $this->bookedRequest();

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertSame($this->amb01->vehicle_id, $request->fresh()->vehicle_id);
    }

    public function test_a_push_failure_does_not_affect_the_approval(): void
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

        $request = $this->bookedRequest();

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertOk()->assertJsonPath('status', 'Booked');

        $this->assertSame($this->amb01->vehicle_id, $request->fresh()->vehicle_id);
        // UNAVAILABLE is transient — the token itself is still good.
        $this->assertNotNull(DeviceToken::find($deviceToken->getKey()));
    }
}
