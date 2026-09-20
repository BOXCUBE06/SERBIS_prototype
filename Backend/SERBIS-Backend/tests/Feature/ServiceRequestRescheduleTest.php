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
 * PATCH /api/service-requests/{id}/reschedule — its own route for the same
 * reason approve() gets one: a locked availability re-check update() cannot
 * do.
 *
 * preventStrayRequests() is on regardless — most tests here never configure
 * Fcm, so notifyResidentDevices() no-ops before any HTTP call, same as it
 * did for SkySMS before push replaced it.
 */
class ServiceRequestRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

    private Vehicle $amb01;

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

        $this->actingAs($this->admin);
    }

    private function bookedRequest(?Carbon $scheduledAt = null, ?Vehicle $vehicle = null): ServiceRequest
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
            'vehicle_id' => $vehicle?->vehicle_id,
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => $scheduledAt ?? Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0),
        ]);

        return $request;
    }

    private function manilaString(Carbon $instant): string
    {
        return $instant->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s');
    }

    public function test_admin_reschedules_an_unapproved_booking(): void
    {
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);
        $newEnd = $newStart->copy()->addHours(2);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newEnd),
            'remarks' => 'Resident asked to move the pickup later.',
        ])->assertOk();

        $fresh = $request->fresh();
        $this->assertTrue($fresh->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
        $this->assertTrue($fresh->ambulanceBooking->scheduled_end->utc()->equalTo($newEnd));
        $this->assertSame('Resident asked to move the pickup later.', $fresh->remarks);
    }

    public function test_reschedule_requires_remarks(): void
    {
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
        ])->assertStatus(422)->assertJsonValidationErrors('remarks');
    }

    public function test_reschedule_is_refused_when_no_unit_is_free_for_the_new_window(): void
    {
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);
        $newEnd = $newStart->copy()->addHours(2);

        // The only Ambulance unit is already committed elsewhere for that window.
        $conflicting = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Another booking',
            'status' => 'Booked',
            'vehicle_id' => $this->amb01->vehicle_id,
        ]);

        AmbulanceBooking::create([
            'request_id' => $conflicting->getKey(),
            'scheduled_at' => $newStart->copy(),
            'scheduled_end' => $newEnd->copy(),
        ]);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newEnd),
            'remarks' => 'Trying to move it anyway.',
        ])->assertStatus(422);

        $this->assertNotNull($request->fresh()->ambulanceBooking->scheduled_at);
    }

    /**
     * Approved bookings keep their own unit through a reschedule as long as
     * that unit is still free for the new time — the point of excluding this
     * request's own row from the availability check, since otherwise its own
     * not-yet-updated window would count as a conflict against itself.
     */
    public function test_rescheduling_an_approved_booking_keeps_its_own_unit(): void
    {
        $originalStart = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($originalStart, $this->amb01);
        $request->ambulanceBooking->update(['scheduled_end' => $originalStart->copy()->addHours(2)]);

        // Overlaps the original window — without self-exclusion this would
        // wrongly read as "AMB-01 is busy" against its own old booking.
        $newStart = $originalStart->copy()->addHour();
        $newEnd = $newStart->copy()->addHours(2);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newEnd),
            'remarks' => 'Pushed back an hour at the office\'s request.',
        ])->assertOk();

        $fresh = $request->fresh();
        $this->assertSame($this->amb01->vehicle_id, $fresh->vehicle_id);
        $this->assertTrue($fresh->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
    }

    /**
     * SkySMS bills per segment and has no sandbox, so the length of this body
     * is a cost, not a cosmetic detail. `remarks` was `required|string` with no
     * ceiling while SmsController::sendBlast had capped its own message at 160
     * from the start — the two paths that text one resident simply never got
     * the same treatment.
     */
    public function test_an_over_long_remark_is_refused_rather_than_billed(): void
    {
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => str_repeat('a', 161),
        ])->assertStatus(422)->assertJsonValidationErrors('remarks');
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

    public function test_rescheduling_pushes_every_device_token_the_resident_has(): void
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
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => 'Resident asked to move the pickup later.',
        ])->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-1'
            && str_contains($sent['message']['notification']['body'], 'moved to')
            && str_contains($sent['message']['notification']['body'], 'Resident asked to move the pickup later.'));
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-2');

        $this->assertTrue($request->fresh()->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
    }

    public function test_rescheduling_with_no_device_tokens_is_a_no_op(): void
    {
        $this->configureFcm();

        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => 'Resident asked to move the pickup later.',
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertTrue($request->fresh()->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
    }

    public function test_a_push_failure_does_not_affect_the_reschedule(): void
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
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => 'Resident asked to move the pickup later.',
        ])->assertOk();

        $this->assertTrue($request->fresh()->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
        // UNAVAILABLE is transient — the token itself is still good.
        $this->assertNotNull(DeviceToken::find($deviceToken->getKey()));
    }
}
