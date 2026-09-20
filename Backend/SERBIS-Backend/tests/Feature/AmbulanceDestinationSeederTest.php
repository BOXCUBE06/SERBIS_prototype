<?php

namespace Tests\Feature;

use App\Models\AmbulanceDestination;
use Database\Seeders\AmbulanceDestinationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AmbulanceDestinationSeeder — seeded from a real audit of
 * tbl_ambulance_bookings.destination (docs/ambulance-destinations-audit.md),
 * not invented. Only the one value that survived the audit is asserted here;
 * this test is the guard against someone "helpfully" adding an unverified
 * hospital name later.
 */
class AmbulanceDestinationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_exactly_the_one_real_destination_the_audit_found(): void
    {
        (new AmbulanceDestinationSeeder)->run();

        $this->assertSame(
            ['Echague District Hospital'],
            AmbulanceDestination::pluck('name')->all(),
        );
    }

    public function test_running_it_twice_does_not_duplicate_or_reset_a_custom_list(): void
    {
        (new AmbulanceDestinationSeeder)->run();
        AmbulanceDestination::create(['name' => 'A Destination Staff Added']);

        (new AmbulanceDestinationSeeder)->run();

        $this->assertSame(2, AmbulanceDestination::count());
        $this->assertTrue(AmbulanceDestination::where('name', 'A Destination Staff Added')->exists());
    }
}
