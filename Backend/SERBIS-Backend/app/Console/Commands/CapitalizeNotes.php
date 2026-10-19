<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: capitalize the first letter of notes and places saved before the
 * server did it on write (App\Support\Text). Counts only unless --apply.
 *
 * Raw UPDATEs, so TracksHistory writes no system-log row per edited record.
 */
class CapitalizeNotes extends Command
{
    protected $signature = 'serbis:capitalize-notes {--apply : Write the change; without it only the counts are shown}';

    protected $description = 'Capitalize the first letter of existing notes, remarks and place fields';

    private const COLUMNS = [
        'tbl_service_request' => ['description', 'landmark', 'delivery_address', 'remarks', 'internal_notes'],
        'tbl_ambulance_bookings' => ['patient_address', 'pickup_location', 'destination', 'condition_notes'],
        'tbl_conduction_requests' => ['patient_address', 'origin', 'destination', 'medical_diagnosis', 'no_arrival_reason', 'others'],
        'tbl_equipment_borrowing' => ['purpose', 'delivery_address', 'denial_reason', 'return_condition_note'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                // BINARY: the default collation is case-insensitive and would call "a" equal to "A".
                $lowercaseStart = DB::table($table)
                    ->where($column, '<>', '')
                    ->whereRaw("BINARY LEFT(`{$column}`, 1) <> BINARY UPPER(LEFT(`{$column}`, 1))");

                $count = (clone $lowercaseStart)->count();

                if ($apply && $count > 0) {
                    $lowercaseStart->update([
                        $column => DB::raw("CONCAT(UPPER(LEFT(`{$column}`, 1)), SUBSTRING(`{$column}`, 2))"),
                    ]);
                }

                $this->line(sprintf('%s.%s: %d %s', $table, $column, $count, $apply ? 'updated' : 'to update'));
            }
        }

        if (! $apply) {
            $this->info('Dry run. Add --apply to write.');
        }

        return self::SUCCESS;
    }
}
