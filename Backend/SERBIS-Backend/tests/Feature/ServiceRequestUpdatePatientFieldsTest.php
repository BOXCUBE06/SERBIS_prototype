<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} routing the 8 patient/intake fields to
 * AmbulanceBooking instead of writing them onto tbl_service_request —
 * correcting a typo in a patient's name after intake should not require a
 * specialised endpoint, and the split must not take that away.
 */
class ServiceRequestUpdatePatientFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $ambulance;

    private Service $roadClearing;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->roadClearing = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal.',
        ]);
    }

    public function test_a_patient_field_change_persists_and_reads_back(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Pending',
        ]);
        AmbulanceBooking::create(['request_id' => $request->getKey(), 'patient_name' => 'Juan Dela Cruz']);

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", ['patient_name' => 'Juana Dela Cruz'])
            ->assertOk()
            ->assertJsonPath('patient_name', 'Juana Dela Cruz');

        $this->assertSame(
            'Juana Dela Cruz',
            $this->actingAs($this->admin)
                ->getJson("/api/service-requests/{$request->getKey()}")
                ->assertOk()
                ->json('patient_name')
        );

        // Not written onto the parent — the booking row is the only place
        // this value lives now.
        $this->assertNull(
            DB::table('tbl_service_request')->where('request_id', $request->getKey())->value('patient_name')
        );
    }

    public function test_it_creates_a_booking_row_when_one_is_missing(): void
    {
        // A legacy or edge-case row: ambulance, but somehow no booking row
        // at all yet.
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Chest pains',
            'status' => 'Pending',
        ]);

        $this->assertNull($request->ambulanceBooking);

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", ['patient_name' => 'Juan Dela Cruz'])
            ->assertOk()
            ->assertJsonPath('patient_name', 'Juan Dela Cruz');

        $this->assertSame('Juan Dela Cruz', AmbulanceBooking::find($request->getKey())->patient_name);
    }

    public function test_a_patient_field_sent_for_a_non_ambulance_request_is_dropped(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->getKey(),
            'description' => 'Fallen tree.',
            'status' => 'Pending',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", ['patient_name' => 'Should not persist'])
            ->assertOk()
            ->assertJsonPath('patient_name', null);

        $this->assertSame(0, AmbulanceBooking::count());
    }

    public function test_updating_an_unrelated_field_leaves_existing_patient_fields_untouched(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Pending',
        ]);
        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'destination' => 'Echague District Hospital',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/service-requests/{$request->getKey()}", ['internal_notes' => 'Called back, confirmed address'])
            ->assertOk();

        $booking = AmbulanceBooking::find($request->getKey());
        $this->assertSame('Juan Dela Cruz', $booking->patient_name);
        $this->assertSame('Echague District Hospital', $booking->destination);
    }
}
