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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The double-booking guard must hold across write paths, not just within
 * one: a unit committed by adminStore()+approve() must still be visible to
 * reschedule() on a completely different request. Both read the same
 * tbl_ambulance_bookings join (AmbulanceAvailability) — this is the test
 * that would catch either path reading a stale or wrong column.
 */
class CrossPathDoubleBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $ambulance;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
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

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        // Exactly one unit, deliberately — the second booking has nowhere
        // else to go once the first holds it.
        $this->vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);
    }

    private function manilaString(Carbon $instant): string
    {
        return $instant->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s');
    }

    public function test_a_booking_approved_via_adminStore_blocks_a_reschedule_into_its_window_on_another_request(): void
    {
        $windowStart = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $windowEnd = $windowStart->copy()->addHours(2);

        // Write path 1: staff walk-in, then approved — the unit is now
        // actually committed to [windowStart, windowEnd), not just booked
        // against an unassigned slot.
        $firstResponse = $this->actingAs($this->admin)->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Pedro Ramos',
            'walk_in_contact_number' => '09171234567',
            'service_id' => $this->ambulance->getKey(),
            'patient_name' => 'Pedro Ramos',
            'patient_relatives' => ['Lalaine Ferrer'],
            'patient_address' => 'Purok 1, San Fabian',
            'pickup_location' => 'Purok 1, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Hypertensive emergency',
            'scheduled_at' => $this->manilaString($windowStart),
        ])->assertStatus(201);

        $first = ServiceRequest::findOrFail($firstResponse->json('request_id'));

        $this->actingAs($this->admin)
            ->patchJson("/api/service-requests/{$first->getKey()}/approve", [
                'vehicle_id' => $this->vehicle->vehicle_id,
            ])->assertOk();

        $this->assertTrue(
            $first->fresh()->ambulanceBooking->scheduled_at->utc()->equalTo($windowStart)
        );
        $this->assertSame($this->vehicle->vehicle_id, $first->fresh()->vehicle_id);

        // Write path 2: a resident's own submission, for a time that does
        // not overlap — this must succeed, since the unit is free then.
        $laterStart = $windowEnd->copy()->addHours(3);

        $secondResponse = $this->actingAs($this->resident)->postJson('/api/service-requests', [
            'service_id' => $this->ambulance->getKey(),
            'patient_name' => 'Ana Cruz',
            'patient_relatives' => ['Lalaine Ferrer'],
            'destination' => 'Echague District Hospital',
            'valid_id' => \Illuminate\Http\UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
            'scheduled_at' => $this->manilaString($laterStart),
        ])->assertStatus(201);

        $second = ServiceRequest::findOrFail($secondResponse->json('request_id'));

        // Now try to move the second booking into the first one's window,
        // through reschedule() — a different write path entirely, on a
        // different request. The only unit is already committed there.
        $this->actingAs($this->admin)
            ->patchJson("/api/service-requests/{$second->getKey()}/reschedule", [
                'scheduled_at' => $this->manilaString($windowStart),
                'scheduled_end' => $this->manilaString($windowEnd),
                'remarks' => 'Trying to move onto the same slot as the other booking.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_at');

        // Rejected, not silently moved: the second booking still holds its
        // original, non-conflicting time.
        $this->assertTrue(
            $second->fresh()->ambulanceBooking->scheduled_at->utc()->equalTo($laterStart)
        );
    }
}
