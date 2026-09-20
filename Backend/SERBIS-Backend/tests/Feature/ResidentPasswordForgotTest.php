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
 * A resident who forgot their password gets it back with their phone: a code by
 * text, then a short-lived token, then the new password.
 *
 * The property that matters most is what the routes do NOT say. Nothing tells an
 * outsider which numbers have accounts: step one answers the same body for a
 * number with an account, one without and a closed account, and step two
 * answers a wrong code, an expired one and an unknown number identically.
 */
class ResidentPasswordForgotTest extends TestCase
{
    use FakesSkySms, RefreshDatabase;

    private Barangay $barangay;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSkySms();
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = $this->makeResident('09171234567');
    }

    private function makeResident(string $phone, string $status = 'Active'): Resident
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'phone_number' => $phone,
            'password' => Hash::make('OldPassword1'),
            'status' => $status,
        ]);
        $resident->markPhoneAsVerified();

        return $resident;
    }

    private function forgot(string $phone = '09171234567'): TestResponse
    {
        return $this->postJson('/api/resident/password/forgot', ['phone_number' => $phone]);
    }

    private function verify(string $code, string $phone = '09171234567'): TestResponse
    {
        return $this->postJson('/api/resident/password/verify', ['phone_number' => $phone, 'code' => $code]);
    }

    private function reset(string $token, string $password = 'NewPassword1', string $phone = '09171234567'): TestResponse
    {
        return $this->postJson('/api/resident/password/reset', [
            'phone_number' => $phone,
            'reset_token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    /** Runs step one and two and returns the reset token. */
    private function token(string $phone = '09171234567'): string
    {
        $this->forgot($phone)->assertOk();

        return $this->verify($this->lastCodeTexted(), $phone)->assertOk()->json('reset_token');
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    // --- Step one: says nothing about who has an account ------------------------

    public function test_a_number_with_an_account_gets_a_code_and_a_neutral_answer(): void
    {
        $this->forgot()
            ->assertOk()
            ->assertExactJson(['message' => 'If this number has an account, a code is on its way.', 'retry_after' => 60]);

        $this->assertSame(['+639171234567'], $this->numbersTexted());
        $this->assertCount(1, $this->codesTexted());
    }

    public function test_the_answer_is_identical_for_an_account_an_unknown_number_and_a_closed_account(): void
    {
        $this->makeResident('09175550000', 'Deactivated');

        $with = $this->forgot('09171234567');
        $unknown = $this->forgot('09179999999');
        $closed = $this->forgot('09175550000');

        $this->assertSame($with->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($with->getStatusCode(), $closed->getStatusCode());
        $this->assertSame($with->getContent(), $unknown->getContent());
        $this->assertSame($with->getContent(), $closed->getContent());
        // Headers a limiter adds are the same shape too.
        $this->assertSame($with->headers->has('Retry-After'), $unknown->headers->has('Retry-After'));
    }

    public function test_nothing_is_sent_to_an_unknown_or_closed_number(): void
    {
        $this->makeResident('09175550000', 'Deactivated');

        $this->forgot('09179999999')->assertOk();
        $this->forgot('09175550000')->assertOk();

        Http::assertNothingSent();
        $this->assertNull(Cache::get('pwreset:code:'.hash('sha256', '+639179999999')));
    }

    public function test_asking_again_inside_the_cooldown_is_answered_the_same_and_texts_nothing(): void
    {
        $first = $this->forgot();
        $second = $this->forgot();

        $this->assertSame($first->getContent(), $second->getContent());
        Http::assertSentCount(1);

        // And for a number with no account, identically.
        $u1 = $this->forgot('09179999999');
        $u2 = $this->forgot('09179999999');
        $this->assertSame($u1->getContent(), $u2->getContent());
    }

    public function test_after_the_cooldown_a_new_code_replaces_the_old_one(): void
    {
        $this->forgot()->assertOk();
        $first = $this->lastCodeTexted();
        $this->travel(61)->seconds();

        $this->forgot()->assertOk();
        $second = $this->lastCodeTexted();

        $this->assertNotSame($first, $second);
        $this->verify($first)->assertStatus(422);
        $this->verify($second)->assertOk();
    }

    public function test_the_number_may_be_typed_in_any_spelling(): void
    {
        $this->forgot('+639171234567')->assertOk();

        $this->assertSame(['+639171234567'], $this->numbersTexted());
    }

    public function test_a_malformed_number_is_a_validation_error_not_an_account_lookup(): void
    {
        foreach (['0288888888', 'not-a-phone', '12345', ''] as $bad) {
            $this->forgot($bad)->assertStatus(422)->assertJsonValidationErrors('phone_number');
        }

        Http::assertNothingSent();
    }

    public function test_a_text_that_cannot_be_sent_is_still_the_same_answer_and_is_only_logged(): void
    {
        Log::spy();
        $this->smsRejects = true;

        $this->forgot()->assertExactJson(['message' => 'If this number has an account, a code is on its way.', 'retry_after' => 60]);

        // No code was stored, so there is nothing to guess.
        $this->assertNull(Cache::get('pwreset:code:'.hash('sha256', '+639171234567')));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'OTP SMS rejected'))->once();
    }

    public function test_a_timed_out_text_still_stores_the_code_because_it_may_have_gone(): void
    {
        $this->smsTimesOut = true;

        $this->forgot()->assertOk();

        $this->assertNotNull(Cache::get('pwreset:code:'.hash('sha256', '+639171234567')));
    }

    public function test_a_short_rate_limit_is_waited_out_once(): void
    {
        $this->smsShortRateLimits = 1;

        $this->forgot()->assertOk();

        Http::assertSentCount(2);
        $this->assertNotNull(Cache::get('pwreset:code:'.hash('sha256', '+639171234567')));
    }

    // --- Step two ---------------------------------------------------------------

    public function test_the_right_code_returns_a_reset_token_and_is_spent(): void
    {
        $this->forgot()->assertOk();
        $code = $this->lastCodeTexted();

        $response = $this->verify($code)->assertOk()->assertJsonStructure(['reset_token']);
        $this->assertGreaterThanOrEqual(40, strlen($response->json('reset_token')));

        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_a_wrong_code_an_expired_one_and_an_unknown_number_all_read_the_same(): void
    {
        $this->forgot()->assertOk();
        $code = $this->lastCodeTexted();

        $wrong = $this->verify($this->wrongCode($code))->assertStatus(422);
        $unknown = $this->verify('123456', '09179999999')->assertStatus(422);

        $this->travel(Resident::PHONE_CHANGE_TTL_MINUTES + 1)->minutes();
        $expired = $this->verify($code)->assertStatus(422);

        $this->assertSame($wrong->getContent(), $unknown->getContent());
        $this->assertSame($wrong->getContent(), $expired->getContent());
        $this->assertSame('invalid_code', $wrong->json('code'));
    }

    public function test_a_closed_account_cannot_verify_even_with_a_code_in_hand(): void
    {
        $this->forgot()->assertOk();
        $code = $this->lastCodeTexted();
        $this->resident->update(['status' => 'Deactivated']);

        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_the_fifth_wrong_code_retires_it_and_answers_like_any_other_wrong_one(): void
    {
        $this->forgot()->assertOk();
        $code = $this->lastCodeTexted();
        $wrong = $this->wrongCode($code);

        for ($i = 1; $i <= 4; $i++) {
            $this->verify($wrong)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        }

        // Past the per-minute budget, so what answers is the attempt cap. It is
        // a 422 like every other wrong guess — a 429 here could only ever come
        // from a real account.
        $this->travel(61)->seconds();
        $this->verify($wrong)->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->travel(61)->seconds();
        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_the_code_is_stored_hashed_and_never_logged(): void
    {
        $this->forgot()->assertOk();
        $code = $this->lastCodeTexted();
        $entry = Cache::get('pwreset:code:'.hash('sha256', '+639171234567'));

        $this->assertNotSame($code, $entry['code_hash']);
        $this->assertTrue(Hash::check($code, $entry['code_hash']));
        $this->assertStringNotContainsString($code, DB::table('tbl_system_logs')->get()->toJson());
    }

    // --- Step three -------------------------------------------------------------

    public function test_the_token_sets_a_new_password_and_the_old_one_stops_working(): void
    {
        $token = $this->token();

        $this->reset($token)->assertOk()->assertJsonPath('message', 'Your password has been changed. Log in with your new password.');

        $this->assertTrue(Hash::check('NewPassword1', $this->resident->fresh()->password));

        $this->postJson('/api/resident/login', ['phone_number' => '09171234567', 'password' => 'OldPassword1'])->assertStatus(401);
        $this->postJson('/api/resident/login', ['phone_number' => '09171234567', 'password' => 'NewPassword1'])
            ->assertStatus(403)->assertJsonPath('code', 'mfa_required');
    }

    public function test_a_reset_ends_every_session_and_does_not_sign_in(): void
    {
        $this->resident->createToken('phone');
        $this->resident->createToken('another-phone');

        $token = $this->token();
        $this->reset($token)->assertOk()->assertJsonMissingPath('token');

        $this->assertSame(0, $this->resident->fresh()->tokens()->count());
    }

    public function test_the_token_works_once(): void
    {
        $token = $this->token();

        $this->reset($token)->assertOk();
        $this->reset($token, 'AnotherPass2')->assertStatus(422)->assertJsonPath('code', 'reset_expired');

        $this->assertTrue(Hash::check('NewPassword1', $this->resident->fresh()->password));
    }

    public function test_a_token_is_bound_to_the_number_it_was_issued_for(): void
    {
        $other = $this->makeResident('09176660000');
        $token = $this->token();

        $this->reset($token, 'NewPassword1', '09176660000')->assertStatus(422)->assertJsonPath('code', 'reset_expired');

        $this->assertTrue(Hash::check('OldPassword1', $other->fresh()->password));
    }

    public function test_a_made_up_token_is_refused(): void
    {
        $this->reset(str_repeat('a', 48))->assertStatus(422)->assertJsonPath('code', 'reset_expired');
    }

    public function test_an_expired_token_is_refused(): void
    {
        $token = $this->token();
        $this->travel(Resident::PHONE_CHANGE_TTL_MINUTES + 1)->minutes();

        $this->reset($token)->assertStatus(422)->assertJsonPath('code', 'reset_expired');
    }

    public function test_a_closed_account_cannot_finish_a_reset_it_started_before_being_closed(): void
    {
        $token = $this->token();
        $this->resident->update(['status' => 'Deactivated']);

        $this->reset($token)->assertStatus(422)->assertJsonPath('code', 'reset_expired');
        $this->assertTrue(Hash::check('OldPassword1', $this->resident->fresh()->password));
    }

    public function test_a_weak_or_unconfirmed_password_is_refused_and_keeps_the_token(): void
    {
        $token = $this->token();

        $this->reset($token, 'short')->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/resident/password/reset', [
            'phone_number' => '09171234567', 'reset_token' => $token,
            'password' => 'NewPassword1', 'password_confirmation' => 'Different1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('OldPassword1', $this->resident->fresh()->password));
        // A typo in the new password does not cost the resident a fresh text.
        $this->reset($token)->assertOk();
    }

    public function test_a_reset_leaves_an_audit_row_and_no_secrets_in_it(): void
    {
        $token = $this->token();
        $code = $this->lastCodeTexted();
        $this->reset($token)->assertOk();

        $row = DB::table('tbl_system_logs')->where('action_type', 'password_reset_by_sms')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->resident->resident_id, (int) $row->resident_id);

        $logged = DB::table('tbl_system_logs')->get()->toJson();
        foreach ([$token, $code, 'NewPassword1', 'OldPassword1'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    // --- The local bypass -------------------------------------------------------

    public function test_the_local_bypass_code_covers_the_reset_code_and_is_logged(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['serbis.otp_bypass_code' => '555555']);

        $this->forgot()->assertOk();

        $this->verify('555555')->assertOk()->assertJsonStructure(['reset_token']);
        $this->assertDatabaseHas('tbl_system_logs', ['action_type' => 'otp_bypass_used', 'resident_id' => $this->resident->resident_id]);
    }

    public function test_the_bypass_code_is_refused_outside_local_and_for_a_number_with_no_code_pending(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['serbis.otp_bypass_code' => '555555']);

        $this->forgot()->assertOk();
        $this->verify('555555')->assertStatus(422);

        $this->app->detectEnvironment(fn () => 'local');
        // Local, but nobody asked for a code for this number.
        $this->verify('555555', '09179999999')->assertStatus(422);
    }

    // --- Limits, keyed on the number --------------------------------------------

    public function test_the_per_minute_limit_answers_the_same_for_a_real_and_an_unknown_number(): void
    {
        $real = [];
        $unknown = [];

        for ($i = 0; $i < 6; $i++) {
            $real[] = $this->forgot('09171234567')->getStatusCode();
            $unknown[] = $this->forgot('09179999999')->getStatusCode();
        }

        $this->assertSame($real, $unknown);
        $this->assertSame(429, $real[5], 'The sixth ask in a minute is throttled.');
    }

    public function test_the_hourly_limit_is_per_number_whatever_address_asks(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->travel(61)->seconds();
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->forgot()->assertOk();
        }

        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.9'])->forgot()->assertStatus(429);

        // A different number is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.9'])->forgot('09179999999')->assertOk();
    }
}
