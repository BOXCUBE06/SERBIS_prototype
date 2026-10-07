<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /api/ambulance-schedule: what the Ambulance schedule popup draws. */
class AmbulanceScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private Vehicle $unit;

    private Resident $resident;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'Transport']);
        $this->unit = Vehicle::create(['unit_identifier' => 'AMB-01', 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available']);
        Vehicle::create(['unit_identifier' => 'AMB-02', 'type' => 'Ambulance', 'specification' => 'PTU', 'status' => 'Maintenance']);
        Vehicle::create(['unit_identifier' => 'RES-01', 'type' => 'Rescue Vehicle', 'status' => 'Available']);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id, 'first_name' => 'Maria', 'last_name' => 'Santos', 'phone_number' => '09171111111',
            'email_address' => 'maria@test.local', 'password' => Hash::make('Password123'), 'status' => 'Active',
        ]);
        $this->admin = User::create([
            'first_name' => 'MDRRMO', 'last_name' => 'Admin', 'username' => 'admin', 'password' => Hash::make('Password123'),
            'role' => 'Admin', 'status' => 'Active',
        ]);
    }

    private function trip(string $status, ?string $start, ?string $end = null, array $request = [], array $booking = []): ServiceRequest
    {
        $sr = ServiceRequest::create($request + [
            'resident_id' => $this->resident->getKey(), 'service_id' => $this->service->getKey(), 'status' => $status,
            'vehicle_id' => $status === 'Pending' ? null : $this->unit->vehicle_id,
        ]);
        AmbulanceBooking::create($booking + [
            'request_id' => $sr->request_id, 'patient_name' => 'Rosa Pascual', 'pickup_location' => 'Purok 2, San Fabian',
            'scheduled_at' => $start, 'scheduled_end' => $end,
        ]);

        return $sr;
    }

    private function schedule(string $from, string $to)
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson("/api/ambulance-schedule?from={$from}&to={$to}");
    }

    public function test_it_lists_every_ambulance_unit_and_the_trips_in_range(): void
    {
        $booked = $this->trip('Booked', '2026-10-05 01:00:00', '2026-10-05 03:00:00');
        $this->trip('Resolved', '2026-10-06 02:00:00', '2026-10-06 05:00:00');
        $this->trip('Booked', '2026-11-20 02:00:00', '2026-11-20 04:00:00');

        $response = $this->schedule('2026-10-05', '2026-10-06')->assertOk();

        $this->assertSame(['AMB-01', 'AMB-02'], collect($response->json('units'))->pluck('unit_identifier')->all());
        $this->assertTrue($response->json('units.1.is_maintenance'));
        $this->assertCount(2, $response->json('trips'));
        $this->assertSame($booked->request_id, $response->json('trips.0.request_id'));
        $this->assertSame('Rosa Pascual', $response->json('trips.0.patient_name'));
        $this->assertSame('Maria Santos', $response->json('trips.0.requested_by'));
        $this->assertSame('San Fabian', $response->json('trips.0.from'));
        $this->assertFalse($response->json('trips.0.end_is_default'));
    }

    public function test_cancelled_and_disapproved_trips_never_appear(): void
    {
        $this->trip('Cancelled', '2026-10-05 01:00:00', '2026-10-05 03:00:00');
        $this->trip('Disapproved', '2026-10-05 04:00:00', '2026-10-05 06:00:00');

        $this->assertSame([], $this->schedule('2026-10-05', '2026-10-05')->json('trips'));
    }

    public function test_the_range_is_manila_calendar_days(): void
    {
        // 16:30 UTC on the 4th is 00:30 on the 5th in Manila.
        $this->trip('Booked', '2026-10-04 16:30:00', '2026-10-04 18:30:00');

        $this->assertCount(1, $this->schedule('2026-10-05', '2026-10-05')->json('trips'));
        $this->assertCount(0, $this->schedule('2026-10-04', '2026-10-04')->json('trips'));
    }

    public function test_an_unscheduled_trip_is_placed_when_it_was_dispatched_or_filed_and_ends_two_hours_later(): void
    {
        $pending = $this->trip('Pending', null);
        $pending->forceFill(['created_at' => '2026-10-05 02:00:00'])->saveQuietly();
        $responding = $this->trip('Responding', null);
        $responding->forceFill(['created_at' => '2026-10-05 00:00:00', 'first_responded_at' => '2026-10-05 05:15:00'])->saveQuietly();

        $trips = collect($this->schedule('2026-10-05', '2026-10-05')->json('trips'))->keyBy('request_id');

        $this->assertSame('2026-10-05T02:00:00+00:00', $trips[$pending->request_id]['starts_at']);
        $this->assertSame('2026-10-05T04:00:00+00:00', $trips[$pending->request_id]['ends_at']);
        $this->assertTrue($trips[$pending->request_id]['end_is_default']);
        $this->assertNull($trips[$pending->request_id]['vehicle_id']);
        $this->assertSame('2026-10-05T05:15:00+00:00', $trips[$responding->request_id]['starts_at']);
    }

    public function test_a_booking_without_an_end_gets_the_default_window(): void
    {
        $this->trip('Booked', '2026-10-05 01:00:00', null);

        $trip = $this->schedule('2026-10-05', '2026-10-05')->json('trips.0');

        $this->assertSame(Carbon::parse('2026-10-05 03:00:00', 'UTC')->toIso8601String(), $trip['ends_at']);
        $this->assertTrue($trip['end_is_default']);
    }

    public function test_a_walk_in_is_named_by_what_the_counter_typed_and_shows_the_pickup(): void
    {
        $this->trip('Booked', '2026-10-05 01:00:00', '2026-10-05 03:00:00', ['resident_id' => null, 'walk_in_name' => 'Pedro Gumaru']);

        $trip = $this->schedule('2026-10-05', '2026-10-05')->json('trips.0');

        $this->assertSame('Pedro Gumaru', $trip['requested_by']);
        $this->assertSame('Purok 2, San Fabian', $trip['from']);
    }

    public function test_a_request_still_waiting_comes_back_whatever_the_range(): void
    {
        $waiting = $this->trip('Pending', null);
        $waiting->forceFill(['created_at' => '2026-09-01 02:00:00'])->saveQuietly();
        // A unit is already on it: not waiting, and outside the range.
        $assigned = $this->trip('Pending', null, null, ['vehicle_id' => $this->unit->vehicle_id]);
        $assigned->forceFill(['created_at' => '2026-09-01 02:00:00'])->saveQuietly();
        $scheduled = $this->trip('Pending', '2026-10-05 01:00:00', null);

        $trips = collect($this->schedule('2026-10-05', '2026-10-05')->json('trips'))->keyBy('request_id');

        $this->assertTrue($trips[$waiting->request_id]['waiting']);
        $this->assertSame('2026-09-01T02:00:00+00:00', $trips[$waiting->request_id]['starts_at']);
        $this->assertArrayNotHasKey($assigned->request_id, $trips->all());
        $this->assertFalse($trips[$scheduled->request_id]['waiting']);
    }

    public function test_a_unit_out_on_a_trip_comes_back_whatever_the_range(): void
    {
        $out = $this->trip('Responding', null);
        $out->forceFill(['created_at' => '2026-09-01 00:00:00', 'first_responded_at' => '2026-09-01 01:00:00'])->saveQuietly();
        $done = $this->trip('Resolved', null);
        $done->forceFill(['created_at' => '2026-09-01 00:00:00', 'first_responded_at' => '2026-09-01 01:00:00'])->saveQuietly();

        $ids = collect($this->schedule('2026-10-05', '2026-10-05')->json('trips'))->pluck('request_id')->all();

        $this->assertSame([$out->request_id], $ids);
    }

    public function test_a_dispatched_trip_starts_when_it_left_and_keeps_its_booked_length(): void
    {
        $late = $this->trip('Responding', '2026-10-05 01:00:00', '2026-10-05 03:00:00');
        $late->forceFill(['first_responded_at' => '2026-10-05 01:30:00'])->saveQuietly();
        $booked = $this->trip('Booked', '2026-10-05 05:00:00', '2026-10-05 06:00:00');

        $trips = collect($this->schedule('2026-10-05', '2026-10-05')->json('trips'))->keyBy('request_id');

        $this->assertSame('2026-10-05T01:30:00+00:00', $trips[$late->request_id]['starts_at']);
        $this->assertSame('2026-10-05T03:30:00+00:00', $trips[$late->request_id]['ends_at']);
        $this->assertFalse($trips[$late->request_id]['end_is_default']);
        // Not dispatched yet: still where it was booked.
        $this->assertSame('2026-10-05T05:00:00+00:00', $trips[$booked->request_id]['starts_at']);
        $this->assertSame('2026-10-05T06:00:00+00:00', $trips[$booked->request_id]['ends_at']);
    }

    public function test_it_refuses_a_range_longer_than_the_grid_needs(): void
    {
        $this->schedule('2026-10-01', '2026-12-31')->assertStatus(422);
        $this->schedule('2026-10-09', '2026-10-05')->assertStatus(422);
    }

    public function test_only_staff_with_the_ambulance_section_may_read_it(): void
    {
        Sanctum::actingAs($this->resident);
        $this->getJson('/api/ambulance-schedule?from=2026-10-05&to=2026-10-05')->assertForbidden();

        $limited = User::create([
            'first_name' => 'Jonas', 'last_name' => 'Tumaneng', 'username' => 'jonas', 'password' => Hash::make('Password123'),
            'role' => 'Admin', 'status' => 'Active',
        ]);
        $limited->forceFill(['permissions' => ['borrowings']])->save();
        Sanctum::actingAs($limited);
        $this->getJson('/api/ambulance-schedule?from=2026-10-05&to=2026-10-05')->assertForbidden();
    }
}
