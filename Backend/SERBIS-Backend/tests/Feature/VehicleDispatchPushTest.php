<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AssignsResponders;
use Tests\Concerns\FakesFcm;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * The Responding push is sent by VehicleDispatch, after the commit, and by
 * nothing else: update() skips its own generic push for a dispatch.
 */
class VehicleDispatchPushTest extends TestCase
{
    use AssignsResponders, FakesFcm, MakesAdmins, RefreshDatabase;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['fcm.googleapis.com/*' => $this->fcmAccepts()]);
        $this->configureFcm();

        $this->resident = Resident::create([
            'barangay_id' => Barangay::create(['barangay_name' => 'San Fabian'])->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '+639171111111',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        $this->deviceFor($this->resident);

        Sanctum::actingAs($this->makeStaff());
    }

    private function request(string $serviceName, string $status = 'Booked', ?int $vehicleId = null): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => Service::create(['service_name' => $serviceName, 'description' => 'x'])->service_id,
            'description' => 'Needs a unit',
            'status' => $status,
            'vehicle_id' => $vehicleId,
        ]);
    }

    public function test_a_unit_going_out_pushes_the_resident_once(): void
    {
        $unit = Vehicle::create(['unit_identifier' => 'RES-01', 'type' => 'Rescue Vehicle', 'status' => 'Available']);
        $request = $this->request('Road Clearing', 'Booked', $unit->getKey());
        $this->assignResponder($request);

        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Responding'])->assertOk();

        $this->assertSame('Responding', $request->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-1'
            && str_contains($sent['message']['notification']['body'], 'Road Clearing')
            && $sent['message']['data']['request_id'] === (string) $request->getKey());
    }

    public function test_an_approved_ambulance_dispatch_pushes_once_and_marks_the_unit_dispatched(): void
    {
        $unit = Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);
        $request = $this->request('Ambulance/Medical Response', 'Booked', $unit->getKey());
        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => Carbon::now('UTC')->addHour(),
            'approved_at' => now(),
        ]);
        $this->assignResponder($request);

        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Responding'])->assertOk();

        $this->assertSame('Dispatched', $unit->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_a_refused_dispatch_sends_nothing(): void
    {
        $unit = Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'status' => 'Available']);
        $request = $this->request('Ambulance/Medical Response', 'Booked', $unit->getKey());
        AmbulanceBooking::create(['request_id' => $request->getKey(), 'scheduled_at' => Carbon::now('UTC')->addHour()]);
        $this->assignResponder($request);

        // Never approved, so VehicleDispatch refuses before touching anything.
        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Responding'])->assertStatus(422);

        $this->assertSame('Booked', $request->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_unit_already_responding_elsewhere_is_a_409_and_sends_nothing(): void
    {
        $unit = Vehicle::create(['unit_identifier' => 'RES-01', 'type' => 'Rescue Vehicle', 'status' => 'Available']);
        $this->request('Relief', 'Responding', $unit->getKey());
        $second = $this->request('Road Clearing', 'Booked', $unit->getKey());
        $this->assignResponder($second);

        $this->putJson("/api/service-requests/{$second->getKey()}", ['status' => 'Responding'])->assertStatus(409);

        Http::assertNothingSent();
    }
}
