<?php

namespace App\Console\Commands;

use App\Services\BarangaySync;
use Illuminate\Console\Command;

/**
 * Brings tbl_barangay in line with the 64 official barangays of Echague.
 * Run it with --dry-run first: it prints what would change and writes nothing.
 *
 * Never deletes or renumbers a row; an existing row that matches no official
 * barangay is listed and left alone. Safe to run again.
 */
class SyncBarangays extends Command
{
    protected $signature = 'barangays:sync {--dry-run : Print what would change without writing}';

    protected $description = 'Match tbl_barangay to the 64 official Echague barangays by PSGC code, adding the missing ones';

    public function handle(BarangaySync $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $plan = $dry ? $sync->plan() : $sync->apply();

        $this->info($dry ? 'DRY RUN: nothing was written.' : 'Applied in one transaction.');

        $this->line('Existing rows matched'.($dry ? '' : ' (name matches were stamped with their code)').':');
        $this->table(
            ['id', 'current name', 'official name', 'psgc_code', 'match'],
            array_map(fn ($m) => [$m['id'], $m['current'], $m['official'], $m['code'], $m['type']], $plan['matched']),
        );

        $this->line($dry ? 'Rows to insert:' : 'Rows inserted:');
        $this->table(['psgc_code', 'name'], array_map(fn ($i) => [$i['code'], $i['name']], $plan['inserts']));

        $this->line('Existing rows with no match (left untouched):');
        $this->table(['id', 'name'], array_map(fn ($u) => [$u['id'], $u['name']], $plan['unmatched']));

        return self::SUCCESS;
    }
}
