<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/service-requests as a resident: `can_cancel` follows the same rule
 * cancel() enforces, `no_arrival_reason` comes off the latest trip, and rows
 * come back newest first.
 */
class ResidentRequestRowFlagsTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Service $service;

    private Vehicle $vehicle;

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
            'password' => bcrypt('Password123'),
            'status' => 'Active',
        ]);

        $this->service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);
    }

    private function request(string $status, ?Carbon $scheduledAt = null): ServiceRequest
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Hospital transfer',
            'status' => $status,
        ]);

        if ($scheduledAt) {
            AmbulanceBooking::create(['request_id' => $request->getKey(), 'scheduled_at' => $scheduledAt]);
        }

        return $request;
    }

    private function trip(ServiceRequest $request, array $attributes = []): ConductionRequest
    {
        return ConductionRequest::create([
            'service_request_id' => $request->request_id,
            'vehicle_id' => $this->vehicle->vehicle_id,
            'patient_name' => 'Maria Santos',
            'patient_address' => 'Barangay San Fabian, Echague, Isabela',
            'patient_contact_number' => '09171111111',
            'medical_diagnosis' => 'Hypertensive emergency',
            'origin' => 'MDRRMO Office, Echague',
            'destination' => 'Echague District Hospital',
            ...$attributes,
        ]);
    }

    private function row(ServiceRequest $request): array
    {
        $rows = $this->actingAs($this->resident)->getJson('/api/service-requests')->assertOk()->json();

        return collect($rows)->firstWhere('request_id', $request->getKey());
    }

    /** can_cancel must predict what the cancel endpoint actually does. */
    private function assertCanCancel(ServiceRequest $request, bool $expected): void
    {
        $this->assertSame($expected, $this->row($request)['can_cancel']);

        $this->actingAs($this->resident)
            ->patchJson("/api/service-requests/{$request->getKey()}/cancel")
            ->assertStatus($expected ? 200 : 422);
    }

    public function test_pending_can_be_cancelled(): void
    {
        $this->assertCanCancel($this->request('Pending'), true);
    }

    public function test_booked_outside_the_cutoff_can_be_cancelled(): void
    {
        $this->assertCanCancel($this->request('Booked', Carbon::now('UTC')->addDays(2)), true);
    }

    public function test_booked_inside_the_cutoff_cannot_be_cancelled(): void
    {
        $this->assertCanCancel($this->request('Booked', Carbon::now('UTC')->addMinutes(90)), false);
    }

    public function test_booked_with_a_departed_trip_cannot_be_cancelled(): void
    {
        $request = $this->request('Booked', Carbon::now('UTC')->addDays(2));
        $this->trip($request, ['departed_office_at' => Carbon::now('UTC')->subMinutes(5)]);

        $this->assertCanCancel($request, false);
    }

    public function test_responding_cannot_be_cancelled(): void
    {
        $this->assertCanCancel($this->request('Responding'), false);
    }

    public function test_no_arrival_reason_comes_from_the_trip(): void
    {
        $request = $this->request('Resolved');
        $this->trip($request, ['no_arrival_reason' => 'Patient already taken by family']);

        $row = $this->row($request);

        $this->assertSame('Patient already taken by family', $row['no_arrival_reason']);
        $this->assertArrayNotHasKey('conduction_requests', $row);
        $this->assertArrayNotHasKey('ambulance_booking', $row);
    }

    public function test_no_arrival_reason_is_null_for_an_arrived_trip_or_none(): void
    {
        $arrived = $this->request('Resolved');
        $this->trip($arrived, ['arrived_destination_at' => Carbon::now('UTC')->subHour()]);

        $this->assertNull($this->row($arrived)['no_arrival_reason']);
        $this->assertNull($this->row($this->request('Resolved'))['no_arrival_reason']);
    }

    public function test_rows_come_back_newest_first(): void
    {
        $older = $this->request('Pending');
        $older->forceFill(['created_at' => Carbon::now('UTC')->subDays(3)])->save();
        $newer = $this->request('Pending');

        $ids = collect($this->actingAs($this->resident)->getJson('/api/service-requests')->json())->pluck('request_id');

        $this->assertSame([$newer->getKey(), $older->getKey()], $ids->all());
    }
}
