<?php

namespace Tests\Unit;

use App\Http\Controllers\ServiceRequestController;
use App\Services\AmbulanceAvailability;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The overlap itself runs in SQL (the Feature tests cover it). These pin the
 * pure pieces it is built from.
 */
class AmbulanceAvailabilityOverlapTest extends TestCase
{
    public function test_window_end_falls_back_to_the_default_booking_length(): void
    {
        $sql = (new ReflectionMethod(AmbulanceAvailability::class, 'windowEnd'))->invoke(null, 'b');

        $this->assertSame(
            'COALESCE(b.scheduled_end, DATE_ADD(b.scheduled_at, INTERVAL '.ServiceRequestController::DEFAULT_BOOKING_HOURS.' HOUR))',
            $sql,
        );
    }

    public function test_only_closed_requests_release_a_unit(): void
    {
        $terminal = ServiceRequestController::TERMINAL_STATUSES;

        foreach (['Pending', 'Booked', 'Responding'] as $open) {
            $this->assertNotContains($open, $terminal);
        }
        foreach (['Resolved', 'Cancelled', 'Disapproved'] as $closed) {
            $this->assertContains($closed, $terminal);
        }
    }

    public function test_default_booking_length_is_positive(): void
    {
        $this->assertGreaterThan(0, ServiceRequestController::DEFAULT_BOOKING_HOURS);
    }
}
