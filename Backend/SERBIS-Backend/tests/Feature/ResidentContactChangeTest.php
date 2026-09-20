<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Moving the two contacts a login code is delivered to.
 *
 * PATCH /me used to write email_address and phone_number on a bearer token
 * alone. sendLoginCode() texts the number and falls back to the address, so a
 * token lifted from a shared phone bought every future sign-in: rewrite both,
 * and the codes arrive at the attacker's handset from then on. The real owner
 * has no way back — there is no self-serve password reset anywhere in the API,
 * and an admin resetting the password does not undo a changed phone number.
 *
 * Two things close it. The current password is required whenever either field
 * actually moves, and a changed address loses its verified state so the next
 * sign-in has to prove the new one through the flow residentLogin() already
 * runs for an unverified row.
 */
class ResidentContactChangeTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $home;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // test_the_next_login_has_to_verify_the_new_address reaches
        // issueSignupCode(), and phpunit.xml sets a SKYSMS_API_KEY precisely so
        // the OTP takes its real SMS path. SkySMS has no sandbox, so an
        // escaped request is a real call to the vendor — preventStrayRequests()
        // turns that into a test failure instead.
        Http::preventStrayRequests();
        Http::fake([
            'skysms.skyio.site/*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->home = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $this->home->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        // Verified, as every account that finished signing up is — the point of
        // the assertions below is that a change takes this away again.
        $this->resident->markEmailAsVerified();
    }

    public function test_changing_the_email_without_the_password_is_refused(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'email_address' => 'attacker@evil.local',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame('maria@test.local', $this->resident->fresh()->email_address);
    }

    public function test_changing_the_phone_number_without_the_password_is_refused(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'phone_number' => '09179999999',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame('+639171111111', $this->resident->fresh()->phone_number);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'phone_number' => '09179999999',
            'current_password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame('+639171111111', $this->resident->fresh()->phone_number);
    }

    /**
     * A refusal must not half-apply. first_name is a field this endpoint would
     * otherwise have written, and it rides on the same call.
     */
    public function test_a_refused_call_writes_nothing_at_all(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Rewritten',
            'email_address' => 'attacker@evil.local',
        ])->assertStatus(422);

        $this->assertSame('Maria', $this->resident->fresh()->first_name);
    }

    public function test_the_correct_password_lets_the_contacts_move(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'email_address' => 'maria.clara@test.local',
            'phone_number' => '09179999999',
            'current_password' => 'password123',
        ])->assertOk();

        $fresh = $this->resident->fresh();

        $this->assertSame('maria.clara@test.local', $fresh->email_address);
        $this->assertSame('+639179999999', $fresh->phone_number);
    }

    /**
     * The other half. A new address has not been proved, so it must not inherit
     * the old one's verified state.
     */
    public function test_changing_the_email_clears_the_verified_state(): void
    {
        $this->assertTrue($this->resident->hasVerifiedEmail());

        $this->actingAs($this->resident)->patchJson('/api/me', [
            'email_address' => 'maria.clara@test.local',
            'current_password' => 'password123',
        ])->assertOk()->assertJsonPath('user.is_email_verified', false);

        $this->assertFalse($this->resident->fresh()->hasVerifiedEmail());
    }

    /**
     * residentLogin() is what enforces it. An unverified row is answered with a
     * 403 and a code in flight rather than a token, so the new address has to
     * be proved before the account is usable again.
     */
    public function test_the_next_login_has_to_verify_the_new_address(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'email_address' => 'maria.clara@test.local',
            'current_password' => 'password123',
        ])->assertOk();

        // Drop the acting-as guard, or the login below is answered from the
        // session this test is already holding.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/resident/login', [
            'email_address' => 'maria.clara@test.local',
            'password' => 'password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_unverified')
            ->assertJsonMissingPath('token');

        // Proves the setUp() fake is load-bearing rather than decorative: this
        // path really does reach a vendor send, so an unfaked run of this test
        // would be a live call to SkySMS, which has no sandbox.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'skysms.skyio.site'));
    }

    /** Changing the phone number is not an email change, so verification survives it. */
    public function test_changing_only_the_phone_number_leaves_the_email_verified(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'phone_number' => '09179999999',
            'current_password' => 'password123',
        ])->assertOk();

        $this->assertTrue($this->resident->fresh()->hasVerifiedEmail());
    }

    /**
     * The fields that are not a credential still save on a token alone. A
     * resident correcting a typo in their surname must not be asked for a
     * password, and the SMS toggle is a one-tap control on the profile screen.
     */
    public function test_the_ordinary_profile_fields_need_no_password(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Maria Clara',
            'last_name' => 'Santos-Cruz',
            'middle_name' => 'Reyes',
            'sms_opt_in' => false,
        ])->assertOk();

        $fresh = $this->resident->fresh();

        $this->assertSame('Maria Clara', $fresh->first_name);
        $this->assertSame('Santos-Cruz', $fresh->last_name);
        $this->assertFalse($fresh->sms_opt_in);
        $this->assertTrue($fresh->hasVerifiedEmail());
    }

    /**
     * Resubmitting the values already stored is not a change and must not ask
     * for anything. A client that PATCHes the whole profile rather than a diff
     * would otherwise be unable to save at all.
     */
    public function test_resubmitting_the_same_contacts_unchanged_needs_no_password(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Maria Clara',
            'email_address' => 'maria@test.local',
            'phone_number' => '09171111111',
        ])->assertOk();

        $fresh = $this->resident->fresh();

        $this->assertSame('Maria Clara', $fresh->first_name);
        // Not a change, so the verified state is untouched.
        $this->assertTrue($fresh->hasVerifiedEmail());
    }
}
