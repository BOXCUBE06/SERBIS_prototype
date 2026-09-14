<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The AmbulanceBooking model, its 1:1 relation to ServiceRequest, and the
 * factory wiring that keeps every ambulance ServiceRequest carrying exactly
 * one booking row. No controller reads or writes through any of this yet —
 * that starts at phase 4.
 */
class AmbulanceBookingModelTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Service $ambulance;

    private Service $roadClearing;

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

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->roadClearing = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal.',
        ]);
    }

    public function test_the_relation_reads_the_booking_row_keyed_by_request_id(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'destination' => 'Echague District Hospital',
        ]);

        $this->assertSame('Juan Dela Cruz', $request->fresh()->ambulanceBooking->patient_name);
        $this->assertSame('Echague District Hospital', $request->fresh()->ambulanceBooking->destination);
    }

    public function test_a_request_with_no_booking_row_has_a_null_relation(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->getKey(),
            'description' => 'Fallen tree.',
            'status' => 'Pending',
        ]);

        $this->assertNull($request->ambulanceBooking);
    }

    public function test_deleting_the_service_request_cascades_to_its_booking_row(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create(['request_id' => $request->getKey(), 'patient_name' => 'Juan Dela Cruz']);

        $request->delete();

        $this->assertSame(0, AmbulanceBooking::count());
    }

    public function test_booking_writes_are_tracked_under_their_own_auditable_type(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
        ]);

        $booking = AmbulanceBooking::create(['request_id' => $request->getKey(), 'patient_name' => 'Juan Dela Cruz']);

        $log = DB::table('tbl_system_logs')
            ->where('auditable_type', AmbulanceBooking::class)
            ->where('auditable_id', $booking->getKey())
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('created', $log->action_type);
    }

    public function test_the_factory_creates_a_matching_booking_row_for_an_ambulance_request(): void
    {
        $request = ServiceRequest::factory()->create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'vehicle_id' => null,
        ]);

        $booking = $request->fresh()->ambulanceBooking;

        $this->assertNotNull($booking);
        // The default factory state schedules a two-hour window, some day
        // in the next two weeks — asserted structurally, since the exact
        // instant is randomised and the parent's own copy is nulled once
        // the factory moves it onto the booking.
        $this->assertNotNull($booking->scheduled_at);
        $this->assertNotNull($booking->scheduled_end);
        $this->assertTrue($booking->scheduled_at->copy()->addHours(2)->equalTo($booking->scheduled_end));

        // Moved, not left behind.
        $this->assertNull($request->fresh()->scheduled_at);
        $this->assertNull($request->fresh()->scheduled_end);
    }

    public function test_the_factorys_unscheduled_state_still_creates_a_booking_row_with_null_fields(): void
    {
        $request = ServiceRequest::factory()->unscheduled()->create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
        ]);

        $booking = $request->fresh()->ambulanceBooking;

        $this->assertNotNull($booking);
        $this->assertNull($booking->scheduled_at);
        $this->assertNull($booking->scheduled_end);
    }

    public function test_the_factory_creates_no_booking_row_for_a_non_ambulance_request(): void
    {
        ServiceRequest::factory()->create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->getKey(),
            'description' => 'Fallen tree.',
        ]);

        $this->assertSame(0, AmbulanceBooking::count());
    }
}
