<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * serbis:send-return-reminders, extended to a confirmed ambulance booking
 * (MDRRMO feedback, 2026-09-17) — same independent-failure shape as the
 * equipment side, covered separately in SendReturnRemindersPushTest.
 */
class SendScheduledBookingRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Service $ambulance;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Manila'));

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        Cache::put('fcm_access_token', 'fake-access-token', 3000);

        $path = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($path, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $path]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function resident(string $phone = '09171111111'): Resident
    {
        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'email_address' => uniqid('resident').'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    /** A confirmed, scheduled booking — the only shape this command reminds about. */
    private function confirmedBooking(
        ?Resident $resident,
        Carbon $scheduledAt,
        array $overrides = [],
    ): AmbulanceBooking {
        $request = ServiceRequest::create([
            'resident_id' => $resident?->getKey(),
            'walk_in_name' => $resident ? null : 'Pedro Cruz',
            'walk_in_contact_number' => $resident ? null : '09172222222',
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
        ]);

        return AmbulanceBooking::create(array_merge([
            'request_id' => $request->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'destination' => 'Echague District Hospital',
            'scheduled_at' => $scheduledAt,
            'scheduled_end' => $scheduledAt->copy()->addHours(2),
            'approved_at' => now(),
        ], $overrides));
    }

    public function test_pushes_and_texts_a_booking_scheduled_tomorrow(): void
    {
        $resident = $this->resident();
        DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'skysms.skyio.site/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        $booking = $this->confirmedBooking($resident, now()->addDay()->setTime(14, 0));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSent(fn ($sent) => isset($sent['message']['notification'])
            && str_contains($sent['message']['notification']['body'], 'scheduled tomorrow')
            && str_contains($sent['message']['notification']['body'], '2:00 PM'));

        $this->assertNotNull($booking->fresh()->scheduled_reminder_sent_at);
    }

    /** A push failure must not stop the SMS from sending or from marking the row. */
    public function test_a_push_failure_does_not_affect_the_sms_side(): void
    {
        $resident = $this->resident();
        DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'skysms.skyio.site/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response([
                'error' => ['status' => 'UNAVAILABLE', 'message' => 'Server is overloaded.'],
            ], 503),
        ]);

        $booking = $this->confirmedBooking($resident, now()->addDay()->setTime(14, 0));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertNotNull($booking->fresh()->scheduled_reminder_sent_at, 'a push failure must not block the SMS side from marking the row sent');
    }

    /** A rejected SMS still leaves the row unmarked even though the push succeeded. */
    public function test_a_successful_push_does_not_mark_the_reminder_sent_when_the_sms_is_rejected(): void
    {
        $resident = $this->resident();
        DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'skysms.skyio.site/*' => Http::response(['success' => false], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        $booking = $this->confirmedBooking($resident, now()->addDay()->setTime(14, 0));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSent(fn ($sent) => isset($sent['message']['notification']));
        $this->assertNull($booking->fresh()->scheduled_reminder_sent_at);
    }

    /** No app account to push to, and this feedback item did not ask for an SMS-only walk-in path. */
    public function test_a_walk_in_booking_with_no_resident_is_skipped_entirely(): void
    {
        Http::fake([
            'skysms.skyio.site/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        $booking = $this->confirmedBooking(null, now()->addDay()->setTime(14, 0));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertNull($booking->fresh()->scheduled_reminder_sent_at);
    }

    /** Not yet confirmed — approve() has not run, so there is nothing settled to remind about. */
    public function test_an_unapproved_booking_is_skipped(): void
    {
        $resident = $this->resident();

        Http::fake([
            'skysms.skyio.site/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        $booking = $this->confirmedBooking($resident, now()->addDay()->setTime(14, 0), ['approved_at' => null]);

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertNull($booking->fresh()->scheduled_reminder_sent_at);
    }

    /** Already dispatched — the appointment has already happened or is happening, not a future thing to remind about. */
    public function test_a_booking_no_longer_in_booked_status_is_skipped(): void
    {
        $resident = $this->resident();

        Http::fake([
            'skysms.skyio.site/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        $booking = $this->confirmedBooking($resident, now()->addDay()->setTime(14, 0));
        $booking->serviceRequest->update(['status' => 'Responding']);

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertNull($booking->fresh()->scheduled_reminder_sent_at);
    }

    /** Same row is not reminded twice for the same appointment. */
    public function test_a_booking_already_reminded_is_not_reminded_again(): void
    {
        $resident = $this->resident();

        Http::fake([
            'skysms.skyio.site/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        $this->confirmedBooking($resident, now()->addDay()->setTime(14, 0), [
            'scheduled_reminder_sent_at' => now(),
        ]);

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
