<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The mobile "Others" tile: a request that names nothing on the seeded
 * seven-service catalogue. Mirrors tbl_equipment_borrowing.equipment_id going
 * null for an uncatalogued item — service_id goes null here for the same
 * reason, and the resident's own words in `description` (already required
 * whenever service_id isn't the ambulance row) carry the rest. No new column:
 * unlike equipment there is no separate `other_service_text`, because
 * `description` already is that free-text home for every non-ambulance
 * service.
 */
class OthersServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

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

        // store() unconditionally resolves the ambulance service's id to build
        // its required_if/required_unless rules — unrelated to this feature,
        // but store() throws before validation runs at all if it is missing.
        Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Need help moving a fallen coconut tree off a footpath.',
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ], $overrides);
    }

    public function test_a_resident_can_file_without_naming_a_service(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload())
            ->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertNull($request->service_id);
        $this->assertSame('Need help moving a fallen coconut tree off a footpath.', $request->description);
    }

    public function test_a_description_is_still_required_with_no_service(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['description' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_a_real_service_id_still_works_unchanged(): void
    {
        $roadClearing = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal.',
        ]);

        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'service_id' => $roadClearing->getKey(),
            ]))
            ->assertStatus(201);

        $this->assertSame($roadClearing->getKey(), ServiceRequest::first()->service_id);
    }

    public function test_an_unknown_service_id_is_still_rejected(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['service_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }
}
