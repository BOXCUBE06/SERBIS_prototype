<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsLog;
use App\Models\SmsQueueId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A blast SkySMS accepted is Queued: taken and billed, delivery unconfirmed.
 * Only SkySMS's own message list may say more, and these tests pin that Queued
 * and Pending are never read as delivered.
 */
class SmsDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private const LIST = 'skysms.skyio.site/api/v1/sms/messages*';

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    /**
     * A blast as sendBlast leaves it: one log row, a Queued recipient each, and
     * the queue ids SkySMS returned.
     *
     * @param  list<string>  $phones
     * @param  list<int|string>  $queueIds
     */
    private function blast(array $phones, array $queueIds = [233895, 233896]): SmsLog
    {
        $log = SmsLog::create([
            'sender_id' => $this->admin->admin_id,
            'target_area_id' => $this->barangay->barangay_id,
            'message_body' => 'Test blast',
            'status' => Recipient::QUEUED,
        ]);

        foreach ($phones as $phone) {
            Recipient::create([
                'sms_log_id' => $log->sms_log_id,
                'resident_id' => $this->resident($phone)->resident_id,
                'status' => Recipient::QUEUED,
            ]);
        }

        foreach ($queueIds as $id) {
            SmsQueueId::create(['sms_log_id' => $log->sms_log_id, 'queue_id' => (string) $id]);
        }

        return $log;
    }

    /** One item of the vendor's list, as seen on 2026-09-20. */
    private function message(int $id, string $phone, string $status): array
    {
        return [
            'id' => $id,
            'unique_message_id' => 'msg_'.$id,
            'phone_number' => $phone,
            'message' => 'Test blast',
            'status' => $status,
            'priority' => 'normal',
            'created_at' => '2026-09-20T22:11:01.000000Z',
            'sent_at' => $status === 'sent' ? '2026-09-20T22:12:00.000000Z' : null,
            'updated_at' => '2026-09-20T22:22:03.000000Z',
        ];
    }

    private function list(array $messages, int $lastPage = 1)
    {
        return Http::response([
            'success' => true,
            'data' => $messages,
            'pagination' => ['current_page' => 1, 'last_page' => $lastPage, 'per_page' => 100, 'total' => count($messages)],
        ], 200);
    }

    private function check(SmsLog $log)
    {
        return $this->actingAs($this->admin)->postJson("/api/sms/deliveries/{$log->sms_log_id}/check");
    }

    private function statusOf(SmsLog $log, string $phone): string
    {
        return Recipient::where('sms_log_id', $log->sms_log_id)
            ->whereHas('resident', fn ($q) => $q->where('phone_number', $phone))
            ->firstOrFail()->status;
    }

    // ---------------------------------------------------------------- list

    public function test_the_list_shows_the_ten_most_recent_with_every_state_counted(): void
    {
        $log = $this->blast(['09171111111', '09172222222']);

        $response = $this->actingAs($this->admin)->getJson('/api/sms/deliveries')->assertOk();

        $response->assertJsonPath('data.0.sms_log_id', $log->sms_log_id)
            ->assertJsonPath('data.0.barangay', 'San Fabian')
            ->assertJsonPath('data.0.checkable', true)
            ->assertJsonPath('data.0.delivery_checked_at', null)
            ->assertJsonPath('data.0.counts', [
                'queued' => 2, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'unconfirmed' => 0, 'other' => 0,
            ]);
    }

    public function test_the_list_caps_at_ten_rows(): void
    {
        foreach (range(1, 12) as $i) {
            $this->blast([], []);
        }

        $this->assertCount(10, $this->actingAs($this->admin)->getJson('/api/sms/deliveries')->assertOk()->json('data'));
    }

    public function test_a_blast_without_queue_ids_is_marked_not_checkable(): void
    {
        $this->blast(['09171111111'], []);

        $this->actingAs($this->admin)->getJson('/api/sms/deliveries')
            ->assertOk()->assertJsonPath('data.0.checkable', false);
    }

    public function test_a_resident_cannot_read_or_check_it(): void
    {
        $resident = $this->resident('09173333333');
        $log = $this->blast(['09171111111']);

        $this->actingAs($resident)->getJson('/api/sms/deliveries')->assertForbidden();
        $this->actingAs($resident)->postJson("/api/sms/deliveries/{$log->sms_log_id}/check")->assertForbidden();
    }

    // --------------------------------------------------------------- check

    public function test_each_vendor_state_is_recorded_distinctly(): void
    {
        $log = $this->blast(['09171111111', '09172222222', '09173333333', '09174444444', '09175555555'], [1, 2, 3, 4, 5]);

        Http::fake([self::LIST => $this->list([
            $this->message(1, '+639171111111', 'pending'),
            $this->message(2, '+639172222222', 'queued'),
            $this->message(3, '+639173333333', 'sent'),
            $this->message(4, '+639174444444', 'failed'),
            $this->message(5, '+639175555555', 'held-for-review'),
        ])]);

        $this->check($log)->assertOk()
            ->assertJsonPath('checked', true)
            ->assertJsonPath('not_found', 0)
            ->assertJsonPath('data.counts', [
                'queued' => 1, 'pending' => 1, 'sent' => 1, 'failed' => 1, 'unconfirmed' => 0, 'other' => 1,
            ]);

        $this->assertSame(Recipient::PENDING, $this->statusOf($log, '+639171111111'));
        $this->assertSame(Recipient::QUEUED, $this->statusOf($log, '+639172222222'));
        $this->assertSame(Recipient::SENT, $this->statusOf($log, '+639173333333'));
        $this->assertSame(Recipient::FAILED, $this->statusOf($log, '+639174444444'));
        $this->assertSame(Recipient::OTHER, $this->statusOf($log, '+639175555555'));

        // Mixed, so the row is still not Sent.
        $this->assertSame(Recipient::QUEUED, $log->fresh()->status);
        $this->assertNotNull($log->fresh()->delivery_checked_at);
    }

    public function test_the_stuck_blast_stays_pending_and_is_never_sent(): void
    {
        // The two real messages of 2026-09-20: accepted, billed, never delivered.
        $log = $this->blast(['09391145133', '09673270822']);

        Http::fake([self::LIST => $this->list([
            $this->message(233895, '+639391145133', 'pending'),
            $this->message(233896, '+639673270822', 'pending'),
        ])]);

        $this->check($log)->assertOk()->assertJsonPath('data.counts.pending', 2)->assertJsonPath('data.counts.sent', 0);

        $this->assertSame(Recipient::QUEUED, $log->fresh()->status);
    }

    public function test_the_row_becomes_sent_only_when_every_recipient_is_sent(): void
    {
        $log = $this->blast(['09171111111', '09172222222']);

        Http::fake([self::LIST => $this->list([
            $this->message(233895, '+639171111111', 'sent'),
            $this->message(233896, '+639172222222', 'sent'),
        ])]);

        $this->check($log)->assertOk()->assertJsonPath('data.status', 'Sent');

        $this->assertSame('Sent', $log->fresh()->status);
    }

    public function test_the_row_becomes_failed_only_when_every_recipient_failed(): void
    {
        $log = $this->blast(['09171111111', '09172222222']);

        Http::fake([self::LIST => $this->list([
            $this->message(233895, '+639171111111', 'failed'),
            $this->message(233896, '+639172222222', 'failed'),
        ])]);

        $this->check($log)->assertOk();

        $this->assertSame('Failed', $log->fresh()->status);
    }

    public function test_a_message_with_someone_elses_id_is_not_matched_by_phone_alone(): void
    {
        $log = $this->blast(['09171111111'], [233895]);

        // Same number, a different send: not this blast's id.
        Http::fake([self::LIST => $this->list([$this->message(999, '+639171111111', 'sent')])]);

        $this->check($log)->assertOk()
            ->assertJsonPath('not_found', 1)
            ->assertJsonPath('data.counts.sent', 0)
            ->assertJsonPath('data.counts.queued', 1);
    }

    public function test_a_recipient_the_list_does_not_hold_stays_as_it_was_and_is_counted_not_found(): void
    {
        $log = $this->blast(['09171111111', '09172222222']);

        Http::fake([self::LIST => $this->list([$this->message(233895, '+639171111111', 'sent')])]);

        $this->check($log)->assertOk()
            ->assertJsonPath('not_found', 1)
            ->assertJsonPath('data.counts.sent', 1)
            ->assertJsonPath('data.counts.queued', 1);

        $this->assertSame(Recipient::QUEUED, $log->fresh()->status);
    }

    public function test_a_vendor_number_in_the_local_spelling_still_matches(): void
    {
        $log = $this->blast(['09171111111'], [7]);

        Http::fake([self::LIST => $this->list([$this->message(7, '09171111111', 'sent')])]);

        $this->check($log)->assertOk()->assertJsonPath('data.counts.sent', 1);
    }

    public function test_it_reads_further_pages_until_the_last(): void
    {
        $log = $this->blast(['09171111111', '09172222222']);

        Http::fake([self::LIST => Http::sequence()
            ->push(['success' => true, 'data' => [$this->message(233895, '+639171111111', 'sent')], 'pagination' => ['last_page' => 2]], 200)
            ->push(['success' => true, 'data' => [$this->message(233896, '+639172222222', 'sent')], 'pagination' => ['last_page' => 2]], 200)]);

        $this->check($log)->assertOk()->assertJsonPath('data.counts.sent', 2);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page=2'));
    }

    public function test_it_asks_the_vendor_for_a_date_window_around_the_blast_and_sends_the_key(): void
    {
        $log = $this->blast(['09171111111']);
        $day = $log->created_at->copy()->utc();

        Http::fake([self::LIST => $this->list([])]);

        $this->check($log)->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), 'from='.$day->toDateString())
            && str_contains($request->url(), 'to='.$day->copy()->addDay()->toDateString())
            && str_contains($request->url(), 'per_page=100')
            && $request->hasHeader('X-API-Key'));
    }

    public function test_a_second_press_inside_twenty_seconds_does_not_call_the_vendor_again(): void
    {
        $log = $this->blast(['09171111111']);

        Http::fake([self::LIST => $this->list([$this->message(233895, '+639171111111', 'pending')])]);

        $this->check($log)->assertOk();
        $this->check($log)->assertOk()->assertJsonPath('checked', true)->assertJsonPath('data.counts.pending', 1);

        Http::assertSentCount(1);
    }

    public function test_when_the_vendor_cannot_be_read_nothing_changes(): void
    {
        $log = $this->blast(['09171111111']);

        Http::fake([self::LIST => Http::response(['message' => 'boom'], 500)]);

        $this->check($log)->assertOk()
            ->assertJsonPath('checked', false)
            ->assertJsonPath('reason', 'unavailable')
            ->assertJsonPath('data.counts.queued', 1);

        $this->assertNull($log->fresh()->delivery_checked_at);
        $this->assertSame(Recipient::QUEUED, $this->statusOf($log, '+639171111111'));
    }

    public function test_a_half_read_list_changes_nothing(): void
    {
        $log = $this->blast(['09171111111', '09172222222']);

        Http::fake([self::LIST => Http::sequence()
            ->push(['success' => true, 'data' => [$this->message(233895, '+639171111111', 'sent')], 'pagination' => ['last_page' => 2]], 200)
            ->push(['message' => 'boom'], 500)]);

        $this->check($log)->assertOk()->assertJsonPath('reason', 'unavailable');

        $this->assertSame(Recipient::QUEUED, $this->statusOf($log, '+639171111111'));
    }

    public function test_a_blast_with_no_queue_ids_cannot_be_checked_and_asks_nothing(): void
    {
        $log = $this->blast(['09171111111'], []);

        Http::fake();

        $this->check($log)->assertOk()
            ->assertJsonPath('checked', false)
            ->assertJsonPath('reason', 'no_queue_ids');

        Http::assertNothingSent();
    }

    public function test_fake_mode_says_so_and_asks_nothing(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['serbis.sms_fake' => true]);

        $log = $this->blast(['09171111111']);

        Http::fake();

        $this->check($log)->assertOk()
            ->assertJsonPath('checked', false)
            ->assertJsonPath('reason', 'fake')
            ->assertJsonPath('message', 'SMS is faked locally, nothing to check.');

        Http::assertNothingSent();
    }

    // -------------------------------------------------------- resident feed

    public function test_a_queued_blast_is_in_the_residents_feed(): void
    {
        $log = $this->blast(['09171111111']);
        $resident = Resident::where('phone_number', '+639171111111')->firstOrFail();

        $this->actingAs($resident)->getJson('/api/advisories')
            ->assertOk()->assertJsonPath('data.0.sms_log_id', $log->sms_log_id);
    }

    public function test_a_blast_the_vendor_failed_for_this_resident_is_not_in_their_feed(): void
    {
        $log = $this->blast(['09171111111', '09172222222']);

        Recipient::whereHas('resident', fn ($q) => $q->where('phone_number', '+639171111111'))
            ->update(['status' => Recipient::FAILED]);

        $failed = Resident::where('phone_number', '+639171111111')->firstOrFail();
        $queued = Resident::where('phone_number', '+639172222222')->firstOrFail();

        $this->actingAs($failed)->getJson('/api/advisories')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($queued)->getJson('/api/advisories')->assertOk()->assertJsonPath('data.0.sms_log_id', $log->sms_log_id);
    }
}
