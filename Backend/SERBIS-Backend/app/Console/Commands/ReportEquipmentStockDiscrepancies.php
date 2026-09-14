<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Read-only by default. Written after tracing the Oxygen Tank row (45 total,
 * 46 available) to a Released->Returned transition whose Released side never
 * decremented stock — see EquipmentBorrowingController::update() and the
 * clamp added there.
 *
 * available_quantity is a stored column, not derived — nothing recomputes it
 * on read. This lists every equipment row's stored value next to what it
 * should be if `Released` is the only status currently holding stock out:
 * total_quantity - SUM(quantity WHERE status = 'Released'). With --fix, a
 * MISMATCH row is corrected to its expected value; a row already OK is left
 * untouched even under --fix.
 */
class ReportEquipmentStockDiscrepancies extends Command
{
    protected $signature = 'serbis:report-equipment-stock {--fix : Correct MISMATCH rows to their expected available_quantity and log each correction}';

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

        if (! $this->option('fix')) {
            $this->line('No corrections applied. This is a report only. Pass --fix to correct MISMATCH rows.');

            return self::FAILURE;
        }

        $corrected = $this->applyFixes($equipment, $released);
        $this->info("{$corrected} row(s) corrected. Re-run without --fix to confirm.");

        return self::SUCCESS;
    }

    /**
     * Locks and re-checks each mismatched row inside its own transaction
     * rather than trusting the values already read above, so a stock write
     * racing this command (a borrowing release/return) can't be clobbered by
     * a correction based on stale numbers.
     */
    private function applyFixes($equipment, $released): int
    {
        $corrected = 0;

        foreach ($equipment as $item) {
            $releasedQty = (int) ($released[$item->equipment_id] ?? 0);
            $expected = $item->total_quantity - $releasedQty;

            if ($item->available_quantity === $expected) {
                continue;
            }

            DB::transaction(function () use ($item, $expected, &$corrected) {
                $current = Equipment::lockForUpdate()->find($item->equipment_id);

                if (! $current || $current->available_quantity === $expected) {
                    return;
                }

                DB::table('tbl_system_logs')->insert([
                    'admin_id' => Auth::user() instanceof User ? Auth::id() : null,
                    'resident_id' => Auth::user() instanceof Resident ? Auth::id() : null,
                    'action_type' => 'stock_corrected',
                    'auditable_type' => Equipment::class,
                    'auditable_id' => $current->getKey(),
                    'old_values' => json_encode(['available_quantity' => $current->available_quantity]),
                    'new_values' => json_encode(['available_quantity' => $expected]),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $current->available_quantity = $expected;
                $current->saveQuietly();

                $corrected++;
            });
        }

        return $corrected;
    }
}
