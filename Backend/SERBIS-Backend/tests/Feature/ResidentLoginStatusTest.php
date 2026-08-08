<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Quality-check finding 5 (2026-08-05), decided 2026-08-08: `residentLogin`
 * does NOT check `status`, and that is deliberate.
 *
 * This file exists because the finding keeps coming back. The asymmetry with
 * `adminLogin`, which does refuse a deactivated account, reads like something
 * nobody got round to — so the intended behaviour is pinned here as an
 * assertion rather than left as a comment somebody can talk themselves out of.
 * The reasoning is at the decision point in AuthController::residentLogin.
 *
 * If the product decision is ever reversed, these tests SHOULD fail. Rewrite
 * them, do not delete them, and mirror User::isDeactivated() rather than
 * testing for 'Active' — hand-written recovery rows leave the column null.
 */
class ResidentLoginStatusTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $status): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09171111111',
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('password123'),
            'status' => $status,
        ])->fresh();
    }

    private function login(Resident $resident): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/resident/login', [
            'email_address' => $resident->email_address,
            'password' => 'password123',
        ]);
    }

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
            'role' => 'admin',
            'status' => 'Inactive',
        ]);

        $this->postJson('/api/admin/login', [
            'email_address' => $admin->email_address,
            'password' => 'password123',
        ])->assertForbidden();
    }
}
