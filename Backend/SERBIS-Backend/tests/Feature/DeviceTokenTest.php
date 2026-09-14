<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** POST/DELETE /api/device-tokens — push notification device registration. */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
    }

    public function test_registering_a_token_creates_a_row(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/device-tokens', ['token' => 'fcm-token-1', 'platform' => 'android'])
            ->assertOk();

        $token = DeviceToken::where('token', 'fcm-token-1')->first();
        $this->assertNotNull($token);
        $this->assertSame($this->resident->getKey(), $token->resident_id);
        $this->assertSame('android', $token->platform);
        $this->assertNotNull($token->last_seen_at);
    }

    public function test_registering_the_same_token_again_refreshes_it_instead_of_duplicating(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/device-tokens', ['token' => 'fcm-token-1', 'platform' => 'android'])
            ->assertOk();

        $firstSeen = DeviceToken::where('token', 'fcm-token-1')->first()->last_seen_at;

        $this->travel(1)->minutes();

        $this->actingAs($this->resident)
            ->postJson('/api/device-tokens', ['token' => 'fcm-token-1', 'platform' => 'android'])
            ->assertOk();

        $this->assertSame(1, DeviceToken::where('token', 'fcm-token-1')->count());
        $this->assertTrue(DeviceToken::where('token', 'fcm-token-1')->first()->last_seen_at->gt($firstSeen));
    }

    /** A handset re-logged-in as someone else — the token now belongs to whoever is signed in on it. */
    public function test_registering_a_token_already_owned_by_another_resident_reassigns_it(): void
    {
        $other = Resident::create([
            'barangay_id' => $this->resident->barangay_id,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'juan@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        DeviceToken::create([
            'resident_id' => $other->getKey(),
            'token' => 'shared-device',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->resident)
            ->postJson('/api/device-tokens', ['token' => 'shared-device', 'platform' => 'android'])
            ->assertOk();

        $this->assertSame(1, DeviceToken::where('token', 'shared-device')->count());
        $this->assertSame($this->resident->getKey(), DeviceToken::where('token', 'shared-device')->first()->resident_id);
    }

    public function test_registering_without_a_token_is_refused(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/device-tokens', ['platform' => 'android'])
            ->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_an_admin_account_cannot_register_a_device_token(): void
    {
        $admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/device-tokens', ['token' => 'fcm-token-1', 'platform' => 'android'])
            ->assertStatus(403);
    }

    public function test_deleting_a_token_removes_it(): void
    {
        DeviceToken::create([
            'resident_id' => $this->resident->getKey(),
            'token' => 'fcm-token-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->resident)
            ->deleteJson('/api/device-tokens', ['token' => 'fcm-token-1'])
            ->assertOk();

        $this->assertNull(DeviceToken::where('token', 'fcm-token-1')->first());
    }

    public function test_deleting_another_residents_token_does_nothing(): void
    {
        $other = Resident::create([
            'barangay_id' => $this->resident->barangay_id,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'juan@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        DeviceToken::create([
            'resident_id' => $other->getKey(),
            'token' => 'not-yours',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->resident)
            ->deleteJson('/api/device-tokens', ['token' => 'not-yours'])
            ->assertOk();

        $this->assertNotNull(DeviceToken::where('token', 'not-yours')->first());
    }

    public function test_deleting_a_token_that_does_not_exist_is_a_no_op(): void
    {
        $this->actingAs($this->resident)
            ->deleteJson('/api/device-tokens', ['token' => 'never-registered'])
            ->assertOk();
    }

    public function test_deleting_a_residents_account_deletes_their_device_tokens(): void
    {
        DeviceToken::create([
            'resident_id' => $this->resident->getKey(),
            'token' => 'fcm-token-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $this->resident->delete();

        $this->assertNull(DeviceToken::where('token', 'fcm-token-1')->first());
    }
}
