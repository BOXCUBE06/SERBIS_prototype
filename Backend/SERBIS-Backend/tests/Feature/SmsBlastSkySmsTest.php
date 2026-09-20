<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Recipient;
use App\Models\SmsBlastCode;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Sms\SkySmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The text blast through SkySMS: bulk requests of at most 1000, and what each
 * kind of vendor answer does to the record and to what staff are told.
 *
 * SkySMS has no sandbox; preventStrayRequests() makes a leaked request fail the
 * test instead of costing credits.
 */
class SmsBlastSkySmsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'skysms.skyio.site/*';

    private const CODE = '123456';

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
        // The 3-an-hour blast limiter is covered elsewhere; these tests send
        // more than three times.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        SmsBlastCode::create(['code_hash' => Hash::make(self::CODE), 'updated_by' => $this->admin->admin_id]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    /** Active, opted-in heads of the family with distinct numbers, inserted in bulk. */
    private function residents(int $count): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'barangay_id' => $this->barangay->barangay_id,
                'first_name' => 'Resident',
                'last_name' => (string) $i,
                'phone_number' => '0917'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'email_address' => "r{$i}@test.local",
                'password' => 'x',
                'status' => 'Active',
                'account_type' => 'head_of_family',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tbl_residents')->insert($chunk);
        }
    }

    private function blast(string $message = 'MDRRMO Echague advisory: heavy rain expected today.')
    {
        return $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => $message,
            'code' => self::CODE,
            'barangays' => [$this->barangay->barangay_id],
        ]);
    }

    // ---- content ---------------------------------------------------------

    public function test_a_message_with_a_url_or_domain_is_refused_and_nothing_is_sent_or_recorded(): void
    {
        Http::fake();
        $this->residents(2);

        foreach (['Details at https://example.org/alert', 'See www.echague.gov', 'Visit echague.ph today', 'Call 192.168.0.1'] as $message) {
            $this->blast($message)
                ->assertStatus(422)
                ->assertJsonValidationErrors('message');
        }

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }

    public function test_curly_punctuation_is_allowed_through_because_the_panel_only_warns_about_it(): void
    {
        Http::fake([self::HOST => Http::response(['success' => true], 200)]);
        $this->residents(1);

        $this->blast("Stay safe \u{2014} it\u{2019}s raining")->assertOk();
    }

    // ---- chunking --------------------------------------------------------

    public function test_a_large_audience_goes_out_in_bulk_requests_of_at_most_a_thousand(): void
    {
        Http::fake([self::HOST => Http::response(['success' => true, 'batch_id' => 'b-1'], 200)]);
        $this->residents(2500);

        $this->blast()->assertOk()->assertJson(['sent' => 2500, 'failed' => 0]);

        $sizes = Http::recorded()
            ->map(fn ($pair) => count($pair[0]['recipients']))
            ->all();

        $this->assertSame([1000, 1000, 500], $sizes);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sms/send-bulk'));

        // One log row per chunk (all one barangay here), every resident recorded once.
        $this->assertSame(3, SmsLog::count());
        $this->assertSame(2500, Recipient::count());
    }

    // ---- what the vendor said --------------------------------------------

    public function test_out_of_credits_is_a_402_with_a_clear_message_and_is_recorded_as_failed(): void
    {
        Http::fake([self::HOST => Http::response(['message' => 'Insufficient credits'], 402)]);
        $this->residents(3);

        $response = $this->blast()->assertStatus(402)->assertJsonPath('code', 'out_of_credits');

        $this->assertStringContainsString('credits are used up', $response->json('message'));
        $this->assertSame('Failed', SmsLog::firstOrFail()->status);
        $this->assertTrue(Cache::get(SkySmsGateway::CACHE_OUT_OF_CREDITS));
    }

    public function test_a_warning_in_the_response_is_a_failure_not_a_success(): void
    {
        Http::fake([self::HOST => Http::response(['success' => true, 'warning' => 'Content penalty applied'], 200)]);
        $this->residents(2);

        $response = $this->blast()->assertStatus(422)->assertJsonPath('code', 'flagged');

        $this->assertStringContainsString('Content penalty applied', $response->json('message'));
        $this->assertSame('Failed', SmsLog::firstOrFail()->status);
        // The advisory feed must not show a warning as received.
        $this->assertSame(0, SmsLog::where('status', 'Sent')->count());
    }

    public function test_a_429_is_retried_and_the_blast_then_goes_through(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '0'])
            ->push(['success' => true, 'batch_id' => 'b-2'], 200)]);
        $this->residents(2);

        $this->blast()->assertOk()->assertJson(['sent' => 2, 'failed' => 0]);

        Http::assertSentCount(2);
        $this->assertSame('b-2', SmsLog::firstOrFail()->api_job_id);
    }

    public function test_a_persistent_429_ends_as_a_429_and_nothing_is_recorded_as_sent(): void
    {
        config(['services.skysms.max_retries' => 1]);
        Http::fake([self::HOST => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '0'])]);
        $this->residents(2);

        $this->blast()->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        Http::assertSentCount(2); // the first try and one retry
        $this->assertSame('Failed', SmsLog::firstOrFail()->status);
    }

    public function test_credits_running_out_part_way_reports_the_split_and_stops_asking(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['success' => true, 'batch_id' => 'b-1'], 200)
            ->push(['message' => 'Insufficient credits'], 402)]);
        $this->residents(2500);

        $response = $this->blast()->assertOk()->assertJson(['sent' => 1000, 'failed' => 1500]);

        $this->assertStringContainsString('Part of the blast went out', $response->json('message'));
        // The third chunk was not even attempted once credits were gone.
        Http::assertSentCount(2);

        $this->assertSame(1000, Recipient::where('status', 'Sent')->count());
        $this->assertSame(1500, Recipient::where('status', 'Failed')->count());
        $this->assertSame(2500, Recipient::count());
    }

    public function test_a_timeout_on_one_chunk_is_unconfirmed_and_never_failed(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['success' => true], 200)
            ->pushFailedConnection('cURL error 28: timed out')]);
        $this->residents(1500);

        $response = $this->blast()->assertStatus(202)->assertJsonPath('unconfirmed', true);

        $response->assertJsonPath('sent', 1000)->assertJsonPath('unconfirmed_count', 500)->assertJsonPath('failed', 0);
        $this->assertSame(500, Recipient::where('status', 'Unconfirmed')->count());
        $this->assertSame(0, Recipient::where('status', 'Failed')->count());
    }

    // ---- the credits shown in the panel ----------------------------------

    public function test_the_balance_endpoint_reports_the_credits_from_the_last_send(): void
    {
        Http::fake([self::HOST => Http::response(['success' => true, 'credits_remaining' => 812], 200)]);
        $this->residents(1);

        $this->actingAs($this->admin)->getJson('/api/sms/balance')
            ->assertOk()
            ->assertJsonPath('available', false); // nothing sent yet, nothing to show

        $this->blast()->assertOk();

        $this->actingAs($this->admin)->getJson('/api/sms/balance')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('data.remaining_credits', 812);
    }

    public function test_the_balance_endpoint_says_so_when_the_account_is_out_of_credits(): void
    {
        Cache::put(SkySmsGateway::CACHE_OUT_OF_CREDITS, true, now()->addDay());

        $this->actingAs($this->admin)->getJson('/api/sms/balance')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('out_of_credits', true);
    }

    public function test_the_balance_endpoint_never_calls_the_vendor(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->getJson('/api/sms/balance')->assertOk();

        Http::assertNothingSent();
    }
}
