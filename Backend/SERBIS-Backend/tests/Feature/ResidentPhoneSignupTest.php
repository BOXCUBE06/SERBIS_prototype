<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesSkySms;
use Tests\TestCase;

/**
 * Signing up with a phone number.
 *
 * The number is the login, so a sign-up is keyed on it and the code is texted to
 * it. Nothing is written to tbl_residents until the code comes back — an
 * abandoned or hostile sign-up never holds a number, and lapses on its own. With
 * no email there is no fallback channel: a text that cannot be sent is a 503
 * `sms_unavailable`, with the sign-up left in place so Resend can try again.
 */
class ResidentPhoneSignupTest extends TestCase
{
    use FakesSkySms, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSkySms();
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    private function pendingEntry(string $phone = '+639171234567'): ?array
    {
        $entry = Cache::get('signup:pending:'.hash('sha256', $phone));

        return is_array($entry) ? $entry : null;
    }

    private function registerAndCaptureCode(array $overrides = []): string
    {
        $this->postJson('/api/register', $this->payload($overrides))->assertStatus(201);

        $codes = $this->codesTexted();
        $this->assertCount(1, $codes, 'Registration must text exactly one code.');

        return $codes[0];
    }

    private function submit(string $code, string $phone = '09171234567'): TestResponse
    {
        return $this->postJson('/api/resident/verify-phone', ['phone_number' => $phone, 'code' => $code]);
    }

    private function resend(string $phone = '09171234567'): TestResponse
    {
        return $this->postJson('/api/resident/verify-phone/resend', ['phone_number' => $phone]);
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    private function verifiedResident(string $phone = '09170000000'): Resident
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'phone_number' => $phone,
            'password' => Hash::make('Password123'),
            'status' => 'Inactive',
        ]);
        $resident->markPhoneAsVerified();

        return $resident;
    }

    // --- Registration ---------------------------------------------------------

    public function test_registering_texts_a_code_to_the_number_and_withholds_the_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->assertSame(['+639171234567'], $this->numbersTexted());
        $this->assertCount(1, $this->codesTexted());

        $response->assertJsonMissingPath('token')
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('phone_number', '+639171234567')
            ->assertJsonPath('channel', 'sms')
            ->assertJsonPath('sent_to', '4567')
            ->assertJsonPath('delivery', 'accepted')
            ->assertJsonPath('retry_after', 60);
        // The mobile client reads any `message` on this response as an error.
        $response->assertJsonMissingPath('message');
    }

    public function test_registering_writes_nothing_to_the_database(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->assertSame(0, Resident::count());
        $this->assertNotNull($this->pendingEntry());
    }

    public function test_the_sign_up_is_keyed_on_the_canonical_number_whatever_was_typed(): void
    {
        $this->postJson('/api/register', $this->payload(['phone_number' => '+639171234567']))->assertStatus(201);

        $this->assertNotNull($this->pendingEntry());
        $this->assertSame(['+639171234567'], $this->numbersTexted());
    }

    public function test_the_code_and_the_password_are_never_stored_in_the_clear(): void
    {
        $code = $this->registerAndCaptureCode();
        $entry = $this->pendingEntry();

        $this->assertNotSame($code, $entry['code_hash']);
        $this->assertTrue(Hash::check($code, $entry['code_hash']));
        $this->assertStringNotContainsString('Password123', json_encode($entry));
        $this->assertTrue(Hash::check('Password123', $entry['attributes']['password']));
    }

    public function test_an_email_in_the_body_is_an_old_app_and_is_told_to_update(): void
    {
        $this->postJson('/api/register', $this->payload(['email_address' => 'grace@test.local']))
            ->assertStatus(410)
            ->assertJsonPath('code', 'app_update_required');

        Http::assertNothingSent();
        $this->assertNull($this->pendingEntry());
    }

    public function test_a_number_that_is_not_a_mobile_number_is_refused(): void
    {
        foreach (['0288888888', 'not-a-phone', '12345'] as $bad) {
            $this->postJson('/api/register', $this->payload(['phone_number' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone_number');
        }

        Http::assertNothingSent();
    }

    public function test_a_number_an_account_already_holds_cannot_be_registered_in_any_spelling(): void
    {
        $this->verifiedResident('09171234567');

        foreach (['09171234567', '639171234567', '+639171234567'] as $typed) {
            $this->postJson('/api/register', $this->payload(['phone_number' => $typed]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone_number');
        }

        Http::assertNothingSent();
    }

    public function test_an_abandoned_sign_up_does_not_hold_the_number(): void
    {
        $this->registerAndCaptureCode();

        // Nothing in tbl_residents, so another person's real registration of
        // the same number is not blocked by it — the unique index only sees
        // accounts that finished.
        $this->assertSame(0, Resident::count());
        $this->postJson('/api/register', $this->payload(['first_name' => 'Someone']))->assertStatus(201);
    }

    public function test_registering_again_retires_the_first_code(): void
    {
        $first = $this->registerAndCaptureCode();
        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->submit($first)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->submit($this->lastCodeTexted())->assertStatus(200);
    }

    public function test_street_address_and_account_type_are_carried_into_the_account(): void
    {
        $code = $this->registerAndCaptureCode(['middle_name' => 'Lim', 'street_address' => 'Purok 3']);

        $this->submit($code)->assertStatus(200);

        $resident = Resident::firstOrFail();
        $this->assertSame('Lim', $resident->middle_name);
        $this->assertSame('Purok 3', $resident->street_address);
        $this->assertSame('+639171234567', $resident->phone_number);
        $this->assertNull($resident->email_address);
        // Inactive on purpose: it must not opt an unverified number into paid SMS.
        $this->assertSame('Inactive', $resident->status);
        $this->assertNotNull($resident->phone_verified_at);
    }

    // --- Delivery: no email to fall back to -----------------------------------

    public function test_a_rejected_text_is_a_503_with_the_sign_up_kept_for_a_retry(): void
    {
        $this->smsRejects = true;

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'sms_unavailable');

        $this->assertNotNull($this->pendingEntry(), 'The form is kept so Resend can try again.');
        // No cooldown for a text that never went out.
        $this->smsRejects = false;
        $this->resend()->assertStatus(200)->assertJsonPath('code', 'code_sent');
    }

    public function test_out_of_credits_is_a_503_not_a_silent_success(): void
    {
        $this->smsOutOfCredits = true;

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'sms_unavailable');
    }

    public function test_a_timed_out_text_opens_the_code_screen_and_says_delivery_is_unknown(): void
    {
        Log::spy();
        $this->smsTimesOut = true;

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('channel', 'sms')
            ->assertJsonPath('delivery', 'unknown');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'outcome unknown'))
            ->once();
        // The cooldown applies: the request left, so a second text costs money.
        $this->resend()->assertStatus(429)->assertJsonPath('code', 'resend_too_soon');
    }

    public function test_a_short_rate_limit_is_waited_out_once_and_the_text_still_goes(): void
    {
        $this->smsShortRateLimits = 1;

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('delivery', 'accepted');

        Http::assertSentCount(2);
    }

    public function test_a_long_rate_limit_is_not_held_and_answers_sms_unavailable(): void
    {
        $this->smsLongRateLimits = 1;

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'sms_unavailable');

        Http::assertSentCount(1);
    }

    public function test_the_message_never_reaches_a_client_with_the_code(): void
    {
        $code = $this->registerAndCaptureCode();

        $body = $this->resend();
        // Inside the cooldown: refused, and the refusal must not echo a code.
        $this->assertStringNotContainsString($code, $body->getContent());
    }

    // --- Verifying ------------------------------------------------------------

    public function test_the_right_code_creates_the_account_and_returns_a_token(): void
    {
        $code = $this->registerAndCaptureCode();

        $response = $this->submit($code)
            ->assertStatus(200)
            ->assertJsonStructure(['token', 'role', 'user'])
            ->assertJsonPath('role', 'resident')
            ->assertJsonPath('user.is_phone_verified', true);

        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertSame(1, Resident::count());
    }

    public function test_verifying_accepts_the_number_in_any_spelling(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->submit($code, '+639171234567')->assertStatus(200);
    }

    public function test_verifying_spends_the_code_so_it_cannot_be_used_twice(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->submit($code)->assertStatus(200);
        $this->assertNull($this->pendingEntry(), 'A spent sign-up must not stay in the cache.');

        $this->submit($code)->assertStatus(422)->assertJsonPath('code', 'already_verified');
        $this->assertSame(1, Resident::count());
    }

    public function test_a_wrong_code_is_refused_creates_nothing_and_does_not_spend_the_code(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->submit($this->wrongCode($code))->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->assertSame(0, Resident::count());

        // The entry was pulled to be checked; a wrong guess has to put it back.
        $this->submit($code)->assertStatus(200);
    }

    public function test_the_fifth_wrong_code_retires_it_and_the_right_one_no_longer_works(): void
    {
        $code = $this->registerAndCaptureCode();
        $wrong = $this->wrongCode($code);

        for ($i = 1; $i <= 4; $i++) {
            $this->submit($wrong)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        }

        $this->submit($wrong)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

        // Past the route limiter's own window, so what answers is the attempt
        // cap, not the throttle.
        $this->travel(61)->seconds();
        $this->submit($code)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->assertSame(0, Resident::count());
    }

    public function test_the_sign_up_survives_the_cap_and_a_resend_finishes_it_with_a_fresh_budget(): void
    {
        $first = $this->registerAndCaptureCode();

        for ($i = 1; $i <= 5; $i++) {
            $this->submit($this->wrongCode($first));
        }

        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->resend()->assertStatus(200)->assertJsonPath('code', 'code_sent');

        $second = $this->lastCodeTexted();
        $this->assertNotSame($first, $second);

        // 422, not 429: guess one of five against the new code.
        $this->submit($this->wrongCode($second))->assertStatus(422);
        $this->submit($second)->assertStatus(200);
        $this->assertSame(1, Resident::count());
    }

    public function test_an_expired_code_is_refused_but_can_be_replaced_without_registering_again(): void
    {
        $first = $this->registerAndCaptureCode();

        $this->travel(Resident::CODE_TTL_MINUTES + 1)->minutes();

        // Same message and code as a wrong one: telling them apart tells someone
        // guessing which half they got right.
        $this->submit($first)->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->resend()->assertStatus(200)->assertJsonPath('code', 'code_sent');
        $this->submit($this->lastCodeTexted())->assertStatus(200);
    }

    public function test_a_sign_up_left_for_a_day_lapses(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->travel(25)->hours();

        $this->submit($code)->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
    }

    public function test_an_unknown_number_cannot_be_verified(): void
    {
        $this->submit('123456', '09179999999')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->submit('123456', '0288888888')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_finishing_a_sign_up_whose_number_was_taken_meanwhile_is_a_clean_refusal(): void
    {
        $code = $this->registerAndCaptureCode();

        // Someone else finished registering this number in the gap. Inserted
        // straight into the table: the unique index is what has to decide.
        DB::table('tbl_residents')->insert([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Other', 'last_name' => 'Person',
            'phone_number' => '+639171234567', 'password' => 'x', 'status' => 'Active',
            'account_type' => 'head_of_family', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->submit($code)->assertStatus(422)->assertJsonPath('code', 'already_verified');
        $this->assertSame(1, Resident::count());
    }

    // --- Resending ------------------------------------------------------------

    public function test_resending_issues_a_new_code_and_retires_the_old_one(): void
    {
        $first = $this->registerAndCaptureCode();
        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->resend()
            ->assertStatus(200)
            ->assertJsonPath('code', 'code_sent')
            ->assertJsonPath('sent_to', '4567')
            ->assertJsonPath('delivery', 'accepted');

        $this->submit($first)->assertStatus(422);
        $this->submit($this->lastCodeTexted())->assertStatus(200);
    }

    public function test_resending_inside_the_cooldown_is_refused_and_does_not_text(): void
    {
        $this->registerAndCaptureCode();

        $this->resend()
            ->assertStatus(429)
            ->assertJsonPath('code', 'resend_too_soon')
            ->assertJsonStructure(['retry_after']);

        Http::assertSentCount(1);
    }

    public function test_resending_does_not_extend_the_sign_up_window(): void
    {
        $this->registerAndCaptureCode();
        $expires = $this->pendingEntry()['signup_expires_at'];

        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->resend()->assertStatus(200);

        // Resending moves the code's clock, not the sign-up's.
        $this->assertSame($expires, $this->pendingEntry()['signup_expires_at']);
    }

    public function test_a_registered_number_cannot_ask_for_another_code(): void
    {
        $this->verifiedResident('09171234567');

        $this->resend()->assertStatus(422)->assertJsonPath('code', 'already_verified');
        Http::assertNothingSent();
    }

    public function test_resending_for_a_number_with_no_sign_up_is_a_404(): void
    {
        $this->resend('09179999999')->assertStatus(404)->assertJsonPath('code', 'not_found');
        Http::assertNothingSent();
    }

    // --- An unfinished sign-up at login ---------------------------------------

    public function test_an_unfinished_sign_up_that_logs_in_is_sent_back_to_the_code_screen_with_a_fresh_code(): void
    {
        $this->registerAndCaptureCode();
        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/resident/login', ['phone_number' => '09171234567', 'password' => 'Password123'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'phone_unverified')
            ->assertJsonPath('phone_number', '+639171234567')
            ->assertJsonPath('channel', 'sms');

        $this->assertCount(2, $this->codesTexted());
    }

    public function test_logging_in_again_inside_the_cooldown_does_not_text_twice(): void
    {
        $this->registerAndCaptureCode();

        $this->postJson('/api/resident/login', ['phone_number' => '09171234567', 'password' => 'Password123'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'phone_unverified');

        Http::assertSentCount(1);
    }

    public function test_a_wrong_password_on_an_unfinished_sign_up_reads_as_bad_credentials(): void
    {
        $this->registerAndCaptureCode();

        $this->postJson('/api/resident/login', ['phone_number' => '09171234567', 'password' => 'Wrongpass123'])
            ->assertStatus(401);
    }

    // --- The record -----------------------------------------------------------

    public function test_a_claimed_account_is_one_created_row_in_the_system_log_and_the_code_is_not_in_it(): void
    {
        $code = $this->registerAndCaptureCode();
        $this->submit($code)->assertStatus(200);

        $rows = DB::table('tbl_system_logs')->where('auditable_type', Resident::class)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('created', $rows[0]->action_type);

        $logged = $rows->toJson();
        $this->assertStringNotContainsString($code, $logged);
        $this->assertStringNotContainsString('Password123', $logged);
        $this->assertStringNotContainsString('"password"', $logged);
    }

    public function test_an_abandoned_sign_up_leaves_no_trace_in_the_system_log(): void
    {
        $this->registerAndCaptureCode();

        $this->assertSame(0, DB::table('tbl_system_logs')->where('auditable_type', Resident::class)->count());
    }
}
