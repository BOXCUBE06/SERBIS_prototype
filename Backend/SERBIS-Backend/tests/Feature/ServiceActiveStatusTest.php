<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Services are fixed and their intake forms are hardcoded against
 * tbl_services.code, so deleting a row breaks things — disabling
 * (is_active) is the safe equivalent. This covers the whole feature: the
 * shared GET /api/services split by caller, the filing-time gate in
 * store()/adminStore(), and that a request already filed against a
 * service disabled afterward is left alone.
 */
class ServiceActiveStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Resident $resident;
    private Service $activeService;
    private Service $inactiveService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->activeService = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Clearing blocked roads.',
        ]);

        $this->inactiveService = Service::create([
            'service_name' => 'Sandbagging',
            'description' => 'Retired service.',
            'is_active' => false,
        ]);
    }

    public function test_a_new_service_defaults_active(): void
    {
        $this->assertTrue($this->activeService->fresh()->is_active);
    }

    public function test_admin_listing_includes_inactive_services(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonFragment(['service_name' => 'Sandbagging', 'is_active' => false]);
    }

    public function test_resident_listing_excludes_inactive_services(): void
    {
        $response = $this->actingAs($this->resident)
            ->getJson('/api/services')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('service_name');
        $this->assertTrue($names->contains('Road Clearing'));
        $this->assertFalse($names->contains('Sandbagging'));
    }

    public function test_admin_can_toggle_is_active(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/services/{$this->activeService->service_id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($this->activeService->fresh()->is_active);
    }

    public function test_resident_filing_against_an_inactive_service_is_refused(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', [
                'service_id' => $this->inactiveService->service_id,
                'description' => 'Please help.',
                'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');

        $this->assertDatabaseCount('tbl_service_request', 0);
    }

    public function test_staff_walk_in_against_an_inactive_service_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/service-requests', [
                'walk_in_name' => 'Rosario Bautista',
                'walk_in_contact_number' => '09181234567',
                'service_id' => $this->inactiveService->service_id,
                'description' => 'Filed at the counter.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');

        $this->assertDatabaseCount('tbl_service_request', 0);
    }

    public function test_filing_against_an_active_service_still_works(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', [
                'service_id' => $this->activeService->service_id,
                'description' => 'Fallen tree on the road.',
                'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(201);
    }

    /**
     * The gate is filing-time only. A request already on file keeps working
     * through the rest of its lifecycle even after its service is disabled
     * later — update() never re-checks is_active.
     */
    public function test_a_request_already_filed_survives_its_service_being_disabled_later(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->activeService->service_id,
            'description' => 'Fallen tree on the road.',
            'status' => 'Pending',
        ]);

        $this->activeService->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Responding'])
            ->assertOk();

        $this->assertSame('Responding', $request->fresh()->status);
    }
}
