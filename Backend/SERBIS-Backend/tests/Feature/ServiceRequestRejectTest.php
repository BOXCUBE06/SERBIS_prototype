<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} rejecting a booking — extends update()
 * rather than a new route, since it only touches columns update() already
 * writes and syncFleet() already reconciles.
 */
class ServiceRequestRejectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
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

        $resident = Resident::create([
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
            'resident_id' => $resident->getKey(),
            'service_id' => $service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        $this->actingAs($this->admin);
    }

    public function test_rejecting_without_remarks_is_refused(): void
    {
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
        ])->assertStatus(422)->assertJsonValidationErrors('remarks');

        $this->assertSame('Booked', $this->request->fresh()->status);
    }

    public function test_rejecting_with_remarks_succeeds(): void
    {
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk()->assertJsonPath('status', 'Disapproved');

        $fresh = $this->request->fresh();
        $this->assertSame('Disapproved', $fresh->status);
        $this->assertSame('No unit free for the requested window.', $fresh->remarks);
    }
}
