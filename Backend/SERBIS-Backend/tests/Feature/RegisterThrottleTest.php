<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /register used to share the 'login' rate limiter, which is the wrong
 * shape for it: 'login' keys its tight 5/min limit on the submitted email,
 * but a registration attempt always submits a NEW email, so that key never
 * repeats and only the loose 20/min-per-IP fallback ever engaged — and being
 * per-minute, it resets forever, so a script sitting at 20/min could create
 * an unbounded number of `tbl_residents` rows (and, for a real-looking
 * number, bill real PhilSMS sends) over a day with nothing to stop it.
 *
 * The 'register' limiter (AppServiceProvider::boot()) is IP-only with two
 * tiers: a fast per-minute burst cap and the actual fix, a per-hour
 * sustained cap. Both are asserted here independently, because they share
 * a limiter but must not share a cache key — see the comment on the fix.
 */
class RegisterThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        // register() texts an OTP through PhilSMS, which has no sandbox — an
        // escaped request here would be a billed real send.
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function payload(string $email): array
    {
        return [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234567',
            'email_address' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ];
    }

    public function test_the_sixth_registration_in_one_minute_from_one_ip_is_throttled(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/register', $this->payload("burst{$i}@test.local"))
                ->assertStatus(201);
        }

        $this->postJson('/api/register', $this->payload('burst6@test.local'))
            ->assertStatus(429);
    }

    public function test_a_script_pacing_itself_under_the_burst_limit_still_hits_the_hourly_cap(): void
    {
        // One request per minute stays under the 5/min burst tier on every
        // single call, so only the separate per-hour tier can catch this —
        // proving the two tiers are tracked independently, not just that
        // *a* 429 eventually appears.
        $start = Carbon::now();
        Carbon::setTestNow($start);

        for ($i = 1; $i <= 15; $i++) {
            Carbon::setTestNow($start->copy()->addMinutes($i - 1));

            $this->postJson('/api/register', $this->payload("paced{$i}@test.local"))
                ->assertStatus(201);
        }

        Carbon::setTestNow($start->copy()->addMinutes(15));

        $this->postJson('/api/register', $this->payload('paced16@test.local'))
            ->assertStatus(429);

        Carbon::setTestNow();
    }

    public function test_registration_from_a_different_ip_is_unaffected_by_another_ips_throttle(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/register', $this->payload("attacker{$i}@test.local"), [
                'REMOTE_ADDR' => '10.0.0.1',
            ])->assertStatus(201);
        }

        $this->postJson('/api/register', $this->payload('attacker6@test.local'), [
            'REMOTE_ADDR' => '10.0.0.1',
        ])->assertStatus(429);

        // A resident behind a different connection is not caught by it —
        // the CGNAT concern the 'login' limiter's own comment names.
        $this->postJson('/api/register', $this->payload('neighbour@test.local'), [
            'REMOTE_ADDR' => '10.0.0.2',
        ])->assertStatus(201);
    }
}
