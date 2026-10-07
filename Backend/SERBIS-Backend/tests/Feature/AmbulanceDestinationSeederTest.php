<?php

namespace Tests\Feature;

use App\Models\AmbulanceDestination;
use Database\Seeders\AmbulanceDestinationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AmbulanceDestinationSeeder — seeded from a real audit of
 * tbl_ambulance_bookings.destination (docs/ambulance-destinations-audit.md),
 * plus the two hospitals the office named (2026-10-07). This test guards the
 * exact list against someone "helpfully" adding an unverified hospital name.
 */
class AmbulanceDestinationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_exactly_the_listed_destinations(): void
    {
        (new AmbulanceDestinationSeeder)->run();

        $this->assertSame(
            ['Echague District Hospital', 'Isabela Southern Specialist Hospital Inc.', 'Southern Isabela Medical Center'],
            AmbulanceDestination::orderBy('id')->pluck('name')->all(),
        );
    }

    public function test_running_it_twice_does_not_duplicate_or_reset_a_custom_list(): void
    {
        (new AmbulanceDestinationSeeder)->run();
        AmbulanceDestination::create(['name' => 'A Destination Staff Added']);

        (new AmbulanceDestinationSeeder)->run();

        $this->assertSame(4, AmbulanceDestination::count());
        $this->assertTrue(AmbulanceDestination::where('name', 'A Destination Staff Added')->exists());
    }
}
