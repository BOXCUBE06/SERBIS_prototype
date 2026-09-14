<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * tbl_service_request and tbl_ambulance_bookings share three column names —
 * request_id, created_at, updated_at. AmbulanceAvailabilityController::byDate()
 * is the one query that joins the two AND selects tbl_service_request.* (the
 * others select only the specific booking columns they need), so it is the
 * one place a careless widening of the booking-side select list — swapping
 * the two named columns for tbl_ambulance_bookings.* — would silently start
 * overwriting the parent's own created_at/updated_at with the booking's.
 *
 * Mirrors the controller's exact select/join rather than calling the
 * endpoint: byDate()'s JSON response deliberately never serialises
 * created_at at all (its own docblock: only the time range and the unit
 * leave this controller), so the endpoint itself cannot expose this bug.
 */
class AmbulanceBookingJoinColumnSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_joined_created_at_is_the_parents_not_the_bookings(): void
    {
        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $windowStart = Carbon::parse('2026-09-15 08:00:00', 'UTC');
        $windowEnd = $windowStart->copy()->addHours(2);

        // Parent and booking created at deliberately different instants —
        // a real gap this migration's own backfill produced (the booking
        // row was always created after the request it backfills), and
        // exactly the case that would go unnoticed if both timestamps
        // happened to match.
        Carbon::setTestNow('2026-09-01 08:00:00');
        $request = ServiceRequest::create([
            'service_id' => $service->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        Carbon::setTestNow('2026-09-05 10:00:00');
        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => $windowStart,
            'scheduled_end' => $windowEnd,
        ]);
        Carbon::setTestNow();

        $parentCreatedAt = DB::table('tbl_service_request')->where('request_id', $request->getKey())->value('created_at');
        $bookingCreatedAt = DB::table('tbl_ambulance_bookings')->where('request_id', $request->getKey())->value('created_at');
        $this->assertNotSame($parentCreatedAt, $bookingCreatedAt, 'fixture must give parent and booking different created_at, or this test proves nothing');

        // The exact select/join AmbulanceAvailabilityController::byDate() uses.
        $hydrated = Vehicle::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->with(['serviceRequests' => function ($query) use ($windowStart, $windowEnd) {
                $query->select('tbl_service_request.*', 'tbl_ambulance_bookings.scheduled_at', 'tbl_ambulance_bookings.scheduled_end')
                    ->join('tbl_ambulance_bookings', 'tbl_ambulance_bookings.request_id', '=', 'tbl_service_request.request_id')
                    ->where('tbl_ambulance_bookings.scheduled_at', '<', $windowEnd->copy()->addDay())
                    ->where('tbl_ambulance_bookings.scheduled_end', '>', $windowStart->copy()->subDay());
            }])
            ->firstOrFail();

        $joinedRequest = $hydrated->serviceRequests->firstOrFail();

        $this->assertTrue($joinedRequest->created_at->equalTo(Carbon::parse($parentCreatedAt)));
        $this->assertFalse($joinedRequest->created_at->equalTo(Carbon::parse($bookingCreatedAt)));

        // The columns the query actually adds from the booking are correct too.
        $this->assertTrue($joinedRequest->scheduled_at->equalTo($windowStart));
        $this->assertTrue($joinedRequest->scheduled_end->equalTo($windowEnd));
    }
}
