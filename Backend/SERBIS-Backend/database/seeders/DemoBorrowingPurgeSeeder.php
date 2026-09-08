<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Undo for DemoBorrowingSeeder.
 *
 *     php artisan db:seed --class=DemoBorrowingPurgeSeeder
 *
 * Deletes every borrowing whose `purpose` starts with the [demo] marker and
 * gives back the stock the demo Released rows were holding. A row a person
 * filed never carries that marker, so nothing real is in range.
 *
 * The work itself lives in DemoBorrowingSeeder::purge() — the seeder calls it
 * too, before re-inserting, which is what makes running it twice leave thirty
 * rows rather than sixty. One implementation, so "undo" cannot drift from
 * "replace".
 */
class DemoBorrowingPurgeSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'DemoBorrowingPurgeSeeder skipped: refuses to delete rows outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        $removed = DemoBorrowingSeeder::purge($this->command);

        $this->command?->info(
            $removed === 0
                ? 'DemoBorrowingPurgeSeeder: nothing to remove — no rows carry the '.DemoBorrowingSeeder::MARKER.' marker.'
                : "DemoBorrowingPurgeSeeder: removed {$removed} demo borrowing(s)."
        );
    }
}
