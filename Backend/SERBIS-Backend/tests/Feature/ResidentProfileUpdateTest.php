<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PATCH /me. The interesting assertions are the negative ones: this endpoint is
 * the first place a resident can write to their own row, so what it refuses
 * matters more than what it accepts.
 */
class ResidentProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $home;

    private Barangay $elsewhere;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->elsewhere = Barangay::create(['barangay_name' => 'San Miguel']);

        $this->resident = Resident::create([
            'barangay_id' => $this->home->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Inactive',
        ]);
    }

    public function test_a_resident_can_update_their_own_name(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Maria Clara',
        ])->assertOk()->assertJsonPath('user.first_name', 'Maria Clara');

        $this->resident->refresh();

        $this->assertSame('Maria Clara', $this->resident->first_name);
        // The number is the login and moves only through POST /me/phone.
        $this->assertSame('+639171111111', $this->resident->phone_number);
        // Untouched fields stay untouched — a PATCH is not a replace.
        $this->assertSame('Santos', $this->resident->last_name);
    }

    /**
     * A self-correctable detail (MDRRMO feedback, 2026-09-19) — no password
     * required, since it changes nothing about where a login code is sent.
     */
    public function test_a_resident_can_set_and_change_their_street_address(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'street_address' => 'Purok 3',
        ])->assertOk()->assertJsonPath('user.street_address', 'Purok 3');

        $this->resident->refresh();
        $this->assertSame('Purok 3', $this->resident->street_address);

        $this->actingAs($this->resident)->patchJson('/api/me', [
            'street_address' => 'Purok 7, near the covered court',
        ])->assertOk();

        $this->resident->refresh();
        $this->assertSame('Purok 7, near the covered court', $this->resident->street_address);
    }

    public function test_a_head_of_the_family_can_move_to_another_barangay(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'barangay_id' => $this->elsewhere->barangay_id,
        ])->assertOk()->assertJsonPath('user.barangay.barangay_name', 'San Miguel');

        $this->assertSame($this->elsewhere->barangay_id, $this->resident->refresh()->barangay_id);
    }

    public function test_an_unknown_barangay_is_refused(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'barangay_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('barangay_id');

        $this->assertSame($this->home->barangay_id, $this->resident->refresh()->barangay_id);
    }

    public function test_barangay_and_organization_accounts_cannot_move_themselves(): void
    {
        foreach ([Resident::TYPE_BARANGAY, Resident::TYPE_ORGANIZATION] as $type) {
            $this->resident->forceFill(['account_type' => $type])->save();

            $this->actingAs($this->resident)->patchJson('/api/me', [
                'barangay_id' => $this->elsewhere->barangay_id,
            ])->assertStatus(422)->assertJsonValidationErrors('barangay_id');

            $this->assertSame($this->home->barangay_id, $this->resident->refresh()->barangay_id, $type);
        }
    }

    public function test_status_role_photo_and_password_cannot_be_set_by_the_resident(): void
    {
        $originalPassword = $this->resident->password;

        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Maria',
            'status' => 'Active',
            'role' => 'admin',
            'photo' => 'https://evil.example.com/tracker.png',
            'password' => 'hunter2',
        ])->assertOk();

        $this->resident->refresh();

        // Inactive is what registration writes; only an admin activates.
        $this->assertSame('Inactive', $this->resident->status);
        $this->assertNull($this->resident->photo);
        $this->assertSame($originalPassword, $this->resident->password);
    }

    public function test_an_admin_cannot_use_the_resident_profile_endpoint(): void
    {
        $admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->patchJson('/api/me', ['first_name' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_it_requires_authentication(): void
    {
        $this->patchJson('/api/me', ['first_name' => 'Anonymous'])->assertStatus(401);
    }
}
