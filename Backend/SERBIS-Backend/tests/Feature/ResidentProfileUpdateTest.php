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

    public function test_a_resident_can_update_their_own_contact_details(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Maria Clara',
            'phone_number' => '09179999999',
            'email_address' => 'maria.clara@test.local',
            // Both contacts are where a login code lands, so moving either now
            // needs the password — see ResidentContactChangeTest.
            'current_password' => 'password123',
        ])->assertOk()->assertJsonPath('user.first_name', 'Maria Clara');

        $this->resident->refresh();

        $this->assertSame('Maria Clara', $this->resident->first_name);
        $this->assertSame('09179999999', $this->resident->phone_number);
        $this->assertSame('maria.clara@test.local', $this->resident->email_address);
        // Untouched fields stay untouched — a PATCH is not a replace.
        $this->assertSame('Santos', $this->resident->last_name);
    }

    public function test_barangay_status_role_and_photo_cannot_be_set_by_the_resident(): void
    {
        $originalPassword = $this->resident->password;

        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Maria',
            'barangay_id' => $this->elsewhere->barangay_id,
            'status' => 'Active',
            'role' => 'admin',
            'photo' => 'https://evil.example.com/tracker.png',
            'password' => 'hunter2',
        ])->assertOk();

        $this->resident->refresh();

        // barangay_id is the field every request is dispatched on: a resident who
        // could move themselves could redirect their own dispatch.
        $this->assertSame($this->home->barangay_id, $this->resident->barangay_id);
        // Inactive is what registration writes; only an admin activates.
        $this->assertSame('Inactive', $this->resident->status);
        $this->assertNull($this->resident->photo);
        $this->assertSame($originalPassword, $this->resident->password);
    }

    public function test_the_email_address_must_stay_unique_but_may_be_resubmitted_unchanged(): void
    {
        Resident::create([
            'barangay_id' => $this->home->barangay_id,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'juan@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->actingAs($this->resident)
            ->patchJson('/api/me', ['email_address' => 'juan@test.local'])
            ->assertStatus(422);

        // The unique rule ignores the caller's own row, or saving an unchanged
        // form would fail against itself.
        $this->actingAs($this->resident)
            ->patchJson('/api/me', ['email_address' => 'maria@test.local'])
            ->assertOk();
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
