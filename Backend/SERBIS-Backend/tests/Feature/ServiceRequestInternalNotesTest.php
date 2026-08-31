<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * internal_notes is the operator-only column split off `remarks` (see its
 * migration): `remarks` is what a resident is told, internal_notes never
 * leaves the admin panel. index() and show() serve both audiences from the
 * same method, so the exclusion is asserted here rather than assumed from
 * the controller reading correctly — this is exactly the class of bug
 * ServiceRequestAdminScopeTest documents: a shared method with an
 * unexercised branch.
 */
class ServiceRequestInternalNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Resident $resident;
    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
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

        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $service->service_id,
            'description' => 'Transfer to another hospital',
            'status' => 'Responding',
            'internal_notes' => 'Verified in person -- no vehicle at home.',
        ]);
    }

    public function test_resident_index_does_not_expose_internal_notes(): void
    {
        Sanctum::actingAs($this->resident);

        $rows = $this->getJson('/api/service-requests')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('internal_notes', $rows[0]);
    }

    public function test_resident_show_does_not_expose_internal_notes(): void
    {
        Sanctum::actingAs($this->resident);

        $row = $this->getJson("/api/service-requests/{$this->request->getKey()}")
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('internal_notes', $row);
    }

    public function test_admin_index_does_expose_internal_notes(): void
    {
        Sanctum::actingAs($this->admin);

        $rows = $this->getJson('/api/service-requests')->assertOk()->json();

        $this->assertArrayHasKey('internal_notes', $rows[0]);
        $this->assertSame('Verified in person -- no vehicle at home.', $rows[0]['internal_notes']);
    }

    public function test_admin_show_does_expose_internal_notes(): void
    {
        Sanctum::actingAs($this->admin);

        $row = $this->getJson("/api/service-requests/{$this->request->getKey()}")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('internal_notes', $row);
        $this->assertSame('Verified in person -- no vehicle at home.', $row['internal_notes']);
    }

    public function test_admin_can_save_a_note_without_touching_status_or_vehicle(): void
    {
        $this->actingAs($this->admin);

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'internal_notes' => 'Family confirmed transfer to St. Luke\'s.',
        ])->assertOk();

        $fresh = $this->request->fresh();
        $this->assertSame('Family confirmed transfer to St. Luke\'s.', $fresh->internal_notes);
        $this->assertSame('Responding', $fresh->status);
    }
}
