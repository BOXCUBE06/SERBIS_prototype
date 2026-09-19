<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The ambulance form's destination dropdown (MDRRMO feedback, 2026-09-19).
 *
 * Seeded from what was actually in tbl_ambulance_bookings.destination on
 * this deployment, audited 2026-09-19: three distinct values — "Echague
 * District Hospital" (2 bookings), and "asd" / "asdas" (1 each, plainly
 * manual test typos, not real destinations). Only the one real value is
 * seeded here. The dropdown's "Others" option is the fallback for
 * everywhere else a resident might actually be sent — this list is not
 * expected to be complete, only correct.
 */
class AmbulanceDestinationSeeder extends Seeder
{
    private const DESTINATIONS = [
        'Echague District Hospital',
    ];

    public function run(): void
    {
        // name carries a unique constraint, so a blind insert on a second run
        // would abort rather than duplicate — but skip first anyway, the same
        // shape as BarangaySeeder, so a partial custom list an admin has
        // since edited is never silently reset.
        if (DB::table('tbl_ambulance_destinations')->exists()) {
            $this->command?->warn('AmbulanceDestinationSeeder skipped: tbl_ambulance_destinations is not empty.');

            return;
        }

        $now = Carbon::now();

        DB::table('tbl_ambulance_destinations')->insert(
            array_map(fn (string $name) => [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ], self::DESTINATIONS)
        );

        $this->command?->info('AmbulanceDestinationSeeder: created '.count(self::DESTINATIONS).' destination(s).');
    }
}
