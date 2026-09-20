<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\SystemLog;
use App\Models\User;
use App\Support\ReminderFollowUp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesFcm;
use Tests\TestCase;

/**
 * serbis:send-return-reminders, the equipment half: push only, no text. A
 * reminder is marked sent only once FCM accepts it for a device; a resident with
 * none is left unmarked and put in front of staff (ReminderFollowUp).
 *
 * preventStrayRequests() is what proves no SMS is attempted: a request to
 * SkySMS that this file did not fake would throw.
 */
class SendReturnRemindersTest extends TestCase
{
    use FakesFcm;
    use RefreshDatabase;

    private const FCM = 'fcm.googleapis.com/*';

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureFcm();

        // Pinned so every bare now()/today()/addDay() call in this file — and
        // the command's own single Carbon::now() read — lands on the same
        // instant regardless of when the suite actually runs. A real midnight
        // crossing between fixture setup and the command's read used to make
        // "due tomorrow" and "due today" miss each other; see
        // SendReturnDueReminders::handle()'s single $now read.
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Manila'));

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 3,
            'status' => 'Available',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function resident(string $phone = '09171111111', bool $withDevice = true): Resident
    {
        $barangay = Barangay::firstOrCreate(['barangay_name' => 'San Antonio Ugad']);

        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'email_address' => uniqid('resident').'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        if ($withDevice) {
            $this->deviceFor($resident, 'device-'.$resident->getKey());
        }

        return $resident;
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

    private function tomorrow(): string
    {
        return now()->addDay()->format('Y-m-d');
    }

    private function pushBodies(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]['message']['notification']['body'] ?? null)->filter()->values()->all();
    }

    public function test_pushes_a_reminder_for_a_borrowing_due_tomorrow_and_marks_it_sent(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $borrowing = $this->released($this->resident(), $this->tomorrow());

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSentCount(1);
        $body = $this->pushBodies()[0];
        $this->assertStringContainsString('Rubber Boat', $body);
        $this->assertStringContainsString('due back tomorrow', $body);

        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
    }

    public function test_pushes_a_reminder_for_a_borrowing_due_today(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $this->released($this->resident(), now()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertStringContainsString('due back today', $this->pushBodies()[0]);
    }

    public function test_a_borrowing_already_reminded_is_never_pushed_twice(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $this->released($this->resident(), $this->tomorrow());

        $this->artisan('serbis:send-return-reminders');
        $this->artisan('serbis:send-return-reminders');

        Http::assertSentCount(1);
    }

    public function test_skips_a_borrowing_that_has_already_been_returned(): void
    {
        Http::fake();

        $borrowing = $this->released($this->resident(), $this->tomorrow(), [
            'status' => 'Returned',
            'returned_at' => now(),
        ]);

        $this->artisan('serbis:send-return-reminders');

        Http::assertNothingSent();
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);
    }

    public function test_a_resident_with_no_device_is_left_unmarked_and_staff_are_told(): void
    {
        Http::fake();

        $borrowing = $this->released($this->resident('09171111111', withDevice: false), $this->tomorrow());

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        // No device, so nothing left the machine — and no text was tried in its place.
        Http::assertNothingSent();
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at, 'a reminder nobody received must not be marked sent');

        $log = SystemLog::where('action_type', ReminderFollowUp::ACTION)->sole();
        $this->assertSame($borrowing->resident_id, $log->resident_id);
        $this->assertSame($borrowing->getKey(), (int) $log->auditable_id);
        $this->assertSame(EquipmentBorrowing::class, $log->auditable_type);
        $this->assertSame(ReminderFollowUp::DUE_REMINDER, $log->new_values['kind']);
    }

    public function test_a_push_fcm_refuses_is_also_a_follow_up_and_stays_unmarked(): void
    {
        Http::fake([self::FCM => $this->fcmRefuses()]);

        $borrowing = $this->released($this->resident(), $this->tomorrow());

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);
        $this->assertSame(1, SystemLog::where('action_type', ReminderFollowUp::ACTION)->count());
    }

    public function test_it_is_marked_sent_when_only_one_of_two_devices_takes_it(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident, 'second-device');

        Http::fake([self::FCM => Http::sequence()
            ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
            ->push(['name' => 'projects/x/messages/0:2'], 200)]);

        $borrowing = $this->released($resident, $this->tomorrow());

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
        $this->assertSame(0, SystemLog::where('action_type', ReminderFollowUp::ACTION)->count());
    }

    public function test_a_resident_with_no_device_is_retried_on_the_next_run_and_reminded_once_they_have_one(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $resident = $this->resident('09171111111', withDevice: false);
        $borrowing = $this->released($resident, $this->tomorrow());

        $this->artisan('serbis:send-return-reminders');
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);

        $this->deviceFor($resident);

        $this->artisan('serbis:send-return-reminders');
        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
    }

    public function test_the_follow_up_is_not_repeated_by_a_second_run_the_same_day(): void
    {
        Http::fake();

        $this->released($this->resident('09171111111', withDevice: false), $this->tomorrow());

        $this->artisan('serbis:send-return-reminders');
        $this->artisan('serbis:send-return-reminders');

        $this->assertSame(1, SystemLog::where('action_type', ReminderFollowUp::ACTION)->count());
    }

    public function test_the_follow_up_reaches_the_dashboard_with_a_name_and_a_number_to_ring(): void
    {
        Http::fake();

        $admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $borrowing = $this->released($this->resident('09171111111', withDevice: false), $this->tomorrow());

        $this->artisan('serbis:send-return-reminders');

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('followUps.0.name', 'Maria Santos')
            ->assertJsonPath('followUps.0.phone', '09171111111')
            ->assertJsonPath('followUps.0.what', 'Equipment due-back reminder');

        $this->actingAs($admin)->getJson('/api/logs/system')
            ->assertOk()
            ->assertJsonPath('data.0.action', ReminderFollowUp::ACTION)
            ->assertJsonPath('data.0.description', 'Equipment due-back reminder not delivered: Maria Santos has no registered device. Follow up by phone (EquipmentBorrowing ID: '.$borrowing->getKey().')');
    }

    public function test_an_item_name_that_reads_as_a_domain_goes_into_the_push_as_typed(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $row = $this->released($this->resident(), $this->tomorrow(), [
            'equipment_id' => null,
            'other_equipment_text' => 'Tent.com Set',
        ]);

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        // No text is sent any more, so nothing has to be scrubbed to get past a link check.
        $this->assertStringContainsString('Your borrowed Tent.com Set is due back', $this->pushBodies()[0]);
        $this->assertNotNull($row->fresh()->return_reminder_sent_at);
    }

    public function test_the_summary_line_counts_sent_and_not_delivered(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $this->released($this->resident('09171111111'), $this->tomorrow());
        $this->released($this->resident('09172222222', withDevice: false), $this->tomorrow());

        $this->artisan('serbis:send-return-reminders')
            ->expectsOutputToContain('1 sent, 0 failed (will retry), 0 skipped (no usable number), 1 not delivered (no registered device), 0 skipped (not configured).')
            ->assertExitCode(0);
    }

    public function test_equipment_is_still_reminded_when_skysms_is_not_configured(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);
        Config::set('services.skysms.api_key', null);
        Log::spy();

        $borrowing = $this->released($this->resident(), $this->tomorrow());

        // Still exits non-zero — SkySMS is unset, and the ambulance booking
        // reminders behind it would all be skipped — but equipment does not need it.
        $this->artisan('serbis:send-return-reminders')->assertExitCode(1);

        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
        Log::shouldHaveReceived('warning')->with('SkySMS not configured, 0 booking reminder(s) skipped');
    }

    public function test_respects_manila_date_boundaries_not_utc(): void
    {
        Http::fake([self::FCM => $this->fcmAccepts()]);

        // 23:30 UTC on the 11th is already 07:30 on the 12th in Manila
        // (UTC+8, no DST) — "today" in the office's own day is the 12th, not
        // the UTC date the server clock would name.
        Carbon::setTestNow(Carbon::parse('2026-09-11 23:30:00', 'UTC'));

        $dueTodayInManila = $this->released($this->resident('09171111111'), '2026-09-12');
        $dueTomorrowInManila = $this->released($this->resident('09172222222'), '2026-09-13');
        $dueDayAfterInManila = $this->released($this->resident('09173333333'), '2026-09-14');
        $overdue = $this->released($this->resident('09174444444'), '2026-09-11');

        $this->artisan('serbis:send-return-reminders');

        Http::assertSentCount(2);
        $this->assertNotNull($dueTodayInManila->fresh()->return_reminder_sent_at);
        $this->assertNotNull($dueTomorrowInManila->fresh()->return_reminder_sent_at);
        $this->assertNull($dueDayAfterInManila->fresh()->return_reminder_sent_at);
        $this->assertNull($overdue->fresh()->return_reminder_sent_at);
    }
}
