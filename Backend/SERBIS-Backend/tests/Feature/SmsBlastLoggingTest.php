<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsBlastCode;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SkySMS has no sandbox: every call is a real send to a real handset, billed.
 * preventStrayRequests() is what makes these tests safe to run — a request that
 * escapes the fake fails the test instead of costing money.
 */
class SmsBlastLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '123456';

    private User $admin;

    private Barangay $barangayA;

    private Barangay $barangayB;

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

        // The shared blast code — every admin knows the same one, so sendBlast
        // is gated on it rather than on whichever admin's own account password.
        SmsBlastCode::create([
            'code_hash' => Hash::make(self::CODE),
            'updated_by' => $this->admin->admin_id,
        ]);

        $this->barangayA = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->barangayB = Barangay::create(['barangay_name' => 'San Miguel']);
    }

    private function resident(Barangay $barangay, string $status, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('password123'),
            'status' => $status,
        ]);
    }

    public function test_a_successful_blast_is_recorded_per_barangay_with_its_recipients(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response(['success' => true, 'batch_id' => 'job-123'], 200)]);

        $a1 = $this->resident($this->barangayA, 'Active', '09171111111');
        $a2 = $this->resident($this->barangayA, 'Active', '09172222222');
        $b1 = $this->resident($this->barangayB, 'Active', '09173333333');

        $response = $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Evacuate low-lying areas immediately.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id, $this->barangayB->barangay_id],
        ]);

        $response->assertOk()->assertJson(['sent' => 3, 'failed' => 0]);

        // One log per barangay, not one per blast: "what was sent to my barangay"
        // is the question a resident asks.
        $this->assertSame(2, SmsLog::count());
        $this->assertSame(3, Recipient::count());

        $logA = SmsLog::where('target_area_id', $this->barangayA->barangay_id)->firstOrFail();
        $this->assertSame('Sent', $logA->status);
        $this->assertSame('job-123', $logA->api_job_id);
        $this->assertSame('Evacuate low-lying areas immediately.', $logA->message_body);
        $this->assertEqualsCanonicalizing(
            [$a1->resident_id, $a2->resident_id],
            $logA->recipients->pluck('resident_id')->all(),
        );

        $logB = SmsLog::where('target_area_id', $this->barangayB->barangay_id)->firstOrFail();
        $this->assertSame([$b1->resident_id], $logB->recipients->pluck('resident_id')->all());
    }

    public function test_residents_who_were_not_sent_to_are_not_recorded_as_recipients(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response([], 200)]);

        $active = $this->resident($this->barangayA, 'Active', '09171111111');
        $this->resident($this->barangayA, 'Inactive', '09174444444');
        // The column is NOT NULL, so a resident with no usable number stores an
        // empty string — which is exactly why the recipient query filters on
        // both null and ''.
        $this->resident($this->barangayA, 'Active', '');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Test advisory.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk()->assertJson(['sent' => 1]);

        $this->assertSame([$active->resident_id], Recipient::pluck('resident_id')->all());
    }

    public function test_a_failed_blast_is_recorded_but_never_reaches_the_advisory_feed(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response(['error' => 'upstream down'], 500)]);

        $resident = $this->resident($this->barangayA, 'Active', '09171111111');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'This one never went out.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(500);

        $this->assertSame('Failed', SmsLog::firstOrFail()->status);

        $this->actingAs($resident)->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_advisories_are_scoped_to_the_resident_who_received_them(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response([], 200)]);

        $inA = $this->resident($this->barangayA, 'Active', '09171111111');
        $inB = $this->resident($this->barangayB, 'Active', '09172222222');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Flooding on the national road.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk();

        $this->actingAs($inA)->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_body', 'Flooding on the national road.')
            ->assertJsonPath('data.0.barangay.barangay_name', 'San Fabian');

        $this->actingAs($inB)->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_blast_with_no_eligible_recipients_records_nothing_and_does_not_call_the_vendor(): void
    {
        Http::fake();

        $this->resident($this->barangayA, 'Inactive', '09171111111');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Nobody to send this to.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }

    public function test_a_blast_without_the_code_is_refused_before_the_vendor_is_called(): void
    {
        Http::fake();

        $this->resident($this->barangayA, 'Active', '09171111111');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Should never leave.',
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // The point of the gate: nothing billed, nothing recorded.
        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }

    public function test_a_blast_with_the_wrong_code_is_refused_before_the_vendor_is_called(): void
    {
        Http::fake();

        $this->resident($this->barangayA, 'Active', '09171111111');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Should never leave.',
            'code' => 'not-the-code',
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }

    public function test_a_blast_is_refused_when_no_code_has_ever_been_set(): void
    {
        Http::fake();

        // Simulates a fresh deployment that skipped the seed step — no row in
        // tbl_sms_blast_code at all, distinct from a wrong-code guess.
        SmsBlastCode::query()->delete();

        $this->resident($this->barangayA, 'Active', '09171111111');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Should never leave.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }

    /**
     * The point of a shared code rather than each admin's own password: there
     * is no role system, so any admin who was told the code may send — not
     * just the admin who last set it.
     */
    public function test_a_second_admin_who_knows_the_shared_code_may_also_send(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response([], 200)]);

        $this->resident($this->barangayA, 'Active', '09171111111');

        $other = User::create([
            'first_name' => 'Second',
            'last_name' => 'Admin',
            'email_address' => 'second@test.local',
            'password' => Hash::make('a-different-password'),
            'role' => 'Admin',
        ]);

        $this->actingAs($other)->postJson('/api/sms/blast', [
            'message' => 'A colleague who knows the code.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk();
    }

    public function test_repeated_wrong_codes_are_throttled_and_then_block_the_correct_one(): void
    {
        // Isolated from the route throttle: proving the code limiter costs more
        // than three sends an hour, and the two guards are independent.
        $this->withoutMiddleware(ThrottleRequests::class);

        Http::fake();

        $this->resident($this->barangayA, 'Active', '09171111111');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($this->admin)->postJson('/api/sms/blast', [
                'message' => 'Guessing.',
                'code' => "wrong-{$attempt}",
                'barangays' => [$this->barangayA->barangay_id],
            ])->assertStatus(422);
        }

        // Sixth wrong code is refused by the limiter, not the hash check.
        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Guessing.',
            'code' => 'wrong-6',
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(429);

        // The point of checking the limit before the comparison: inside the
        // window even the real code does not get through.
        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Correct code, still locked out.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(429);

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }

    public function test_the_throttle_is_per_account_and_does_not_lock_out_another_admin(): void
    {
        // Isolated from the route throttle: proving the code limiter costs more
        // than three sends an hour, and the two guards are independent.
        $this->withoutMiddleware(ThrottleRequests::class);

        Http::fake();

        $this->resident($this->barangayA, 'Active', '09171111111');

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($this->admin)->postJson('/api/sms/blast', [
                'message' => 'Guessing.',
                'code' => "wrong-{$attempt}",
                'barangays' => [$this->barangayA->barangay_id],
            ]);
        }

        $other = User::create([
            'first_name' => 'Second',
            'last_name' => 'Admin',
            'email_address' => 'second@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);

        $this->actingAs($other)->postJson('/api/sms/blast', [
            'message' => 'A colleague sending normally.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk();
    }

    public function test_a_correct_code_clears_the_tally_so_ordinary_sending_is_never_throttled(): void
    {
        // Isolated from the route throttle: proving the code limiter costs more
        // than three sends an hour, and the two guards are independent.
        $this->withoutMiddleware(ThrottleRequests::class);

        Http::fake();

        $this->resident($this->barangayA, 'Active', '09171111111');

        // Four typos — one short of the limit.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->actingAs($this->admin)->postJson('/api/sms/blast', [
                'message' => 'Typo.',
                'code' => "wrong-{$attempt}",
                'barangays' => [$this->barangayA->barangay_id],
            ])->assertStatus(422);
        }

        // The right code, which sends and resets the tally to zero.
        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Got it right.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk();

        // Four more typos. Without the reset these would be attempts five
        // through eight and the last of them would be refused as 429.
        for ($attempt = 5; $attempt <= 8; $attempt++) {
            $this->actingAs($this->admin)->postJson('/api/sms/blast', [
                'message' => 'Typo again.',
                'code' => "wrong-{$attempt}",
                'barangays' => [$this->barangayA->barangay_id],
            ])->assertStatus(422);
        }

        // And the tally being clear means the right code still works.
        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Still able to send.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk();
    }

    public function test_a_fourth_blast_within_the_hour_is_refused_by_the_route_throttle(): void
    {
        Http::fake();

        $this->resident($this->barangayA, 'Active', '09171111111');

        for ($sent = 1; $sent <= 3; $sent++) {
            $this->actingAs($this->admin)->postJson('/api/sms/blast', [
                'message' => "Advisory {$sent}.",
                'code' => self::CODE,
                'barangays' => [$this->barangayA->barangay_id],
            ])->assertOk();
        }

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'One too many.',
            'code' => self::CODE,
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(429);

        // The money, not the status code: the fourth call must never reach the
        // vendor, so the log stays at the three that were allowed through.
        $this->assertSame(3, SmsLog::count());
    }
}
