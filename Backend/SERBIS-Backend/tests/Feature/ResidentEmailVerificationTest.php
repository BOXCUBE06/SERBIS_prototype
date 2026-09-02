<?php

namespace Tests\Feature;

use App\Mail\ResidentVerificationCode;
use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email verification on resident sign-up.
 *
 * Asked for by the adviser. It began as SMS, moved to email while no OTP-capable
 * SMS API was sourced, and is back on SMS now that PhilSMS is wired up: a
 * resident registering on a phone reads the code without leaving the handset.
 * Mail is the fallback for a number the vendor cannot dial, so both paths are
 * covered below. The column is still email_verified_at whichever channel
 * carried the code — what it records is that the account was claimed.
 *
 * The gate is at registration, not at login. A resident finishes signing up by
 * entering the code, and after that never meets a verification screen again.
 * Login still refuses an unverified sign-up, because an abandoned registration
 * has to lead somewhere better than "invalid credentials".
 *
 * 2026-08-31: an unfinished sign-up no longer touches tbl_residents. It used to
 * be inserted at POST /register and verified afterwards, which meant an
 * abandoned or hostile attempt permanently held an email address and a phone
 * number that only an admin could release. It is now a cache entry, on the same
 * pattern as the MFA login challenge, and the row is inserted by
 * /resident/verify-email — already verified. The tests that used to read the
 * pending code off tbl_residents read the cache entry instead, and the ones
 * below that assert nothing is written until the code comes back are the point
 * of the change.
 */
class ResidentEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    /**
     * What the faked vendor answers. A second Http::fake() does not replace the
     * first stub for the same URL — the earlier one still matches — so a test
     * that needs a rejection flips this instead of re-faking.
     */
    private string $smsStatus = 'success';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // PhilSMS has no sandbox. An escaped request is a billed real send.
        Http::fake([
            'dashboard.philsms.com/*' => fn () => Http::response(['status' => $this->smsStatus], 200),
        ]);
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234567',
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    /**
     * Every six-digit code PhilSMS was asked to text, oldest first. Read off the
     * outgoing request bodies rather than storage, because what is stored is a
     * hash — the plain code exists only in the message.
     */
    private function codesTexted(): array
    {
        return Http::recorded()
            ->map(function ($pair) {
                preg_match('/[0-9]{6}/', $pair[0]['message'] ?? '', $match);

                return $match[0] ?? null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The pending sign-up itself. Keyed exactly as AuthController keys it — by a
     * hash of the address, so the cache store never holds a list of every email
     * that has started registering.
     */
    private function pendingEntry(string $email = 'grace@test.local'): ?array
    {
        $entry = Cache::get('signup:pending:'.hash('sha256', $email));

        return is_array($entry) ? $entry : null;
    }

    /** Registers, and returns the plain code that was texted out. */
    private function registerAndCaptureCode(array $overrides = []): string
    {
        $this->postJson('/api/register', $this->payload($overrides))->assertStatus(201);

        $codes = $this->codesTexted();
        $this->assertCount(1, $codes, 'Registration must text exactly one code.');

        return $codes[0];
    }

    /** Registers and finishes, leaving a real verified account behind. */
    private function registerAndVerify(array $overrides = []): void
    {
        $code = $this->registerAndCaptureCode($overrides);

        $this->postJson('/api/resident/verify-email', [
            'email_address' => $overrides['email_address'] ?? 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);
    }

    private function verifiedResident(string $email = 'verified@test.local'): Resident
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'phone_number' => '09170000000',
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'status' => 'Inactive',
        ]);

        $resident->markEmailAsVerified();

        return $resident;
    }

    /**
     * An unverified row of the shape the old flow left behind. Nothing writes
     * one any more, but production has them and they still have to be able to
     * finish — the controller adopts them into a pending sign-up.
     */
    private function legacyUnverifiedResident(array $overrides = []): Resident
    {
        return Resident::create(array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
            'email_address' => 'grace@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Inactive',
        ], $overrides));
    }

    // --- Registration ---------------------------------------------------------

    public function test_registering_texts_a_code_and_withholds_the_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->assertCount(1, $this->codesTexted());
        // The text is the delivery, so mail must not go out as well — a second
        // channel would double the cost and widen where the code can be read.
        Mail::assertNothingSent();

        // A token here would authorise an account that does not exist yet.
        $response->assertJsonMissingPath('token');
        $response->assertJsonPath('verification_required', true);
        $response->assertJsonPath('email_address', 'grace@test.local');
    }

    public function test_registering_writes_nothing_to_tbl_residents(): void
    {
        $this->registerAndCaptureCode();

        $this->assertSame(0, Resident::count(), 'An unverified sign-up must not be a row.');
        // It is not lost, though — it is waiting for its code.
        $this->assertNotNull($this->pendingEntry());
    }

    public function test_the_register_response_no_longer_carries_a_resident(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);

        // There is no row to return. The mobile client never read this key —
        // it routes on `verification_required` and labels the code screen from
        // channel/sent_to/retry_after, all of which are still here.
        $response->assertJsonMissingPath('resident');
        $response->assertJsonMissingPath('resident.resident_id');
        $response->assertJsonStructure(['channel', 'sent_to', 'retry_after']);
    }

    public function test_an_abandoned_sign_up_does_not_hold_the_email_address(): void
    {
        // The whole point of the change. Under the old flow this second
        // registration was a 422 on the `unique` rule, and the address stayed
        // taken until an admin deleted the row.
        $this->registerAndCaptureCode();

        $this->travel(61)->seconds();

        $this->postJson('/api/register', $this->payload([
            'first_name' => 'Grace',
            'phone_number' => '09179999999',
        ]))->assertStatus(201);

        $this->assertSame(0, Resident::count());
    }

    public function test_registering_again_retires_the_first_code(): void
    {
        $first = $this->registerAndCaptureCode();

        $this->travel(61)->seconds();

        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $second = collect($this->codesTexted())->first(fn (string $code) => $code !== $first);
        $this->assertNotNull($second, 'A second registration must issue a new code.');

        // Two live codes for one address would double the guessing surface.
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $first,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $second,
        ])->assertStatus(200);
    }

    public function test_a_verified_address_still_cannot_be_registered_again(): void
    {
        $this->verifiedResident('ana@test.local');

        // The `unique` rule stays: it now refuses a real account and never an
        // abandoned attempt, which is exactly what it was meant to do.
        $this->postJson('/api/register', $this->payload(['email_address' => 'ana@test.local']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email_address');
    }

    // --- Where the code and the password actually live ------------------------

    public function test_the_code_is_stored_hashed_never_in_plain_text(): void
    {
        $code = $this->registerAndCaptureCode();

        $entry = $this->pendingEntry();

        // The cache store is a file tree in production and a table in
        // development. A usable code must not be readable in either.
        $this->assertNotSame($code, $entry['code_hash']);
        $this->assertTrue(Hash::check($code, $entry['code_hash']));
    }

    public function test_the_password_is_hashed_before_it_reaches_the_cache(): void
    {
        $this->registerAndCaptureCode();

        $stored = $this->pendingEntry()['attributes']['password'];

        $this->assertNotSame('Password123', $stored);
        $this->assertTrue(Hash::check('Password123', $stored));
    }

    public function test_the_code_never_reaches_a_client(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $code = $this->codesTexted()[0];

        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertStringNotContainsString('Password123', $response->getContent());
    }

    public function test_a_verified_account_serialises_without_credentials(): void
    {
        $this->registerAndVerify();

        $serialised = Resident::where('email_address', 'grace@test.local')->first()->toArray();

        $this->assertArrayNotHasKey('password', $serialised);
        $this->assertArrayNotHasKey('verification_code', $serialised);
        // The flag clients actually need is there instead.
        $this->assertTrue($serialised['is_email_verified']);
    }

    // --- Delivery -------------------------------------------------------------

    public function test_a_number_the_vendor_cannot_dial_falls_back_to_email(): void
    {
        // 2026-08-31: registration requires a real mobile shape (see
        // ValidationLengthLimitsTest and PhilSms::PHONE_REGEX), so a landline
        // can no longer reach this state through POST /register. It can still
        // exist on a row written before that rule — created directly here to
        // stand in for one — and AuthController::smsIsUsable() must still fall
        // back to email for it rather than assume every stored number is
        // dialable. This also exercises the legacy-row adoption path: the row
        // has no cache entry, so resend has to build one around it.
        $this->legacyUnverifiedResident(['phone_number' => '(078) 305 1234']);

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(200)->assertJsonPath('channel', 'email');

        $this->assertSame([], $this->codesTexted(), 'An undiallable number must not be sent to the vendor.');
        Mail::assertSent(ResidentVerificationCode::class);
    }

    public function test_a_rejected_text_falls_back_to_email(): void
    {
        // PhilSMS answers some rejections with a 200 carrying status "error",
        // which is why the controller cannot treat a 200 as delivery.
        $this->smsStatus = 'error';

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('channel', 'email')
            ->assertJsonPath('sent_to', 'grace@test.local');

        Mail::assertSent(ResidentVerificationCode::class);
    }

    public function test_the_response_names_the_contact_the_code_went_to(): void
    {
        $this->postJson('/api/register', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('channel', 'sms')
            // Last four digits only: enough to recognise, not enough to write a
            // whole phone number into a response body.
            ->assertJsonPath('sent_to', '4567');
    }

    // --- Verifying ------------------------------------------------------------

    public function test_the_right_code_creates_the_account_and_returns_a_token(): void
    {
        $code = $this->registerAndCaptureCode();

        $response = $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $response->assertJsonStructure(['token', 'role', 'user']);
        $response->assertJsonPath('role', 'resident');

        $resident = Resident::where('email_address', 'grace@test.local')->first();
        $this->assertNotNull($resident, 'The row is created here, not at registration.');
        $this->assertTrue($resident->hasVerifiedEmail());
    }

    public function test_the_created_account_carries_the_registered_details(): void
    {
        $code = $this->registerAndCaptureCode(['middle_name' => 'Lim']);

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $resident = Resident::where('email_address', 'grace@test.local')->first();

        $this->assertSame('Grace', $resident->first_name);
        $this->assertSame('Lim', $resident->middle_name);
        $this->assertSame('Reyes', $resident->last_name);
        $this->assertSame('09171234567', $resident->phone_number);
        $this->assertSame($this->barangay->barangay_id, $resident->barangay_id);
        // Still Inactive: activation is the MDRRMO's call and gates paid SMS.
        $this->assertSame('Inactive', $resident->status);
        // The password survived the cache round trip as the same hash.
        $this->assertTrue(Hash::check('Password123', $resident->password));
    }

    public function test_verifying_spends_the_code_so_it_cannot_be_used_twice(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $this->assertNull($this->pendingEntry(), 'A spent sign-up must not stay in the cache.');
        $this->assertSame(1, Resident::count(), 'Verifying twice must not insert twice.');

        // Second attempt with the same digits is refused, not honoured.
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('code', 'already_verified');

        $this->assertSame(1, Resident::count());
    }

    public function test_a_wrong_code_is_refused_and_creates_nothing(): void
    {
        $code = $this->registerAndCaptureCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $wrong,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->assertSame(0, Resident::count());
    }

    public function test_a_wrong_guess_does_not_spend_the_code(): void
    {
        // The entry is pulled from the cache to be checked, so a wrong guess
        // has to put it back — otherwise one fat-fingered digit would end the
        // registration.
        $code = $this->registerAndCaptureCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $wrong,
        ])->assertStatus(422);

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);
    }

    /** Any six digits that are not the real code. */
    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    private function submit(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ]);
    }

    /**
     * Guessing a six-digit code was bounded only by the route limiter, whose
     * tight tier keys on the IP as well as the address — so the ceiling was per
     * source address, and spreading the guesses across addresses raised it.
     * The login challenge has had a per-code cap since it was written; this is
     * the same cap on the sign-up code.
     */
    public function test_the_fifth_wrong_code_retires_it(): void
    {
        $code = $this->registerAndCaptureCode();
        $wrong = $this->wrongCode($code);

        // Four wrong guesses are refused and leave the code alive.
        for ($i = 1; $i <= 4; $i++) {
            $this->submit($wrong)
                ->assertStatus(422)
                ->assertJsonPath('code', 'invalid_code');
        }

        $this->submit($wrong)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_the_right_code_stops_working_once_the_cap_is_hit(): void
    {
        $code = $this->registerAndCaptureCode();
        $wrong = $this->wrongCode($code);

        for ($i = 1; $i <= 5; $i++) {
            $this->submit($wrong);
        }

        // Past the route limiter's own window. Five guesses is exactly its
        // per-minute budget for this address, so without this step the next
        // request would be answered by the throttle and this test would prove
        // nothing about the attempt cap.
        $this->travel(61)->seconds();

        // The code that WOULD have worked. This is the assertion that matters:
        // the cap retires the secret rather than merely counting against it.
        $this->submit($code)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');

        $this->assertSame(0, Resident::count());
    }

    /**
     * The deliberate difference from consumeMfaChallengeAttempt(), which
     * destroys its challenge outright. A pending sign-up also holds the
     * registration form, and for a fresh sign-up there is no tbl_residents row
     * behind it — so destroying it would send the resident back to an empty
     * form, and leave the code screen's Resend button answering 404.
     */
    public function test_the_sign_up_survives_the_cap_and_a_resend_finishes_it(): void
    {
        $first = $this->registerAndCaptureCode();
        $wrong = $this->wrongCode($first);

        for ($i = 1; $i <= 5; $i++) {
            $this->submit($wrong);
        }

        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(200)->assertJsonPath('code', 'code_sent');

        $second = collect($this->codesTexted())->first(fn (string $code) => $code !== $first);
        $this->assertNotNull($second);

        $this->submit($second)->assertStatus(200);

        $this->assertSame(1, Resident::count());
    }

    /**
     * A new code is a new secret, so it gets a new budget — the same reset
     * sendLoginCode() performs. Without it the first wrong guess after a resend
     * would answer 429 against a code nobody had guessed yet.
     */
    public function test_a_resend_restores_the_budget_of_guesses(): void
    {
        $first = $this->registerAndCaptureCode();
        $wrong = $this->wrongCode($first);

        for ($i = 1; $i <= 5; $i++) {
            $this->submit($wrong);
        }

        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(200);

        // 422, not 429: this is guess one of five against the new code.
        $this->submit($this->wrongCode($first))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_code');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->travel(Resident::CODE_TTL_MINUTES + 1)->minutes();

        // Same message and same code as a wrong code, on purpose: telling the
        // two apart tells someone guessing which half they got right.
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_an_expired_code_can_still_be_replaced_without_registering_again(): void
    {
        // The code expires in 15 minutes but the sign-up itself is kept for a
        // day, so a resident who put the phone down taps Resend rather than
        // retyping the whole form.
        $first = $this->registerAndCaptureCode();

        $this->travel(Resident::CODE_TTL_MINUTES + 1)->minutes();

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(200)->assertJsonPath('code', 'code_sent');

        $second = collect($this->codesTexted())->first(fn (string $code) => $code !== $first);

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $second,
        ])->assertStatus(200);
    }

    public function test_a_sign_up_left_for_a_day_lapses_and_frees_the_address(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->travel(25)->hours();

        // Nothing to finish, and the message says what to do about it.
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(404)->assertJsonPath('code', 'not_found');

        // And the address is free, which under the old flow it never was.
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
    }

    public function test_an_unknown_address_cannot_be_verified(): void
    {
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'nobody@test.local',
            'code' => '123456',
        ])->assertStatus(404);
    }

    // --- Resending ------------------------------------------------------------

    public function test_resending_issues_a_new_code_and_retires_the_old_one(): void
    {
        $first = $this->registerAndCaptureCode();

        // Step past the per-sign-up cooldown rather than sleeping through it.
        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(200)->assertJsonPath('code', 'code_sent');

        $codes = $this->codesTexted();
        $second = collect($codes)->first(fn (string $code) => $code !== $first);

        $this->assertNotNull($second, 'A resend must not text the same digits again.');

        // The old code must stop working, or each resend widens the window
        // instead of moving it.
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $first,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $second,
        ])->assertStatus(200);
    }

    public function test_resending_inside_the_cooldown_is_refused(): void
    {
        $this->registerAndCaptureCode();

        $response = $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(429);

        $response->assertJsonPath('code', 'resend_too_soon');
        $this->assertGreaterThan(0, $response->json('retry_after'));

        // Only the registration text went out.
        $this->assertCount(1, $this->codesTexted());
    }

    public function test_resending_does_not_extend_the_sign_up_window(): void
    {
        $this->registerAndCaptureCode();

        // Nearly a day in, one resend, then past the original window. The code's
        // clock moves; the sign-up's does not, or an unfinished registration
        // could be held open forever by tapping Resend.
        $this->travel(23)->hours();

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(200);

        $latest = collect($this->codesTexted())->last();

        $this->travel(2)->hours();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $latest,
        ])->assertStatus(404);
    }

    public function test_a_verified_account_cannot_ask_for_another_code(): void
    {
        $this->verifiedResident('ana@test.local');

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'ana@test.local',
        ])->assertStatus(422)->assertJsonPath('code', 'already_verified');

        Mail::assertNothingSent();
    }

    public function test_resending_for_an_address_with_no_sign_up_is_a_404(): void
    {
        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'nobody@test.local',
        ])->assertStatus(404)->assertJsonPath('code', 'not_found');

        $this->assertSame([], $this->codesTexted());
        Mail::assertNothingSent();
    }

    // --- Login against an unfinished sign-up ----------------------------------

    public function test_an_unverified_sign_up_cannot_log_in(): void
    {
        $this->registerAndCaptureCode();

        $response = $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
        ])->assertStatus(403);

        // There is no row at all now, so without the pending sign-up this would
        // read as a bad password. The client routes on this code, so it has to
        // say what to do next.
        $response->assertJsonPath('code', 'email_unverified');
        $response->assertJsonMissingPath('token');
    }

    public function test_logging_in_unverified_texts_a_fresh_code(): void
    {
        $first = $this->registerAndCaptureCode();

        // Past the cooldown, so this login is entitled to send.
        $this->travel(Resident::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $response = $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
        ])->assertStatus(403);

        $codes = $this->codesTexted();
        $this->assertCount(2, $codes, 'The refused login must text a code of its own.');
        $this->assertNotSame($first, $codes[1], 'The login must issue a new code, not resend the old one.');

        // What the code screen needs to label itself and start its counter.
        $response->assertJsonPath('channel', 'sms');
        $response->assertJsonPath('sent_to', '4567');
        $this->assertSame(Resident::RESEND_COOLDOWN_SECONDS, $response->json('retry_after'));
    }

    public function test_logging_in_again_inside_the_cooldown_does_not_text_twice(): void
    {
        $this->registerAndCaptureCode();

        // Registration just sent one, so the cooldown is running.
        $response = $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
        ])->assertStatus(403);

        $this->assertCount(1, $this->codesTexted(), 'A login inside the cooldown must not bill a second send.');
        // Still told where the outstanding code went, and how long is left on
        // it. The entry records the contact it was sent to, so this is the real
        // channel rather than a guess made from the number.
        $response->assertJsonPath('channel', 'sms');
        $response->assertJsonPath('sent_to', '4567');
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }

    public function test_a_wrong_password_on_an_unfinished_sign_up_reads_as_bad_credentials(): void
    {
        $this->registerAndCaptureCode();

        // Answering "unverified" before checking the password would turn login
        // into an oracle for which addresses have a sign-up in flight.
        $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'WrongPassword123',
        ])->assertStatus(401)->assertJsonMissingPath('code');

        $this->assertCount(1, $this->codesTexted(), 'A failed login must not send a code.');
    }

    public function test_a_login_that_cannot_be_texted_falls_back_to_email(): void
    {
        // Same 2026-08-31 change as the test above: '12345' can no longer
        // register, so this stands in for a pre-existing row with a malformed
        // number, and proves the login path adopts such a row and still falls
        // back to mail for it.
        $this->legacyUnverifiedResident(['phone_number' => '12345']);

        $response = $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
        ])->assertStatus(403);

        $response->assertJsonPath('code', 'email_unverified');
        $response->assertJsonPath('channel', 'email');
        $response->assertJsonPath('sent_to', 'grace@test.local');
        Mail::assertSent(ResidentVerificationCode::class, 1);
    }

    public function test_a_legacy_unverified_row_is_verified_in_place(): void
    {
        // The row already exists, so verifying must mark it rather than insert
        // a second one against the unique index on email_address.
        $legacy = $this->legacyUnverifiedResident();

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'grace@test.local',
        ])->assertStatus(200);

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $this->codesTexted()[0],
        ])->assertStatus(200)->assertJsonStructure(['token']);

        $this->assertSame(1, Resident::count());
        $this->assertTrue($legacy->refresh()->hasVerifiedEmail());
    }

    public function test_a_verified_resident_can_log_in(): void
    {
        $this->registerAndVerify();

        $login = $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
        ])->assertStatus(403)->assertJsonPath('code', 'mfa_required');

        $loginCode = collect($this->codesTexted())->last();

        $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $login->json('challenge_id'),
            'code' => $loginCode,
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    // --- The system log -------------------------------------------------------

    public function test_a_claimed_account_is_written_to_the_system_log(): void
    {
        $this->registerAndVerify();

        $resident = Resident::where('email_address', 'grace@test.local')->first();

        // One `created` row, not a create-then-verify pair: the account's first
        // appearance in the table is as a claimed one.
        $logged = DB::table('tbl_system_logs')
            ->where('auditable_type', Resident::class)
            ->where('auditable_id', $resident->resident_id)
            ->where('action_type', 'created')
            ->get()
            ->contains(fn ($row) => str_contains((string) $row->new_values, 'email_verified_at'));

        $this->assertTrue($logged, 'An address becoming verified is worth a log row.');
    }

    public function test_an_abandoned_sign_up_leaves_no_trace_in_the_system_log(): void
    {
        $this->registerAndCaptureCode();

        $this->assertSame(
            0,
            DB::table('tbl_system_logs')->where('auditable_type', Resident::class)->count(),
            'Nothing happened to tbl_residents, so nothing should be logged about it.',
        );
    }

    public function test_the_code_and_the_password_are_never_written_to_the_system_log(): void
    {
        $code = $this->registerAndCaptureCode();
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $rows = DB::table('tbl_system_logs')
            ->where('auditable_type', Resident::class)
            ->get();

        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            foreach ([$row->new_values, $row->old_values] as $values) {
                $this->assertStringNotContainsString('verification_code', (string) $values);
                $this->assertStringNotContainsString($code, (string) $values);
                $this->assertStringNotContainsString('password', (string) $values);
            }
        }
    }
}
