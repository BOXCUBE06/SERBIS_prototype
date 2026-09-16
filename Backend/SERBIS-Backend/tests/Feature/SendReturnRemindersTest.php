<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * serbis:send-return-reminders — PhilSMS has no sandbox, so every test here
 * fakes the host rather than letting a real send happen, same as the
 * ServiceRequest approve/reject/reschedule tests.
 */
class SendReturnRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_sends_a_reminder_for_a_borrowing_due_tomorrow_and_marks_it_sent(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $borrowing = $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['message'], 'Rubber Boat')
            && str_contains($request['message'], 'due back tomorrow'));

        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
    }

    public function test_sends_a_reminder_for_a_borrowing_due_today(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->released($this->resident(), now()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request['message'], 'due back today'));
    }

    public function test_a_borrowing_already_reminded_is_never_texted_twice(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders');
        $this->artisan('serbis:send-return-reminders');

        Http::assertSentCount(1);
    }

    public function test_skips_a_borrowing_that_has_already_been_returned(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $borrowing = $this->released($this->resident(), now()->addDay()->format('Y-m-d'), [
            'status' => 'Returned',
            'returned_at' => now(),
        ]);

        $this->artisan('serbis:send-return-reminders');

        Http::assertNothingSent();
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);
    }

    public function test_a_rejected_response_leaves_the_row_unmarked_for_retry(): void
    {
        // A 200 carrying status "error" — PhilSms::accepted() treats this as
        // a rejection, not a success, same as ServiceRequestController's own
        // sends.
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'error'], 200)]);

        $borrowing = $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at, 'a rejected send must not be marked as delivered');
    }

    public function test_a_thrown_exception_leaves_the_row_unmarked_for_retry(): void
    {
        Http::fake(['dashboard.philsms.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out')]);

        $borrowing = $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertNull($borrowing->fresh()->return_reminder_sent_at, 'a failed send must not be marked as delivered');
    }

    public function test_skips_a_borrowing_with_no_reachable_phone_number(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        // Bypasses the registration-time PHONE_REGEX rule on purpose — see
        // PhilSms::PHONE_REGEX's own note that rows written before the rule
        // can still hold an undialable number.
        $borrowing = $this->released($this->resident('not-a-phone'), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders');

        Http::assertNothingSent();

        // Left unmarked: a resident who fixes their number before the due
        // date still gets reminded on a later run.
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);
    }

    public function test_missing_philsms_config_logs_one_warning_and_exits_non_zero(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);
        Config::set('services.philsms.token', null);

        $this->released($this->resident(), now()->addDay()->format('Y-m-d'));
        $this->released($this->resident('09172222222'), now()->format('Y-m-d'));

        Log::shouldReceive('warning')
            ->once()
            ->with('PhilSMS not configured, 2 reminder(s) skipped');

        $this->artisan('serbis:send-return-reminders')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_summary_line_reports_all_four_buckets(): void
    {
        // Keyed on the recipient in the request body, not call order, since
        // EquipmentBorrowing::get() makes no ordering guarantee here.
        Http::fake(function ($request) {
            $rejected = str_contains((string) $request['recipient'], '639172222222');

            return Http::response(['status' => $rejected ? 'error' : 'success'], 200);
        });

        // Sent.
        $this->released($this->resident('09171111111'), now()->addDay()->format('Y-m-d'));
        // Failed — rejected by PhilSMS, left unmarked.
        $this->released($this->resident('09172222222'), now()->addDay()->format('Y-m-d'));
        // Skipped — no usable number.
        $this->released($this->resident('not-a-phone'), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')
            ->expectsOutputToContain('1 sent, 1 failed (will retry), 1 skipped (no usable number), 0 skipped (not configured).')
            ->assertExitCode(0);
    }

    public function test_respects_manila_date_boundaries_not_utc(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

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
