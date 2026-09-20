<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Deactivate account" in the admin panel, and what it actually does.
 *
 * Until 2026-09-03 it did almost nothing: `tbl_residents.status` gated SMS
 * blast inclusion and nothing else, so a deactivated resident stayed signed in
 * on their phone, could sign in again, and could keep filing service requests.
 * The button told staff the account was closed; the account was not closed.
 *
 * Three gates were added together and are asserted together here, because each
 * one alone leaves a way in:
 *
 *   1. residentLogin refuses  — no new session
 *   2. store()/adminStore() refuse — no new request from an existing session
 *   3. update() revokes tokens — no existing session either
 *
 * The login half of (1) also lives in ResidentLoginStatusTest, which owns the
 * "which statuses may sign in" question. This file owns the consequences.
 */
class ResidentDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        // SkySMS has no sandbox. Faked here and asserted against below: one of
        // these tests exists precisely to prove the deactivation refusal costs
        // no billed message.
        Http::preventStrayRequests();
        Http::fake([
            'skysms.skyio.site/*' => fn () => Http::response(['status' => 'success'], 200),
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $status = 'Active'): Resident
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('Password123'),
            'status' => $status,
        ]);

        $resident->markPhoneAsVerified();

        return $resident->fresh();
    }

    private function admin(): User
    {
        // 'Admin', not 'admin' — the casing AdminController and the seeders
        // write, and what User::isAdmin() compares against.
        return User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => uniqid('a', true).'@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
    }

    /** Service::booted() slugs `code` from the name, which is what store() looks up. */
    private function ambulanceService(): Service
    {
        return Service::create(['service_name' => 'Ambulance/Medical Response']);
    }

    // ---- Gate 2: filing ----------------------------------------------------

    public function test_a_deactivated_resident_cannot_file_a_request(): void
    {
        $service = $this->ambulanceService();

        Sanctum::actingAs($this->resident('Deactivated'));

        $this->postJson('/api/service-requests', [
            'service_id' => $service->service_id,
            'patient_name' => 'Juan Dela Cruz',
            'patient_relatives' => ['Lalaine Ferrer'],
            'destination' => 'Echague District Hospital',
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_deactivated');
    }

    /**
     * The regression guard that matters most on this change. 'Inactive' is a
     * self-registered account waiting for an admin, NOT a closed one — the
     * account most likely to be filing, and the one a careless `!== 'Active'`
     * check would have locked out.
     */
    public function test_an_inactive_resident_can_still_file_a_request(): void
    {
        $service = $this->ambulanceService();

        Sanctum::actingAs($this->resident('Inactive'));

        $this->postJson('/api/service-requests', [
            'service_id' => $service->service_id,
            'patient_name' => 'Juan Dela Cruz',
            'patient_relatives' => ['Lalaine Ferrer'],
            'destination' => 'Echague District Hospital',
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ])->assertSuccessful();
    }

    public function test_staff_cannot_file_a_counter_request_for_a_deactivated_resident(): void
    {
        $service = $this->ambulanceService();
        $resident = $this->resident('Deactivated');

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/service-requests', [
            'resident_id' => $resident->resident_id,
            'service_id' => $service->service_id,
            'patient_name' => 'Juan Dela Cruz',
            'patient_relatives' => ['Lalaine Ferrer'],
            'patient_address' => 'Purok 1, San Fabian',
            'pickup_location' => 'Purok 1, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Shortness of breath.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resident_id');
    }

    /**
     * The escape hatch, asserted so nobody "tidies it away" later. A
     * deactivated resident standing at the counter is still a person needing an
     * ambulance; staff file them as a walk-in, which carries no resident_id and
     * so never reaches the gate above.
     */
    public function test_staff_can_still_file_a_walk_in_for_the_same_person(): void
    {
        $service = $this->ambulanceService();
        $this->resident('Deactivated');

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Maria Santos',
            'walk_in_contact_number' => '09171111111',
            'service_id' => $service->service_id,
            'patient_name' => 'Maria Santos',
            'patient_relatives' => ['Lalaine Ferrer'],
            'patient_address' => 'Purok 1, San Fabian',
            'pickup_location' => 'Purok 1, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Shortness of breath.',
        ])->assertSuccessful();
    }

    // ---- Gate 3: existing sessions -----------------------------------------

    public function test_deactivating_from_the_panel_revokes_the_residents_tokens(): void
    {
        $resident = $this->resident('Active');
        $resident->createToken('phone');

        $this->assertSame(1, $resident->tokens()->count());

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/residents/{$resident->resident_id}", [
            'first_name' => $resident->first_name,
            'last_name' => $resident->last_name,
            'phone_number' => $resident->phone_number,
            'email_address' => $resident->email_address,
            'barangay_id' => $resident->barangay_id,
            'status' => 'Deactivated',
        ])->assertOk();

        $this->assertSame(
            0,
            $resident->fresh()->tokens()->count(),
            'Deactivating must end the session; a resident token otherwise lives 30 days.',
        );
    }

    /**
     * The other half of that rule. An ordinary edit — fixing a surname, moving
     * a barangay — must not sign the resident out of their phone.
     */
    public function test_an_ordinary_edit_leaves_the_residents_tokens_alone(): void
    {
        $resident = $this->resident('Active');
        $resident->createToken('phone');

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/residents/{$resident->resident_id}", [
            'first_name' => 'Mariah',
            'last_name' => $resident->last_name,
            'phone_number' => $resident->phone_number,
            'email_address' => $resident->email_address,
            'barangay_id' => $resident->barangay_id,
            'status' => 'Active',
        ])->assertOk();

        $this->assertSame(1, $resident->fresh()->tokens()->count());
    }

    // ---- Gate 1's cost -----------------------------------------------------

    /**
     * The refusal is placed above the unverified-email branch in residentLogin,
     * not below it, because that branch calls issueSignupCode(). SkySMS bills
     * every send and has no sandbox, so gating afterwards would let repeated
     * logins against a closed account run up a real bill. Asserted rather than
     * described — the placement is invisible in a diff read later.
     */
    public function test_a_refused_login_sends_no_billed_message(): void
    {
        $resident = $this->resident('Deactivated');

        $this->postJson('/api/resident/login', [
            'phone_number' => $resident->phone_number,
            'password' => 'Password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_deactivated');

        $this->assertCount(
            0,
            Http::recorded(),
            'A deactivated login must not reach SkySMS — every send is billed.',
        );
    }
}
