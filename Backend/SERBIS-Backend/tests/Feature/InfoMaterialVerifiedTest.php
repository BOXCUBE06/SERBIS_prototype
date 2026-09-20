<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\InfoMaterial;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InfoMaterialVerifiedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);
    }

    private function material(array $overrides = []): InfoMaterial
    {
        return InfoMaterial::create(array_merge([
            'uploader_id' => $this->admin->admin_id,
            'title' => 'Flood evacuation map',
            'file_path' => 'info_materials/flood-map.pdf',
            'file_type' => 'pdf',
            'file_size' => 2048,
        ], $overrides));
    }

    public function test_a_material_is_not_verified_until_someone_verifies_it(): void
    {
        $material = $this->material();

        $this->assertFalse($material->fresh()->verified);
    }

    public function test_an_admin_can_verify_and_unverify_a_material(): void
    {
        $material = $this->material();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", [
                'verified' => true,
                'verified_by_name' => 'Dr. Ana Reyes',
                'verified_by_role' => 'MDRRMO Medical Officer',
            ])
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('verified_by_name', 'Dr. Ana Reyes')
            ->assertJsonPath('verified_by_role', 'MDRRMO Medical Officer');

        $fresh = $material->fresh();
        $this->assertTrue($fresh->verified);
        $this->assertNotNull($fresh->verified_at);

        // The mark has to be reversible — a toggle that only switches on is a
        // mistaken click nobody can take back. Taking it back clears who and
        // when: an unverified row must not still name an expert for a check
        // that no longer stands.
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", ['verified' => false])
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('verified_by_name', null)
            ->assertJsonPath('verified_by_role', null);

        $fresh = $material->fresh();
        $this->assertFalse($fresh->verified);
        $this->assertNull($fresh->verified_by_name);
        $this->assertNull($fresh->verified_by_role);
        $this->assertNull($fresh->verified_at);
    }

    public function test_verifying_without_a_name_and_role_is_rejected(): void
    {
        $material = $this->material();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", ['verified' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['verified_by_name', 'verified_by_role']);

        $this->assertFalse($material->fresh()->verified);
    }

    /** Unverifying needs neither field — they exist to name who verified, not who is taking it back. */
    public function test_unverifying_needs_no_name_or_role(): void
    {
        $material = $this->material([
            'verified' => true,
            'verified_by_name' => 'Dr. Ana Reyes',
            'verified_by_role' => 'MDRRMO Medical Officer',
            'verified_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", ['verified' => false])
            ->assertOk();

        $this->assertFalse($material->fresh()->verified);
    }

    public function test_the_verified_flag_is_a_boolean_in_the_response_not_a_zero_or_one(): void
    {
        $this->material(['verified' => true]);
        $this->material(['verified' => false]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/info-materials')->assertOk();

        foreach ($response->json() as $row) {
            $this->assertIsBool($row['verified']);
        }
    }

    public function test_the_flag_reaches_the_endpoint_the_mobile_library_reads(): void
    {
        // Residents read GET /info-materials, which is the same index() the
        // panel calls. The flag rides along on that response.
        $this->material(['verified' => true]);

        $resident = Resident::create([
            'barangay_id' => Barangay::create(['barangay_name' => 'San Fabian'])->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09171111111',
            'email_address' => 'resident@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->actingAs($resident)->getJson('/api/info-materials')
            ->assertOk()
            ->assertJsonPath('0.verified', true);
    }

    public function test_a_resident_cannot_set_the_verified_flag(): void
    {
        $material = $this->material();

        $resident = Resident::create([
            'barangay_id' => Barangay::create(['barangay_name' => 'San Miguel'])->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09172222222',
            'email_address' => 'resident2@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->actingAs($resident)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", ['verified' => true])
            ->assertStatus(403);

        $this->assertFalse($material->fresh()->verified);
    }

    public function test_verifying_a_material_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/admin/info-materials/999999/verify', ['verified' => true])
            ->assertStatus(404);
    }

    public function test_the_verified_field_is_required(): void
    {
        $material = $this->material();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['verified']);
    }

    /**
     * Bypasses the real OAuth2 mint the same way ServiceRequestApproveTest
     * does: primes its cache key directly rather than faking google/auth's
     * own Guzzle client, which Http::fake() cannot see.
     */
    private function configureFcm(): void
    {
        Cache::put('fcm_access_token', 'fake-access-token', 3000);

        $path = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($path, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $path]);
    }

    private function resident(string $email): Resident
    {
        return Resident::create([
            'barangay_id' => Barangay::create(['barangay_name' => 'Brgy '.$email])->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '0917'.substr(md5($email), 0, 7),
            'email_address' => $email,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    public function test_verifying_pushes_every_registered_device_not_just_the_uploader(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        $material = $this->material(['title' => 'Flood evacuation map']);

        DeviceToken::create(['resident_id' => $this->resident('r1@test.local')->getKey(), 'token' => 'device-1', 'platform' => 'android', 'last_seen_at' => now()]);
        DeviceToken::create(['resident_id' => $this->resident('r2@test.local')->getKey(), 'token' => 'device-2', 'platform' => 'android', 'last_seen_at' => now()]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", [
                'verified' => true,
                'verified_by_name' => 'Dr. Ana Reyes',
                'verified_by_role' => 'MDRRMO Medical Officer',
            ])
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-1'
            && str_contains($sent['message']['notification']['body'], 'Flood evacuation map')
            && ! str_contains($sent['message']['notification']['body'], '!')
            && $sent['message']['data']['files_id'] === (string) $material->files_id);
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-2');
    }

    public function test_unverifying_sends_no_push(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        $material = $this->material([
            'verified' => true,
            'verified_by_name' => 'Dr. Ana Reyes',
            'verified_by_role' => 'MDRRMO Medical Officer',
            'verified_at' => now(),
        ]);
        DeviceToken::create(['resident_id' => $this->resident('r3@test.local')->getKey(), 'token' => 'device-3', 'platform' => 'android', 'last_seen_at' => now()]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", ['verified' => false])
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_re_verifying_an_already_verified_material_sends_no_second_push(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);

        $material = $this->material([
            'verified' => true,
            'verified_by_name' => 'Dr. Ana Reyes',
            'verified_by_role' => 'MDRRMO Medical Officer',
            'verified_at' => now(),
        ]);
        DeviceToken::create(['resident_id' => $this->resident('r4@test.local')->getKey(), 'token' => 'device-4', 'platform' => 'android', 'last_seen_at' => now()]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", [
                'verified' => true,
                'verified_by_name' => 'Dr. Ana Reyes',
                'verified_by_role' => 'MDRRMO Medical Officer',
            ])
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_push_failure_does_not_affect_verification(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response([
            'error' => ['status' => 'UNAVAILABLE', 'message' => 'Server is overloaded.'],
        ], 503)]);

        $material = $this->material();
        DeviceToken::create(['resident_id' => $this->resident('r5@test.local')->getKey(), 'token' => 'device-5', 'platform' => 'android', 'last_seen_at' => now()]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", [
                'verified' => true,
                'verified_by_name' => 'Dr. Ana Reyes',
                'verified_by_role' => 'MDRRMO Medical Officer',
            ])
            ->assertOk();

        $this->assertTrue($material->fresh()->verified);
    }
}
