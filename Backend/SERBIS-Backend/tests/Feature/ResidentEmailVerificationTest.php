<?php

namespace Tests\Feature;

use App\Mail\ResidentVerificationCode;
use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * Login still refuses an unverified account, because an abandoned registration
 * has to lead somewhere better than "invalid credentials".
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
            'app.philsms.com/*' => fn () => Http::response(['status' => $this->smsStatus], 200),
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
     * outgoing request bodies rather than the database, because what is stored
     * is a hash — the plain code exists only in the message.
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

    /** Registers, and returns the plain code that was texted out. */
    private function registerAndCaptureCode(array $overrides = []): string
    {
        $this->postJson('/api/register', $this->payload($overrides))->assertStatus(201);

        $codes = $this->codesTexted();
        $this->assertCount(1, $codes, 'Registration must text exactly one code.');

        return $codes[0];
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

    public function test_registering_texts_a_code_and_withholds_the_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->assertCount(1, $this->codesTexted());
        // The text is the delivery, so mail must not go out as well — a second
        // channel would double the cost and widen where the code can be read.
        Mail::assertNothingSent();

        // A token here would authorise an account that is not usable yet.
        $response->assertJsonMissingPath('token');
        $response->assertJsonPath('verification_required', true);
    }

    public function test_a_number_the_vendor_cannot_dial_falls_back_to_email(): void
    {
        // A landline. phone_number is a free string a resident types, so this
        // reaches registration intact and only PhilSms::normalize() rejects it.
        $this->postJson('/api/register', $this->payload([
            'phone_number' => '(078) 305 1234',
        ]))->assertStatus(201);

        $this->assertSame([], $this->codesTexted(), 'An undiallable number must not be sent to the vendor.');
        Mail::assertSent(ResidentVerificationCode::class);
    }

    public function test_a_rejected_text_falls_back_to_email(): void
    {
        // PhilSMS answers some rejections with a 200 carrying status "error",
        // which is why the controller cannot treat a 200 as delivery.
        $this->smsStatus = 'error';

        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        Mail::assertSent(ResidentVerificationCode::class);
    }

    public function test_a_new_account_starts_unverified(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $resident = Resident::where('email_address', 'grace@test.local')->first();

        $this->assertFalse($resident->hasVerifiedEmail());
        $this->assertNull($resident->email_verified_at);
    }

    public function test_the_code_is_stored_hashed_never_in_plain_text(): void
    {
        $code = $this->registerAndCaptureCode();

        $stored = DB::table('tbl_residents')
            ->where('email_address', 'grace@test.local')
            ->value('verification_code');

        // The row is readable by anything with database access and is serialised
        // by the admin panel's Users view. A usable code must not be in it.
        $this->assertNotSame($code, $stored);
        $this->assertTrue(Hash::check($code, $stored));
    }

    public function test_the_code_never_reaches_a_client(): void
    {
        $this->registerAndCaptureCode();

        $resident = Resident::where('email_address', 'grace@test.local')->first();
        $serialised = $resident->toArray();

        $this->assertArrayNotHasKey('verification_code', $serialised);
        $this->assertArrayNotHasKey('verification_code_expires_at', $serialised);
        $this->assertArrayNotHasKey('verification_code_sent_at', $serialised);
        // The flag clients actually need is there instead.
        $this->assertFalse($serialised['is_email_verified']);
    }

    public function test_the_right_code_verifies_and_returns_a_token(): void
    {
        $code = $this->registerAndCaptureCode();

        $response = $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $response->assertJsonStructure(['token', 'role', 'user']);
        $response->assertJsonPath('role', 'resident');

        $resident = Resident::where('email_address', 'grace@test.local')->first();
        $this->assertTrue($resident->hasVerifiedEmail());
    }

    public function test_verifying_clears_the_code_so_it_cannot_be_used_twice(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $resident = Resident::where('email_address', 'grace@test.local')->first();
        $this->assertNull($resident->verification_code);

        // Second attempt with the same digits is refused, not honoured.
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('code', 'already_verified');
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $code = $this->registerAndCaptureCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $wrong,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        $resident = Resident::where('email_address', 'grace@test.local')->first();
        $this->assertFalse($resident->hasVerifiedEmail());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->registerAndCaptureCode();

        DB::table('tbl_residents')
            ->where('email_address', 'grace@test.local')
            ->update(['verification_code_expires_at' => now()->subMinute()]);

        // Same message and same code as a wrong code, on purpose: telling the
        // two apart tells someone guessing which half they got right.
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_an_unknown_address_cannot_be_verified(): void
    {
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'nobody@test.local',
            'code' => '123456',
        ])->assertStatus(404);
    }

    public function test_resending_issues_a_new_code_and_retires_the_old_one(): void
    {
        $first = $this->registerAndCaptureCode();

        // Step past the per-account cooldown rather than sleeping through it.
        DB::table('tbl_residents')
            ->where('email_address', 'grace@test.local')
            ->update(['verification_code_sent_at' => now()->subMinutes(5)]);

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

    public function test_a_verified_account_cannot_ask_for_another_code(): void
    {
        $this->verifiedResident('ana@test.local');

        $this->postJson('/api/resident/verify-email/resend', [
            'email_address' => 'ana@test.local',
        ])->assertStatus(422)->assertJsonPath('code', 'already_verified');

        Mail::assertNothingSent();
    }

    public function test_an_unverified_resident_cannot_log_in(): void
    {
        $this->registerAndCaptureCode();

        $response = $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
        ])->assertStatus(403);

        // The client routes on this code, so it has to say what to do next
        // rather than reading as a bad password.
        $response->assertJsonPath('code', 'email_unverified');
        $response->assertJsonMissingPath('token');
    }

    public function test_a_verified_resident_can_log_in(): void
    {
        $code = $this->registerAndCaptureCode();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'Password123',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_a_wrong_password_on_an_unverified_account_still_reads_as_bad_credentials(): void
    {
        $this->registerAndCaptureCode();

        // Answering "unverified" before checking the password would turn login
        // into an oracle for which addresses have accounts.
        $this->postJson('/api/resident/login', [
            'email_address' => 'grace@test.local',
            'password' => 'WrongPassword123',
        ])->assertStatus(401)->assertJsonMissingPath('code');
    }

    public function test_verifying_an_address_is_written_to_the_system_log(): void
    {
        $code = $this->registerAndCaptureCode();
        $resident = Resident::where('email_address', 'grace@test.local')->first();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'grace@test.local',
            'code' => $code,
        ])->assertStatus(200);

        $logged = DB::table('tbl_system_logs')
            ->where('auditable_type', Resident::class)
            ->where('auditable_id', $resident->resident_id)
            ->where('action_type', 'updated')
            ->get()
            ->contains(fn ($row) => str_contains((string) $row->new_values, 'email_verified_at'));

        $this->assertTrue($logged, 'An address becoming verified is worth a log row.');
    }

    public function test_the_code_itself_is_never_written_to_the_system_log(): void
    {
        $this->registerAndCaptureCode();

        $rows = DB::table('tbl_system_logs')
            ->where('auditable_type', Resident::class)
            ->get();

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('verification_code', (string) $row->new_values);
            $this->assertStringNotContainsString('verification_code', (string) $row->old_values);
        }
    }
}
