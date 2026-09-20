<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesSkySms;
use Tests\TestCase;

/**
 * A resident moves their phone number.
 *
 * The number is the login and where every code goes, so a bearer token alone
 * must not be enough: the current password proves who is asking, and a code
 * texted to the NEW number proves they hold the number they are moving to.
 */
class ResidentPhoneChangeTest extends TestCase
{
    use FakesSkySms, RefreshDatabase;

    private Barangay $barangay;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSkySms();
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        $this->resident->markPhoneAsVerified();
    }

    private function asResident(): static
    {
        Sanctum::actingAs($this->resident);

        return $this;
    }

    private function start(string $phone = '09179999999', string $password = 'Password123'): TestResponse
    {
        return $this->asResident()->postJson('/api/me/phone', ['phone_number' => $phone, 'current_password' => $password]);
    }

    private function verify(string $code): TestResponse
    {
        return $this->asResident()->postJson('/api/me/phone/verify', ['code' => $code]);
    }

    private function pending(): ?array
    {
        $entry = Cache::get('phonechange:'.$this->resident->getKey());

        return is_array($entry) ? $entry : null;
    }

    // --- Step one ---------------------------------------------------------------

    public function test_the_password_and_a_new_number_text_a_code_to_the_new_number_only(): void
    {
        $this->start()
            ->assertOk()
            ->assertJsonPath('pending', true)
            ->assertJsonPath('phone_number', '+639179999999')
            ->assertJsonPath('channel', 'sms')
            ->assertJsonPath('sent_to', '9999')
            ->assertJsonPath('delivery', 'accepted')
            ->assertJsonPath('retry_after', 60);

        // The NEW number, never the old one — and nothing has moved yet.
        $this->assertSame(['+639179999999'], $this->numbersTexted());
        $this->assertSame('+639171111111', $this->resident->fresh()->phone_number);
    }

    public function test_a_wrong_or_missing_password_is_refused_and_texts_nothing(): void
    {
        $this->start('09179999999', 'not-the-password')
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->asResident()->postJson('/api/me/phone', ['phone_number' => '09179999999'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        Http::assertNothingSent();
        $this->assertNull($this->pending());
    }

    public function test_a_number_another_account_holds_is_refused_in_any_spelling(): void
    {
        Resident::create([
            'barangay_id' => $this->barangay->barangay_id, 'first_name' => 'Juan', 'last_name' => 'Cruz',
            'phone_number' => '09172222222', 'password' => Hash::make('Password123'), 'status' => 'Active',
        ]);

        foreach (['09172222222', '639172222222', '+639172222222'] as $typed) {
            $this->start($typed)->assertStatus(422)->assertJsonValidationErrors('phone_number');
        }

        Http::assertNothingSent();
    }

    public function test_your_own_number_and_a_malformed_one_are_refused(): void
    {
        $this->start('+639171111111')->assertStatus(422)->assertJsonValidationErrors('phone_number');
        $this->start('0288888888')->assertStatus(422)->assertJsonValidationErrors('phone_number');
        $this->start('not-a-phone')->assertStatus(422)->assertJsonValidationErrors('phone_number');

        Http::assertNothingSent();
    }

    public function test_an_admin_token_cannot_use_it(): void
    {
        $admin = User::create([
            'first_name' => 'A', 'last_name' => 'B', 'email_address' => 'a@serbis.com',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/me/phone', ['phone_number' => '09179999999', 'current_password' => 'Password123'])->assertStatus(403);
        $this->postJson('/api/me/phone/verify', ['code' => '123456'])->assertStatus(403);
    }

    public function test_unauthenticated_calls_are_refused(): void
    {
        $this->postJson('/api/me/phone', [])->assertStatus(401);
        $this->postJson('/api/me/phone/verify', [])->assertStatus(401);
        $this->postJson('/api/me/phone/resend', [])->assertStatus(401);
    }

    // --- Delivery: there is no email to fall back to ---------------------------

    public function test_a_text_that_cannot_be_sent_is_a_503_and_starts_nothing(): void
    {
        $this->smsRejects = true;

        $this->start()->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');

        $this->assertNull($this->pending());
        $this->assertSame('+639171111111', $this->resident->fresh()->phone_number);
    }

    public function test_a_timed_out_text_starts_the_change_and_says_delivery_is_unknown(): void
    {
        $this->smsTimesOut = true;

        $this->start()->assertOk()->assertJsonPath('delivery', 'unknown');

        $this->assertNotNull($this->pending());
    }

    public function test_a_short_rate_limit_is_waited_out_once(): void
    {
        $this->smsShortRateLimits = 1;

        $this->start()->assertOk()->assertJsonPath('delivery', 'accepted');
        Http::assertSentCount(2);
    }

    // --- Step two ---------------------------------------------------------------

    public function test_the_right_code_moves_the_number_and_marks_it_verified(): void
    {
        $this->start()->assertOk();
        $code = $this->lastCodeTexted();

        $this->verify($code)
            ->assertOk()
            ->assertJsonPath('role', 'resident')
            ->assertJsonPath('user.phone_number', '+639179999999')
            ->assertJsonPath('user.is_phone_verified', true);

        $fresh = $this->resident->fresh();
        $this->assertSame('+639179999999', $fresh->phone_number);
        $this->assertNull($this->pending(), 'A spent change must not stay in the cache.');

        // The new number is now the login.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/resident/login', ['phone_number' => '09179999999', 'password' => 'Password123'])
            ->assertStatus(403)->assertJsonPath('code', 'mfa_required');
        $this->postJson('/api/resident/login', ['phone_number' => '09171111111', 'password' => 'Password123'])
            ->assertStatus(401);
    }

    public function test_a_wrong_code_changes_nothing_and_does_not_spend_the_change(): void
    {
        $this->start()->assertOk();
        $code = $this->lastCodeTexted();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->verify($wrong)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->assertSame('+639171111111', $this->resident->fresh()->phone_number);

        $this->verify($code)->assertOk();
    }

    public function test_the_fifth_wrong_code_ends_the_whole_change(): void
    {
        $this->start()->assertOk();
        $code = $this->lastCodeTexted();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $this->verify($wrong)->assertStatus(422);
        }

        // Past the route limiter's per-minute budget (the start and four
        // guesses used it), so what answers is the attempt cap, not the throttle.
        $this->travel(61)->seconds();

        $this->verify($wrong)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

        // The right code no longer works, and there is nothing pending to resend
        // against: starting again asks for the password again.
        $this->travel(61)->seconds();
        $this->verify($code)->assertStatus(404)->assertJsonPath('code', 'no_pending_change');
        $this->assertSame('+639171111111', $this->resident->fresh()->phone_number);
    }

    public function test_an_expired_code_is_refused_and_can_be_replaced_by_a_resend(): void
    {
        $this->start()->assertOk();
        $first = $this->lastCodeTexted();

        $this->travel(Resident::PHONE_CHANGE_TTL_MINUTES + 1)->minutes();

        $this->verify($first)->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->asResident()->postJson('/api/me/phone/resend')->assertOk()->assertJsonPath('code', 'code_sent');
        $second = $this->lastCodeTexted();
        $this->assertNotSame($first, $second);

        $this->verify($second)->assertOk();
    }

    public function test_verifying_with_nothing_pending_is_a_404(): void
    {
        $this->verify('123456')->assertStatus(404)->assertJsonPath('code', 'no_pending_change');
    }

    public function test_the_code_must_be_six_characters(): void
    {
        $this->start()->assertOk();

        $this->verify('12345')->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_number_taken_meanwhile_is_a_clean_refusal(): void
    {
        $this->start()->assertOk();
        $code = $this->lastCodeTexted();

        // Someone else registers that number between the two steps.
        DB::table('tbl_residents')->insert([
            'barangay_id' => $this->barangay->barangay_id, 'first_name' => 'Other', 'last_name' => 'Person',
            'phone_number' => '+639179999999', 'password' => 'x', 'status' => 'Active',
            'account_type' => 'head_of_family', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'phone_taken');
        $this->assertSame('+639171111111', $this->resident->fresh()->phone_number);
        $this->assertNull($this->pending());
    }

    public function test_the_change_ends_every_other_session_and_keeps_this_one(): void
    {
        $keep = $this->resident->createToken('this-phone');
        $stale = $this->resident->createToken('old-holder-of-the-number');

        $this->app['auth']->forgetGuards();
        $bearer = ['Authorization' => 'Bearer '.$keep->plainTextToken];

        $this->postJson('/api/me/phone', ['phone_number' => '09179999999', 'current_password' => 'Password123'], $bearer)->assertOk();
        $code = $this->lastCodeTexted();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/me/phone/verify', ['code' => $code], $bearer)->assertOk();

        $remaining = $this->resident->fresh()->tokens()->pluck('id')->all();
        $this->assertContains($keep->accessToken->getKey(), $remaining);
        $this->assertNotContains($stale->accessToken->getKey(), $remaining);
    }

    // --- Resending --------------------------------------------------------------

    public function test_resending_inside_the_cooldown_is_refused_and_texts_nothing(): void
    {
        $this->start()->assertOk();

        $this->asResident()->postJson('/api/me/phone/resend')
            ->assertStatus(429)->assertJsonPath('code', 'resend_too_soon');

        Http::assertSentCount(1);
    }

    public function test_resending_after_the_cooldown_retires_the_old_code(): void
    {
        $this->start()->assertOk();
        $first = $this->lastCodeTexted();
        $this->travel(61)->seconds();

        $this->asResident()->postJson('/api/me/phone/resend')
            ->assertOk()->assertJsonPath('phone_number', '+639179999999')->assertJsonPath('delivery', 'accepted');

        $this->verify($first)->assertStatus(422);
        $this->verify($this->lastCodeTexted())->assertOk();
    }

    public function test_resending_with_nothing_pending_is_a_404(): void
    {
        $this->asResident()->postJson('/api/me/phone/resend')->assertStatus(404)->assertJsonPath('code', 'no_pending_change');
    }

    // --- What is stored and logged ---------------------------------------------

    public function test_the_code_and_password_are_never_stored_in_the_clear_or_logged(): void
    {
        $this->start()->assertOk();
        $code = $this->lastCodeTexted();
        $entry = $this->pending();

        $this->assertNotSame($code, $entry['code_hash']);
        $this->assertStringNotContainsString('Password123', json_encode($entry));

        $this->verify($code)->assertOk();

        $logged = DB::table('tbl_system_logs')->get()->toJson();
        $this->assertStringNotContainsString($code, $logged);
        $this->assertStringNotContainsString('Password123', $logged);
        // The change itself is worth a log row: it moves the login.
        $this->assertTrue(DB::table('tbl_system_logs')
            ->where('auditable_type', Resident::class)->where('action_type', 'updated')->exists());
    }
}
