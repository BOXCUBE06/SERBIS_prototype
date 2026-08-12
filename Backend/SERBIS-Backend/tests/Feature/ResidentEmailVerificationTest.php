<?php

namespace Tests\Feature;

use App\Mail\ResidentVerificationCode;
use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email verification on resident sign-up.
 *
 * Asked for by the adviser. It began as SMS and moved to email because an SMS
 * API with OTP was hard to source — which also removed the reason the earlier
 * decision refused it, since SkySMS bills per send with no sandbox and email
 * costs nothing to test.
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

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
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

    /** Registers, and returns the plain code that was mailed out. */
    private function registerAndCaptureCode(array $overrides = []): string
    {
        $this->postJson('/api/register', $this->payload($overrides))->assertStatus(201);

        $sent = null;
        Mail::assertSent(ResidentVerificationCode::class, function ($mail) use (&$sent) {
            $sent = $mail->code;
            return true;
        });

        return $sent;
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

    public function test_registering_mails_a_code_and_withholds_the_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);

        Mail::assertSent(ResidentVerificationCode::class);

        // A token here would authorise an account that is not usable yet.
        $response->assertJsonMissingPath('token');
        $response->assertJsonPath('verification_required', true);
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

        $second = null;
        Mail::assertSent(ResidentVerificationCode::class, function ($mail) use (&$second, $first) {
            if ($mail->code !== $first) {
                $second = $mail->code;
            }
            return true;
        });

        $this->assertNotNull($second, 'A resend must not mail the same digits again.');

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

        // Only the registration email went out.
        Mail::assertSentCount(1);
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
