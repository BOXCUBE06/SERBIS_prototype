<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Responder;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * The dashboard's live strip and Today rail: responder counts, and bookings in
 * the next 24 hours that share a unit with an overlapping booking.
 */
class DashboardLiveFiguresTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Service::create(['service_name' => 'Ambulance/Medical Response']);
        Sanctum::actingAs($this->makeSuperAdmin());
    }

    private function unit(string $identifier): Vehicle
    {
        return Vehicle::create(['unit_identifier' => $identifier, 'type' => 'Ambulance', 'specification' => 'Type I', 'status' => 'Available']);
    }

    private function booking(Vehicle $vehicle, Carbon $start, ?Carbon $end = null, string $status = 'Booked'): int
    {
        $request = ServiceRequest::create([
            'service_id' => $this->service->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'description' => 'Test booking',
            'status' => $status,
        ]);
        AmbulanceBooking::create(['request_id' => $request->getKey(), 'scheduled_at' => $start, 'scheduled_end' => $end]);

        return $request->getKey();
    }

    private function conflicts(): array
    {
        return collect($this->getJson('/api/admin/dashboard')->assertOk()->json('bookingConflicts'))->sort()->values()->all();
    }

    public function test_responders_are_counted_by_availability(): void
    {
        foreach (['available', 'available', 'deployed', 'off_duty'] as $i => $status) {
            Responder::create(['name' => "R$i", 'contact_no' => '09170000000', 'position' => 'EMT', 'status' => $status]);
        }

        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('responders', ['available' => 2, 'total' => 4]);
    }

    public function test_overlapping_bookings_on_one_unit_are_both_flagged(): void
    {
        $unit = $this->unit('AMB-01');
        $a = $this->booking($unit, now()->addHours(2), now()->addHours(4));
        $b = $this->booking($unit, now()->addHours(3), now()->addHours(5));

        $this->assertSame([$a, $b], $this->conflicts());
    }

    public function test_the_default_window_counts_when_there_is_no_end(): void
    {
        $unit = $this->unit('AMB-01');
        // No scheduled_end: holds the default 2 hours, so 1h later overlaps.
        $a = $this->booking($unit, now()->addHours(2));
        $b = $this->booking($unit, now()->addHours(3), now()->addHours(4));

        $this->assertSame([$a, $b], $this->conflicts());
    }

    public function test_adjacent_other_unit_and_closed_bookings_are_not_conflicts(): void
    {
        $unit = $this->unit('AMB-01');
        $this->booking($unit, now()->addHours(2), now()->addHours(3));
        $this->booking($unit, now()->addHours(3), now()->addHours(4));
        $this->booking($this->unit('AMB-02'), now()->addHours(2), now()->addHours(4));
        $this->booking($unit, now()->addMinutes(150), now()->addHours(4), 'Cancelled');

        $this->assertSame([], $this->conflicts());
    }

    public function test_only_bookings_starting_in_the_next_24_hours_are_flagged(): void
    {
        $unit = $this->unit('AMB-01');
        $this->booking($unit, now()->addHours(30), now()->addHours(32));
        $this->booking($unit, now()->addHours(31), now()->addHours(33));

        $this->assertSame([], $this->conflicts());
    }
}
