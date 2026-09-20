<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * serbis:send-return-reminders — the push channel added alongside the
 * existing SMS one. The two fail independently: neither's outcome is
 * allowed to affect the other, and only an accepted SMS send may set
 * return_reminder_sent_at — see the command's own class docblock.
 */
class SendReturnRemindersPushTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Manila'));

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 3,
            'status' => 'Available',
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

    private function released(Resident $resident, string $dueDate, array $overrides = []): EquipmentBorrowing
    {
        return EquipmentBorrowing::create(array_merge([
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Released',
            'due_date' => $dueDate,
            'released_at' => now(),
        ], $overrides));
    }

    public function test_pushes_and_texts_a_borrowing_due_tomorrow(): void
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

        $this->released($resident, now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSent(fn ($sent) => isset($sent['message']['notification'])
            && str_contains($sent['message']['notification']['body'], 'Rubber Boat')
            && str_contains($sent['message']['notification']['body'], 'due back tomorrow'));
    }

    /** The resident has no SMS-reachable number, but the push is attempted anyway — the two channels do not gate each other. */
    public function test_push_still_fires_when_the_resident_has_no_reachable_phone_number(): void
    {
        $resident = $this->resident('not-a-phone');
        DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        $borrowing = $this->released($resident, now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders');

        Http::assertSent(fn ($sent) => isset($sent['message']['notification']));
        // SMS was never reachable, so the row stays unmarked regardless of the push.
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);
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

        $borrowing = $this->released($resident, now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at, 'a push failure must not block the SMS side from marking the row sent');
    }

    /** A rejected SMS still leaves the row unmarked even though the push succeeded — a successful push cannot mark it sent on its own. */
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

        $borrowing = $this->released($resident, now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        // The push went out (FCM responded 200)...
        Http::assertSent(fn ($sent) => isset($sent['message']['notification']));
        // ...but the row is unmarked because the SMS side was rejected.
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);
    }

    /** A resident with no registered device gets the SMS with no push attempted against anything — notifyResident() no-ops on an empty token set, harmlessly. */
    public function test_a_resident_with_no_device_token_still_gets_the_sms(): void
    {
        $resident = $this->resident();

        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        $borrowing = $this->released($resident, now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
    }
}
