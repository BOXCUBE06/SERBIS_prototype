<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/ambulance-availability — the resident booking picker and the admin
 * calendar both read this, and neither may see who booked what. Every window
 * assertion below checks the response never carries patient_name,
 * medical_diagnosis, description, remarks, resident_id or walk_in_name — the
 * fields that would identify the booking behind a window.
 */
class AmbulanceAvailabilityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;
    private Vehicle $vehicle;
    private Resident $resident;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical transport',
        ]);

        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
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

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        // The row a leak would expose: a real patient name and diagnosis, on a
        // window that sits inside the ?date= day used below.
        ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'CONFIDENTIAL-PATIENT-NAME Juan Dela Cruz',
            'remarks' => 'CONFIDENTIAL-REMARKS',
            'status' => 'Booked',
            'vehicle_id' => $this->vehicle->getKey(),
            'scheduled_at' => '2026-09-01 00:00:00',
            'scheduled_end' => '2026-09-01 02:00:00',
        ]);
    }

    private function assertNoBookingDetailsLeaked(string $json): void
    {
        $this->assertStringNotContainsString('CONFIDENTIAL', $json);
        $this->assertStringNotContainsString('Juan Dela Cruz', $json);
        $this->assertStringNotContainsString('resident_id', $json);
        $this->assertStringNotContainsString('walk_in_name', $json);
        $this->assertStringNotContainsString('description', $json);
        $this->assertStringNotContainsString('remarks', $json);
    }

    public function test_a_resident_sees_windows_but_no_request_details(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->getJson('/api/ambulance-availability?date=2026-09-01')->assertOk();

        $this->assertNoBookingDetailsLeaked($response->getContent());

        $units = $response->json();
        $this->assertCount(1, $units);
        $this->assertSame('AMB-01', $units[0]['unit_identifier']);
        $this->assertFalse($units[0]['is_maintenance']);
        $this->assertCount(1, $units[0]['booked_windows']);
        $this->assertArrayHasKey('scheduled_at', $units[0]['booked_windows'][0]);
        $this->assertArrayHasKey('scheduled_end', $units[0]['booked_windows'][0]);
    }

    public function test_an_admin_sees_the_same_shape(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/ambulance-availability?date=2026-09-01')->assertOk();

        $this->assertNoBookingDetailsLeaked($response->getContent());

        $units = $response->json();
        $this->assertCount(1, $units);
        $this->assertSame('AMB-01', $units[0]['unit_identifier']);
        $this->assertCount(1, $units[0]['booked_windows']);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/ambulance-availability?date=2026-09-01')->assertStatus(401);
    }

    public function test_window_form_returns_free_units_without_booking_details(): void
    {
        Sanctum::actingAs($this->resident);

        $secondVehicle = Vehicle::create([
            'unit_identifier' => 'AMB-02',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        // Manila 08:30-09:30 on 2026-09-01 is UTC 00:30-01:30 the same day —
        // inside the booked UTC window (00:00-02:00), proving the Manila
        // parse actually shifted these before the overlap check ran.
        $response = $this->getJson(
            '/api/ambulance-availability?start=2026-09-01T08:30:00&end=2026-09-01T09:30:00'
        )->assertOk();

        $this->assertNoBookingDetailsLeaked($response->getContent());

        $identifiers = collect($response->json())->pluck('unit_identifier')->all();
        $this->assertSame(['AMB-02'], $identifiers);
    }

    public function test_neither_date_nor_window_is_rejected(): void
    {
        Sanctum::actingAs($this->resident);

        $this->getJson('/api/ambulance-availability')->assertStatus(422);
    }
}
