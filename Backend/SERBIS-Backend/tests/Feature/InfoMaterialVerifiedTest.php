<?php

namespace Tests\Feature;

use App\Models\InfoMaterial;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", ['verified' => true])
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertTrue($material->fresh()->verified);

        // The mark has to be reversible — a toggle that only switches on is a
        // mistaken click nobody can take back.
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/info-materials/{$material->files_id}/verify", ['verified' => false])
            ->assertOk()
            ->assertJsonPath('verified', false);

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
            'barangay_id' => \App\Models\Barangay::create(['barangay_name' => 'San Fabian'])->barangay_id,
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
            'barangay_id' => \App\Models\Barangay::create(['barangay_name' => 'San Miguel'])->barangay_id,
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
}
