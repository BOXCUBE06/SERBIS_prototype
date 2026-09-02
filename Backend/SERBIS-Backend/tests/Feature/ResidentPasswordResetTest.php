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
 * Setting a resident's password from the panel has to end their sessions.
 *
 * PUT /api/residents/{id} is the only password reset a resident has — there is
 * no self-serve flow, and MAIL_MAILER is 'log' in production so a reset link
 * would reach nobody. It is therefore the whole of the office's response to a
 * resident reporting a compromised account.
 *
 * It used to write the new hash and stop there. Resident tokens live 30 days
 * (SANCTUM_RESIDENT_EXPIRATION = 43200 minutes), so a token already taken from
 * the account kept working for a month afterwards: the panel reported the reset
 * as done and the attacker never noticed. AdminController::update() has revoked
 * on the staff side since audit #30; this is the same guarantee for residents.
 */
class ResidentPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Barangay $barangay;
    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        // 'Admin', not 'admin' — AdminController and the seeders write the
        // capitalised value, and a lowercase fixture makes the guard behave
        // differently under test than in production.
        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171234567',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        Sanctum::actingAs($this->admin);
    }

    /** The full payload update() requires; individual tests override what they are testing. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171234567',
            'email_address' => 'maria@test.local',
            'barangay_id' => $this->barangay->barangay_id,
            'status' => 'Active',
        ], $overrides);
    }

    public function test_setting_a_residents_password_ends_their_sessions(): void
    {
        $this->resident->createToken('resident-token');
        $this->resident->createToken('other-device');
        $this->assertSame(2, $this->resident->tokens()->count());

        $this->putJson("/api/residents/{$this->resident->resident_id}", $this->payload([
            'password' => 'Newpassword123',
        ]))->assertStatus(200);

        $this->assertTrue(Hash::check('Newpassword123', $this->resident->fresh()->password));

        // The point of the reset. A token taken from the account beforehand
        // would otherwise stay usable for the rest of its 30 days.
        $this->assertSame(0, $this->resident->tokens()->count());
    }

    /**
     * A revoked token must actually stop authenticating, not merely disappear
     * from a count — the row is what Sanctum resolves on every request, so this
     * is the assertion that proves the session is really over.
     */
    public function test_a_token_taken_before_the_reset_no_longer_authenticates(): void
    {
        $stolen = $this->resident->createToken('stolen')->plainTextToken;

        $this->putJson("/api/residents/{$this->resident->resident_id}", $this->payload([
            'password' => 'Newpassword123',
        ]))->assertStatus(200);

        // Drop the acting-as guard state, or the request below is answered from
        // the admin session this test set up rather than from the bearer token.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$stolen)
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    /**
     * An edit that does not touch the password must leave the resident signed
     * in. The panel's status toggle and its edit form both PUT this route with
     * no password field, and logging every resident out on a barangay
     * correction would be its own outage.
     */
    public function test_an_edit_with_no_password_leaves_the_sessions_alone(): void
    {
        $this->resident->createToken('resident-token');

        $this->putJson("/api/residents/{$this->resident->resident_id}", $this->payload([
            'first_name' => 'Mariana',
        ]))->assertStatus(200);

        $this->assertSame('Mariana', $this->resident->fresh()->first_name);
        $this->assertTrue(Hash::check('Password123', $this->resident->fresh()->password));
        $this->assertSame(1, $this->resident->tokens()->count());
    }

    /**
     * A blank password is the same as an omitted one — the panel's edit form
     * sends the field either way — so it must not revoke anything either.
     */
    public function test_a_blank_password_leaves_the_sessions_alone(): void
    {
        $this->resident->createToken('resident-token');

        $this->putJson("/api/residents/{$this->resident->resident_id}", $this->payload([
            'password' => '',
        ]))->assertStatus(200);

        $this->assertTrue(Hash::check('Password123', $this->resident->fresh()->password));
        $this->assertSame(1, $this->resident->tokens()->count());
    }

    /** One resident's reset must not sign another resident out. */
    public function test_the_reset_only_touches_the_resident_being_edited(): void
    {
        $other = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'phone_number' => '09179999999',
            'email_address' => 'jose@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $other->createToken('bystander');
        $this->resident->createToken('resident-token');

        $this->putJson("/api/residents/{$this->resident->resident_id}", $this->payload([
            'password' => 'Newpassword123',
        ]))->assertStatus(200);

        $this->assertSame(0, $this->resident->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }
}
