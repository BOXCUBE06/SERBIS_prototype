<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Which resident statuses may sign in.
 *
 * HISTORY, because this file was written to stop the question coming back and
 * then the answer changed. Quality-check finding 5 (2026-08-05) asked why
 * `residentLogin` has no `status` check when `adminLogin` does. On 2026-08-08
 * that was answered deliberately — leave it open — and this file pinned that
 * as assertions, ending: "If the product decision is ever reversed, these
 * tests SHOULD fail. Rewrite them, do not delete them."
 *
 * REVERSED 2026-09-03. The admin panel's "Deactivate account" button did not
 * deactivate anything a person would recognise: the resident stayed signed in,
 * kept filing requests, and only dropped out of SMS blasts.
 *
 * Rewritten rather than deleted, as instructed — and note which tests did NOT
 * change. 'Inactive' and unrecognised values still sign in, and those cases
 * are now the regression guard rather than the point: the reversal gates one
 * named value, 'Deactivated', and a fail-closed `!== 'Active'` check would
 * have locked out every self-registered account and every miscased row, with
 * no self-serve way back in.
 *
 * "Mirror User::isDeactivated()" was the other instruction, and it is a trap
 * taken literally: that method compares against 'inactive', which is the
 * ADMIN vocabulary for a closed account. For a resident 'Inactive' means
 * awaiting activation. Resident::isDeactivated() mirrors its shape — case
 * folded, one named value, not fail-closed — and not its value.
 *
 * What deactivation then costs the resident is ResidentDeactivationTest.
 */
class ResidentLoginStatusTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        // Login now ends in an SMS-challenge step (see AuthController::residentLogin);
        // SkySMS has no sandbox, so the vendor is faked the same way
        // ResidentEmailVerificationTest fakes it for the signup code.
        Http::fake([
            'skysms.skyio.site/*' => fn () => Http::response(['status' => 'success'], 200),
        ]);
        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $status): Resident
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09'.random_int(100000000, 999999999),
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('password123'),
            'status' => $status,
        ]);

        // Verified on purpose. Email verification (2026-08-11) added a second
        // reason login can refuse, and these tests are about `status` alone —
        // an unverified fixture would make them pass or fail for the wrong
        // reason. ResidentEmailVerificationTest owns the verification gate.
        $resident->markPhoneAsVerified();

        return $resident->fresh();
    }

    /**
     * Completes both halves of resident login and returns the final response
     * — the one carrying the token — so callers assert against it exactly as
     * they would have against the old one-step /resident/login. Tests in this
     * file are about `status`, which is decided before the MFA challenge is
     * even issued, so this just gets them past the code prompt.
     */
    private function login(Resident $resident): TestResponse
    {
        $first = $this->postJson('/api/resident/login', [
            'phone_number' => $resident->phone_number,
            'password' => 'password123',
        ]);

        if ($first->status() !== 403 || $first->json('code') !== 'mfa_required') {
            return $first;
        }

        preg_match('/[0-9]{6}/', Http::recorded()->last()[0]['message'] ?? '', $match);

        return $this->postJson('/api/resident/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $match[0] ?? '',
        ]);
    }

    public function test_a_deactivated_resident_is_refused(): void
    {
        $resident = $this->resident('Deactivated');

        $this->login($resident)
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_deactivated');
    }

    /**
     * The column is an unconstrained varchar and the admin CRUD is not its only
     * possible writer — a database edit can store any casing. The refusal folds
     * case for the same reason User::isDeactivated() does.
     */
    public function test_a_deactivated_resident_is_refused_whatever_the_casing(): void
    {
        foreach (['deactivated', 'DEACTIVATED', 'DeActivated'] as $status) {
            $resident = $this->resident($status);

            $this->login($resident)
                ->assertStatus(403)
                ->assertJsonPath('code', 'account_deactivated');
        }
    }

    /**
     * Unchanged by the 2026-09-03 reversal, and now the guard against it being
     * over-applied: a self-registered account waiting for an admin is the one
     * that most needs to get in, not the one to lock out.
     */
    public function test_an_inactive_resident_can_still_sign_in(): void
    {
        $resident = $this->resident('Inactive');

        $this->login($resident)
            ->assertOk()
            ->assertJsonPath('role', 'resident')
            ->assertJsonStructure(['token']);
    }

    public function test_an_active_resident_can_sign_in(): void
    {
        $resident = $this->resident('Active');

        $this->login($resident)->assertOk()->assertJsonPath('role', 'resident');
    }

    /**
     * The case that makes a fail-closed check unsafe, and it is NOT the null
     * one — writing this test is what established that. `tbl_residents.status`
     * is NOT NULL, so a resident row can never have no status at all; the
     * nullable-status reasoning belongs to `tbl_user`, which is a different
     * column with a different default, and must not be carried across.
     *
     * What is true here is looser: the column is a plain varchar with no
     * default and no constraint, so nothing stops a row saying 'active' in the
     * wrong case or 'pending'. A rule of "must say Active" would lock every
     * such account out with no self-serve way back.
     */
    public function test_a_resident_whose_status_is_an_unrecognised_value_can_sign_in(): void
    {
        foreach (['active', 'Pending', 'unverified'] as $status) {
            $resident = $this->resident($status);

            $this->login($resident)
                ->assertOk()
                ->assertJsonPath('role', 'resident');
        }
    }

    /**
     * The gate that DOES exist, and the reason leaving login open is tolerable:
     * activation decides who a blast reaches, and that is enforced in the
     * recipient query, not at the door. Asserted here so the two halves of the
     * decision are read together — if this ever stops being true, the argument
     * in residentLogin's comment no longer holds.
     */
    public function test_activation_still_gates_who_a_blast_reaches(): void
    {
        $this->resident('Inactive');

        $recipients = Resident::where('status', 'Active')
            ->where('sms_opt_in', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->count();

        $this->assertSame(0, $recipients, 'An Inactive resident must not be a blast recipient.');
    }

    /**
     * The contrast, asserted rather than described: an admin account IS
     * refused. Keeps the asymmetry visible as a choice.
     */
    public function test_a_deactivated_admin_is_still_refused(): void
    {
        $admin = User::create([
            'first_name' => 'Former',
            'last_name' => 'Staffer',
            'email_address' => 'former@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
            'status' => 'Inactive',
        ]);

        $this->postJson('/api/admin/login', [
            'username' => $admin->username,
            'password' => 'password123',
        ])->assertForbidden();
    }
}
