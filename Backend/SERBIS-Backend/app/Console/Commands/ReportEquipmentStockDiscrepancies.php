<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only. Written after tracing the Oxygen Tank row (45 total, 46
 * available) to a Released->Returned transition whose Released side never
 * decremented stock — see EquipmentBorrowingController::update() and the
 * clamp added there.
 *
 * available_quantity is a stored column, not derived — nothing recomputes it
 * on read. This lists every equipment row's stored value next to what it
 * should be if `Released` is the only status currently holding stock out:
 * total_quantity - SUM(quantity WHERE status = 'Released'). Nothing here
 * writes; it only reports where the two disagree.
 */
class ReportEquipmentStockDiscrepancies extends Command
{
    protected $signature = 'serbis:report-equipment-stock';

    protected $description = 'Compare stored available_quantity against total_quantity minus quantity on Released loans, for every equipment row';

    public function handle(): int
    {
        $this->line(sprintf(
            'Connected to %s@%s:%s/%s',
            config('database.connections.'.config('database.default').'.username'),
            config('database.connections.'.config('database.default').'.host'),
            config('database.connections.'.config('database.default').'.port'),
            DB::getDatabaseName(),
        ));
        $this->newLine();

        $released = DB::table('tbl_equipment_borrowing')
            ->where('status', 'Released')
            ->groupBy('equipment_id')
            ->select('equipment_id', DB::raw('SUM(quantity) as released_qty'))
            ->pluck('released_qty', 'equipment_id');

        $equipment = DB::table('tbl_equipments')->orderBy('equipment_id')->get();

        $rows = [];
        $mismatches = 0;

        foreach ($equipment as $item) {
            $releasedQty = (int) ($released[$item->equipment_id] ?? 0);
            $expected = $item->total_quantity - $releasedQty;
            $stored = $item->available_quantity;
            $diff = $stored - $expected;
            $match = $diff === 0;

            if (! $match) {
                $mismatches++;
            }

            $rows[] = [
                $item->equipment_id,
                $item->item_name,
                $item->total_quantity,
                $releasedQty,
                $expected,
                $stored,
                $diff > 0 ? "+{$diff}" : (string) $diff,
                $match ? 'OK' : 'MISMATCH',
            ];
        }

        $this->table(
            ['id', 'item_name', 'total_quantity', 'released_qty', 'expected_available', 'stored_available', 'diff', 'status'],
            $rows,
        );

        $this->newLine();

        if ($mismatches === 0) {
            $this->info('No mismatches: every stored available_quantity matches total_quantity - Released quantity.');

            return self::SUCCESS;
        }

        $this->warn("{$mismatches} equipment row(s) disagree with the expected value.");
        $this->line('A positive diff means available_quantity is higher than it should be — more units are shown free than the Released ledger accounts for.');
        $this->line('No corrections applied. This is a report only.');

        return self::FAILURE;
    }
}
