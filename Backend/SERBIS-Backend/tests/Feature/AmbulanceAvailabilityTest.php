<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use App\Services\AmbulanceAvailability;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * availableAmbulances() is the correctness centre of ambulance scheduling: every
 * caller trusts it never offers a unit that is already committed. Each test below
 * asserts against the returned unit_identifier set, not just a count — a wrong
 * unit excluded would pass a count-only assertion the same as a right one.
 */
class AmbulanceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private AmbulanceAvailability $availability;
    private Service $service;

    /** The four seeded Ambulance units, keyed by unit_identifier. */
    private array $units = [];

    private Carbon $windowStart;
    private Carbon $windowEnd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new AmbulanceAvailability();

        $this->service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical transport',
        ]);

        foreach (['AMB-01', 'AMB-02', 'AMB-03', 'AMB-04'] as $identifier) {
            $this->units[$identifier] = Vehicle::create([
                'unit_identifier' => $identifier,
                'type' => 'Ambulance',
                'specification' => 'Type I',
                'status' => 'Available',
            ]);
        }

        // A non-Ambulance unit, present throughout to prove type filtering holds.
        Vehicle::create([
            'unit_identifier' => 'RES-01',
            'type' => 'Rescue Vehicle',
            'specification' => 'Pickup',
            'status' => 'Available',
        ]);

        $this->windowStart = Carbon::parse('2026-09-01 08:00:00', 'UTC');
        $this->windowEnd = Carbon::parse('2026-09-01 10:00:00', 'UTC');
    }

    private function booking(Vehicle $vehicle, string $status, ?Carbon $start, ?Carbon $end): ServiceRequest
    {
        return ServiceRequest::create([
            'service_id' => $this->service->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'description' => 'Test booking',
            'status' => $status,
            'scheduled_at' => $start,
            'scheduled_end' => $end,
        ]);
    }

    private function availableIdentifiers(): array
    {
        return $this->availability
            ->availableAmbulances($this->windowStart, $this->windowEnd)
            ->pluck('unit_identifier')
            ->sort()
            ->values()
            ->all();
    }

    public function test_no_bookings_all_four_units_free(): void
    {
        $this->assertSame(['AMB-01', 'AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_booking_exactly_covering_the_window_is_excluded(): void
    {
        $this->booking($this->units['AMB-01'], 'Booked', $this->windowStart->copy(), $this->windowEnd->copy());

        $this->assertSame(['AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_booking_ending_exactly_at_window_start_leaves_unit_free(): void
    {
        $this->booking(
            $this->units['AMB-01'],
            'Booked',
            $this->windowStart->copy()->subHours(2),
            $this->windowStart->copy(),
        );

        $this->assertSame(['AMB-01', 'AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_booking_starting_exactly_at_window_end_leaves_unit_free(): void
    {
        $this->booking(
            $this->units['AMB-01'],
            'Booked',
            $this->windowEnd->copy(),
            $this->windowEnd->copy()->addHours(2),
        );

        $this->assertSame(['AMB-01', 'AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_booking_overlapping_only_the_first_hour_is_excluded(): void
    {
        $this->booking(
            $this->units['AMB-01'],
            'Booked',
            $this->windowStart->copy()->subHour(),
            $this->windowStart->copy()->addHour(),
        );

        $this->assertSame(['AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_booking_overlapping_only_the_last_hour_is_excluded(): void
    {
        $this->booking(
            $this->units['AMB-01'],
            'Booked',
            $this->windowEnd->copy()->subHour(),
            $this->windowEnd->copy()->addHour(),
        );

        $this->assertSame(['AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_booking_fully_inside_the_window_is_excluded(): void
    {
        $this->booking(
            $this->units['AMB-01'],
            'Booked',
            $this->windowStart->copy()->addMinutes(15),
            $this->windowStart->copy()->addMinutes(45),
        );

        $this->assertSame(['AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_window_fully_inside_a_booking_is_excluded(): void
    {
        $this->booking(
            $this->units['AMB-01'],
            'Booked',
            $this->windowStart->copy()->subHours(2),
            $this->windowEnd->copy()->addHours(2),
        );

        $this->assertSame(['AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_a_cancelled_booking_is_ignored(): void
    {
        $this->booking($this->units['AMB-01'], 'Cancelled', $this->windowStart->copy(), $this->windowEnd->copy());

        $this->assertSame(['AMB-01', 'AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_a_disapproved_booking_is_ignored(): void
    {
        $this->booking($this->units['AMB-01'], 'Disapproved', $this->windowStart->copy(), $this->windowEnd->copy());

        $this->assertSame(['AMB-01', 'AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_a_maintenance_unit_is_excluded_regardless_of_bookings(): void
    {
        $this->units['AMB-01']->update(['status' => 'Maintenance']);

        $this->assertSame(['AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }

    public function test_a_dispatched_unit_on_a_null_scheduled_at_request_is_excluded(): void
    {
        $this->units['AMB-01']->update(['status' => 'Dispatched']);
        $this->booking($this->units['AMB-01'], 'Responding', null, null);

        $this->assertSame(['AMB-02', 'AMB-03', 'AMB-04'], $this->availableIdentifiers());
    }
}
