<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PATCH /api/service-requests/{id}/approve — its own route, not update(),
 * because it re-checks ambulance availability under a lock that update() was
 * never built to take.
 */
class ServiceRequestApproveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Resident $resident;
    private Service $service;
    private Vehicle $amb01;
    private Vehicle $amb02;

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

        $this->service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->amb01 = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $this->amb02 = Vehicle::create([
            'unit_identifier' => 'AMB-02',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);
    }

    private function bookedRequest(?Carbon $scheduledAt = null): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
            'scheduled_at' => $scheduledAt ?? Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0),
        ]);
    }

    public function test_approving_assigns_the_unit_and_keeps_status_booked(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($target);

        $response = $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertOk();

        $response->assertJsonPath('status', 'Booked')
            ->assertJsonPath('vehicle_id', $this->amb01->vehicle_id);

        $fresh = $request->fresh();
        $this->assertSame($this->amb01->vehicle_id, $fresh->vehicle_id);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame($this->admin->getKey(), $fresh->processed_by);
        $this->assertTrue($fresh->scheduled_end->utc()->equalTo($target->copy()->addHours(2)));

        // Approval reserves the window, not the vehicle physically leaving —
        // it must stay Available until an actual dispatch.
        $this->assertSame('Available', $this->amb01->fresh()->status);
    }

    public function test_approving_accepts_a_staff_adjusted_scheduled_end(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($target);
        $customEnd = $target->copy()->addHours(3);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
            'scheduled_end' => $customEnd->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertTrue($request->fresh()->scheduled_end->utc()->equalTo($customEnd));
    }

    public function test_approving_refuses_a_maintenance_unit(): void
    {
        $this->amb01->update(['status' => 'Maintenance']);
        $request = $this->bookedRequest();

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);
    }

    public function test_approving_refuses_a_request_that_is_not_booked(): void
    {
        $request = $this->bookedRequest();
        $request->update(['status' => 'Pending']);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertStatus(422);
    }

    /** The window filled between submission and approval — the exact race this endpoint's lock exists for. */
    public function test_approving_into_a_window_that_filled_after_submission_is_refused(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($target);

        // Someone else got AMB-01 for the same window in the meantime.
        ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Another booking that beat this one to approval',
            'status' => 'Booked',
            'vehicle_id' => $this->amb01->vehicle_id,
            'scheduled_at' => $target->copy(),
            'scheduled_end' => $target->copy()->addHours(2),
        ]);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb01->vehicle_id,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);

        // AMB-02 was never touched — still assignable in a follow-up call.
        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $this->amb02->vehicle_id,
        ])->assertOk();
    }
}
