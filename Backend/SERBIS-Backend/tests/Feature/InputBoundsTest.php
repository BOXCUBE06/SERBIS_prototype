<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ceilings on the numeric and date inputs that had none.
 *
 * Companion to ValidationLengthLimitsTest, which covers the string half. The
 * failure mode here is different and worse: a string past its column blows up
 * loudly at the database, while an unbounded number or date is accepted and
 * stored, and is only wrong later — a four-billion-kilometre odometer prints on
 * the conduction form, and a booking centuries out holds an ambulance in the
 * availability calendar forever.
 *
 * Every assertion is 422 on the value just past the limit AND 200 on the value
 * just under it. A rule tightened too far is as much a bug as one missing.
 */
class InputBoundsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    private Resident $resident;

    private Service $ambulance;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.uploads.private'));
        // store() texts nothing, but approve()/reschedule() notify over PhilSMS,
        // which has no sandbox — an escaped request is a billed real send.
        Http::preventStrayRequests();
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171234567',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->ambulance = Service::create(['service_name' => 'Ambulance/Medical Response']);
    }

    private function trip(): ConductionRequest
    {
        return ConductionRequest::create([
            'patient_name' => 'Maria Santos',
            'patient_address' => 'San Fabian',
            'patient_contact_number' => '09171234567',
            'medical_diagnosis' => 'Chest pain',
            'origin' => 'San Fabian',
            'destination' => 'Echague District Hospital',
        ]);
    }

    /** The ambulance intake payload, minus whatever a test is probing. */
    private function intake(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->ambulance->service_id,
            'patient_name' => 'Maria Santos',
            'destination' => 'Echague District Hospital',
            // create(), not image(): image() needs the GD extension, which is
            // not installed on either dev box. Same pattern as
            // ResidentAmbulanceIntakeTest.
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ], $overrides);
    }

    // ---- odometer -----------------------------------------------------------

    public function test_an_odometer_reading_past_a_million_is_rejected(): void
    {
        $trip = $this->trip();

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'odometer_start' => 1000001,
        ])->assertStatus(422)->assertJsonValidationErrors('odometer_start');

        // The digit-too-many case this exists for: 123456 mistyped as 1234567.
        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'odometer_start' => 1234567,
        ])->assertStatus(422)->assertJsonValidationErrors('odometer_start');
    }

    public function test_a_six_figure_odometer_reading_is_still_accepted(): void
    {
        $trip = $this->trip();

        Sanctum::actingAs($this->admin);

        // What a six-digit dashboard shows just before it rolls over. Rejecting
        // this would make the rule wrong in the other direction.
        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'odometer_start' => 999999,
            'odometer_end' => 1000000,
        ])->assertOk();

        $this->assertSame(999999, $trip->fresh()->odometer_start);
    }

    // ---- people arrays ------------------------------------------------------

    public function test_a_trip_cannot_be_filed_with_more_than_twenty_names_in_a_role(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/conduction-requests', [
            'patient_name' => 'Maria Santos',
            'patient_address' => 'San Fabian',
            'patient_contact_number' => '09171234567',
            'medical_diagnosis' => 'Chest pain',
            'origin' => 'San Fabian',
            'destination' => 'Echague District Hospital',
            'drivers' => array_fill(0, 21, 'Driver'),
        ])->assertStatus(422)->assertJsonValidationErrors('drivers');
    }

    public function test_the_trip_log_cannot_push_a_role_past_twenty_names(): void
    {
        $trip = $this->trip();

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'patient_relatives' => array_fill(0, 21, 'Relative'),
        ])->assertStatus(422)->assertJsonValidationErrors('patient_relatives');
    }

    public function test_twenty_names_in_a_role_is_still_accepted(): void
    {
        $trip = $this->trip();

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/conduction-requests/{$trip->conduction_request_id}/trip-log", [
            'drivers' => array_fill(0, 20, 'Driver'),
        ])->assertOk();

        $this->assertSame(20, $trip->fresh()->people()->where('role', 'driver')->count());
    }

    public function test_a_resident_cannot_file_more_than_twenty_relatives(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/service-requests', $this->intake([
            'patient_relatives' => array_fill(0, 21, 'Relative'),
        ]))->assertStatus(422)->assertJsonValidationErrors('patient_relatives');
    }

    // ---- patient age --------------------------------------------------------

    public function test_an_age_over_one_hundred_and_twenty_is_rejected(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/service-requests', $this->intake(['patient_age' => 150]))
            ->assertStatus(422)->assertJsonValidationErrors('patient_age');
    }

    public function test_a_newborn_is_still_accepted(): void
    {
        Sanctum::actingAs($this->resident);

        // min:0 is deliberate — a neonate transport is a real ambulance case
        // and 0 is the honest reading, not a missing value.
        $this->postJson('/api/service-requests', $this->intake(['patient_age' => 0]))
            ->assertStatus(201);
    }

    public function test_the_oldest_plausible_patient_is_still_accepted(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/service-requests', $this->intake(['patient_age' => 120]))
            ->assertStatus(201);
    }

    // ---- booking horizon ----------------------------------------------------

    public function test_a_booking_more_than_a_year_out_is_rejected(): void
    {
        // A free ambulance has to exist, or this passes for the wrong reason:
        // with no unit in the fleet the availability check inside store() also
        // answers 422 keyed on `scheduled_at`, so the assertion holds whether
        // or not the horizon rule is there at all. With a unit free, the only
        // thing that can reject this date is the horizon.
        Vehicle::create([
            'unit_identifier' => 'AMB-001',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        Sanctum::actingAs($this->resident);

        $this->postJson('/api/service-requests', $this->intake([
            'scheduled_at' => now()->addYears(5)->format('Y-m-d H:i:s'),
        ]))->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }

    public function test_a_booking_inside_the_horizon_is_still_accepted(): void
    {
        Vehicle::create([
            'unit_identifier' => 'AMB-001',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        Sanctum::actingAs($this->resident);

        $this->postJson('/api/service-requests', $this->intake([
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]))->assertStatus(201);
    }

    /**
     * reschedule() takes its own dates and never passes through
     * resolveScheduledAt(), so the horizon has to be on its rules too — it is
     * the one endpoint whose whole job is moving a booking's date.
     */
    public function test_a_booking_cannot_be_rescheduled_past_the_horizon(): void
    {
        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-001',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $booking = ServiceRequest::create([
            'resident_id' => $this->resident->resident_id,
            'service_id' => $this->ambulance->service_id,
            'description' => 'Booked',
            'status' => 'Booked',
            'vehicle_id' => $vehicle->vehicle_id,
            'scheduled_at' => now()->addDays(2),
            'scheduled_end' => now()->addDays(2)->addHours(2),
        ]);

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/service-requests/{$booking->request_id}/reschedule", [
            'scheduled_at' => now()->addYears(5)->format('Y-m-d H:i:s'),
            'scheduled_end' => now()->addYears(5)->addHours(2)->format('Y-m-d H:i:s'),
            'remarks' => 'Moved',
        ])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }

    // ---- borrowing due date -------------------------------------------------

    private function pendingBorrowing(): EquipmentBorrowing
    {
        $equipment = Equipment::create([
            'item_name' => 'Generator',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);

        return EquipmentBorrowing::create([
            'resident_id' => $this->resident->resident_id,
            'equipment_id' => $equipment->equipment_id,
            'quantity' => 1,
            'purpose' => 'Power outage',
            'status' => 'Pending',
        ]);
    }

    public function test_a_due_date_in_the_past_is_rejected(): void
    {
        $borrowing = $this->pendingBorrowing();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", [
            'status' => 'Approved',
            'due_date' => now()->subMonth()->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors('due_date');
    }

    public function test_a_due_date_more_than_a_year_out_is_rejected(): void
    {
        $borrowing = $this->pendingBorrowing();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", [
            'status' => 'Approved',
            'due_date' => now()->addYears(3)->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors('due_date');
    }

    public function test_the_panels_default_loan_length_is_still_accepted(): void
    {
        $borrowing = $this->pendingBorrowing();

        Sanctum::actingAs($this->admin);

        // DEFAULT_LOAN_DAYS in EquipmentBorrowingView.vue. If this ever fails,
        // the rule has been tightened past what the panel actually sends.
        $this->putJson("/api/borrowings/{$borrowing->getKey()}", [
            'status' => 'Approved',
            'due_date' => now()->addDays(7)->format('Y-m-d'),
        ])->assertOk();
    }

    /**
     * The case the lower bound must not break. An item is overdue precisely
     * because its due date is in the past, and closing it out has to keep
     * working — the panel sends the status alone for Returned, never the date.
     */
    public function test_an_overdue_borrowing_can_still_be_returned(): void
    {
        $borrowing = $this->pendingBorrowing();
        $borrowing->forceFill([
            'status' => 'Released',
            'due_date' => now()->subWeeks(2),
        ])->save();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Returned'])
            ->assertOk();

        $this->assertSame('Returned', $borrowing->fresh()->status);
    }
}
