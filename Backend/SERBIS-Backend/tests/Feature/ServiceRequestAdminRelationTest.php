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
 * A request row names the staff member who processed it, and the panel prints
 * their name. The relation used to carry the whole account (phone, email, role,
 * permissions), to other staff and, through index() and show(), to residents.
 */
class ServiceRequestAdminRelationTest extends TestCase
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
            'username' => 'mdrrmo.admin',
            'phone_number' => '+639171234567',
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
            'processed_by' => $this->admin->getKey(),
        ]);
    }

    private function assertNameOnly(array $admin): void
    {
        $this->assertEqualsCanonicalizing(['admin_id', 'first_name', 'last_name', 'username'], array_keys($admin));
        $this->assertSame('mdrrmo.admin', $admin['username']);
    }

    public function test_admin_endpoints_return_the_processing_staff_by_name_only(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->request->getKey();

        $this->assertNameOnly($this->getJson('/api/admin/service-requests')->assertOk()->json('data.0.admin'));
        $this->assertNameOnly($this->getJson('/api/service-requests')->assertOk()->json('0.admin'));
        $this->assertNameOnly($this->getJson("/api/service-requests/{$id}")->assertOk()->json('admin'));
    }

    public function test_resident_endpoints_do_not_return_the_staff_account(): void
    {
        Sanctum::actingAs($this->resident);
        $id = $this->request->getKey();

        $this->assertArrayNotHasKey('admin', $this->getJson('/api/service-requests')->assertOk()->json('0'));
        $this->assertArrayNotHasKey('admin', $this->getJson("/api/service-requests/{$id}")->assertOk()->json());
    }
}
