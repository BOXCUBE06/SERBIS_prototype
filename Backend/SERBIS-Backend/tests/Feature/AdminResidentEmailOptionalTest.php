<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin's resident form no longer asks for an email — a phone number is the
 * login — but the column keeps what residents gave before, so the form must be
 * able to create an account without one and to edit an account without wiping
 * the address it already holds.
 */
class AdminResidentEmailOptionalTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        Sanctum::actingAs(User::create([
            'first_name' => 'MDRRMO', 'last_name' => 'Admin', 'email_address' => 'admin@serbis.com',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active',
        ]));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Rosa', 'last_name' => 'Hall',
            'phone_number' => '09175550000',
            'password' => 'Password123',
            'status' => 'Active',
        ], $overrides);
    }

    public function test_an_account_can_be_created_with_no_email(): void
    {
        $this->postJson('/api/residents', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('phone_number', '+639175550000')
            ->assertJsonPath('email_address', null);

        $this->assertNull(Resident::firstOrFail()->email_address);
    }

    public function test_an_email_that_is_given_must_still_be_valid_and_unique(): void
    {
        $this->postJson('/api/residents', $this->payload(['email_address' => 'not-an-email']))
            ->assertStatus(422)->assertJsonValidationErrors('email_address');

        $this->postJson('/api/residents', $this->payload(['email_address' => 'rosa@test.local']))->assertStatus(201);
        $this->postJson('/api/residents', $this->payload(['phone_number' => '09175550001', 'email_address' => 'rosa@test.local']))
            ->assertStatus(422)->assertJsonValidationErrors('email_address');
    }

    public function test_editing_without_the_email_key_leaves_the_stored_address_alone(): void
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id, 'first_name' => 'Maria', 'last_name' => 'Santos',
            'phone_number' => '09171111111', 'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'), 'status' => 'Active',
        ]);

        $this->putJson("/api/residents/{$resident->resident_id}", [
            'first_name' => 'Maria Clara', 'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'barangay_id' => $this->barangay->barangay_id,
            'status' => 'Active',
        ])->assertOk();

        $fresh = $resident->fresh();
        $this->assertSame('Maria Clara', $fresh->first_name);
        $this->assertSame('maria@test.local', $fresh->email_address, 'An omitted key must not wipe the address.');
    }

    public function test_an_explicit_email_still_moves_it_and_an_explicit_null_clears_it(): void
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id, 'first_name' => 'Maria', 'last_name' => 'Santos',
            'phone_number' => '09171111111', 'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'), 'status' => 'Active',
        ]);

        $base = [
            'first_name' => 'Maria', 'last_name' => 'Santos', 'phone_number' => '09171111111',
            'barangay_id' => $this->barangay->barangay_id, 'status' => 'Active',
        ];

        $this->putJson("/api/residents/{$resident->resident_id}", $base + ['email_address' => 'new@test.local'])->assertOk();
        $this->assertSame('new@test.local', $resident->fresh()->email_address);

        $this->putJson("/api/residents/{$resident->resident_id}", $base + ['email_address' => null])->assertOk();
        $this->assertNull($resident->fresh()->email_address);
    }

    public function test_editing_keeps_the_number_when_it_is_resubmitted_in_another_spelling(): void
    {
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id, 'first_name' => 'Maria', 'last_name' => 'Santos',
            'phone_number' => '09171111111', 'password' => Hash::make('Password123'), 'status' => 'Active',
        ]);

        // The panel shows 09…; the server holds +63…. Saving what is on screen
        // must not collide with the account's own number.
        $this->putJson("/api/residents/{$resident->resident_id}", [
            'first_name' => 'Maria', 'last_name' => 'Santos', 'phone_number' => '09171111111',
            'barangay_id' => $this->barangay->barangay_id, 'status' => 'Active',
        ])->assertOk()->assertJsonPath('phone_number', '+639171111111');
    }
}
