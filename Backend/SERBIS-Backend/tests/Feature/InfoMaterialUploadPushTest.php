<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\InfoMaterial;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Residents are pushed once when a material is uploaded; there is no verify step any more. */
class InfoMaterialUploadPushTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.uploads.public'));

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);

        // Same trick as the other push tests: prime the token cache rather than
        // fake google/auth's own client, which Http::fake() cannot see.
        Cache::put('fcm_access_token', 'fake-access-token', 3000);
        $path = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($path, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $path]);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);
    }

    private function resident(string $token): Resident
    {
        $resident = Resident::create([
            'barangay_id' => Barangay::create(['barangay_name' => 'Brgy '.$token])->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '0917'.substr(md5($token), 0, 7),
            'email_address' => $token.'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        DeviceToken::create(['resident_id' => $resident->getKey(), 'token' => $token, 'platform' => 'android', 'last_seen_at' => now()]);

        return $resident;
    }

    private function upload(string $title = 'Flood evacuation map')
    {
        return $this->actingAs($this->admin)->post('/api/admin/info-materials', [
            'title' => $title,
            'file' => UploadedFile::fake()->create('map.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }

    public function test_creating_a_material_pushes_the_registered_device_once(): void
    {
        $this->resident('device-1');

        $this->upload()->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-1'
            && str_contains($sent['message']['notification']['body'], 'Flood evacuation map is now available')
            && $sent['message']['data']['material_type'] === 'info_material');
    }

    public function test_a_rejected_upload_and_a_delete_send_no_push(): void
    {
        $this->resident('device-2');

        $this->actingAs($this->admin)->postJson('/api/admin/info-materials', ['title' => 'No file'])
            ->assertStatus(422);

        $material = InfoMaterial::create([
            'uploader_id' => $this->admin->admin_id,
            'title' => 'Old map',
            'file_path' => 'info_materials/old.pdf',
            'file_type' => 'pdf',
            'file_size' => 1,
        ]);
        $this->actingAs($this->admin)->deleteJson("/api/admin/info-materials/{$material->files_id}")->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_list_still_returns_verified_as_a_boolean_for_old_app_builds(): void
    {
        $this->upload()->assertCreated();

        $this->actingAs($this->admin)->getJson('/api/admin/info-materials')
            ->assertOk()
            ->assertJsonPath('0.verified', false);
    }
}
