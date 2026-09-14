<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EquipmentBorrowingSeeder extends Seeder
{
    /**
     * One row per status so the borrowing views and dashboard have every state
     * to render. Offsets are in hours back from now; resident/equipment are
     * offsets into the real id lists, never literal ids.
     */
    private const SCENARIOS = [
        ['resident' => 0, 'equipment' => 0, 'quantity' => 1, 'purpose' => 'Barangay flood drill this weekend.', 'status' => 'Pending',  'created' => 2,   'updated' => 2,  'released' => null, 'returned' => null],
        ['resident' => 1, 'equipment' => 2, 'quantity' => 2, 'purpose' => 'Standby cover for the fiesta parade route.', 'status' => 'Approved', 'created' => 24,  'updated' => 5,  'released' => null, 'returned' => null],
        ['resident' => 2, 'equipment' => 3, 'quantity' => 1, 'purpose' => 'Clearing debris along the riverbank after the storm.', 'status' => 'Released', 'created' => 72,  'updated' => 48, 'released' => 48,   'returned' => null],
        ['resident' => 3, 'equipment' => 1, 'quantity' => 1, 'purpose' => 'First aid post for the barangay basketball league.', 'status' => 'Returned', 'created' => 144, 'updated' => 24, 'released' => 120,  'returned' => 24],
        ['resident' => 4, 'equipment' => 4, 'quantity' => 1, 'purpose' => 'Personal use at a family outing.', 'status' => 'Denied',   'created' => 48,  'updated' => 24, 'released' => null, 'returned' => null],
        ['resident' => 5, 'equipment' => 6, 'quantity' => 3, 'purpose' => 'Evacuation centre setup for the incoming typhoon.', 'status' => 'Pending',  'created' => 1,   'updated' => 1,  'released' => null, 'returned' => null],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'EquipmentBorrowingSeeder skipped: refuses to seed outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        // Draw from ids that actually exist. Literal ids were what orphaned
        // rows in the resident seeder, and the FK will not catch a stale one.
        $residentIds = DB::table('tbl_residents')->pluck('resident_id')->all();
        $equipmentIds = DB::table('tbl_equipments')->pluck('equipment_id')->all();

        if (empty($residentIds) || empty($equipmentIds)) {
            $this->command?->warn(
                'EquipmentBorrowingSeeder skipped: needs residents and equipment — run their seeders first.'
            );

            return;
        }

        $rows = [];

        foreach (self::SCENARIOS as $s) {
            // Carbon is mutable and subHours() mutates in place, so every
            // offset is measured from its own copy of the base timestamp.
            $at = fn (?int $hours) => $hours === null ? null : Carbon::now()->subHours($hours);

            $rows[] = [
                'resident_id' => $residentIds[$s['resident'] % count($residentIds)],
                'equipment_id' => $equipmentIds[$s['equipment'] % count($equipmentIds)],
                'quantity' => $s['quantity'],
                'purpose' => $s['purpose'],
                'status' => $s['status'],
                'released_at' => $at($s['released']),
                'returned_at' => $at($s['returned']),
                'created_at' => $at($s['created']),
                'updated_at' => $at($s['updated']),
            ];
        }

        // A raw insert never goes through EquipmentBorrowingController::update(),
        // so a 'Released' row seeded here — units genuinely out — never
        // decremented the equipment it borrowed. That mismatch is exactly what
        // let a later, real Released->Returned transition push
        // available_quantity past total_quantity: nothing had ever subtracted
        // for the release in the first place, so the return's increment had no
        // matching withdrawal to restore. Reconciled here so seeded data starts
        // consistent — a 'Returned' row nets to zero (released then returned)
        // and needs no adjustment; only 'Released' currently holds stock out.
        $releasedByEquipment = collect($rows)
            ->where('status', 'Released')
            ->groupBy('equipment_id')
            ->map(fn ($group) => collect($group)->sum('quantity'));

        DB::transaction(function () use ($rows, $releasedByEquipment) {
            DB::table('tbl_equipment_borrowing')->insert($rows);

            foreach ($releasedByEquipment as $equipmentId => $releasedQty) {
                DB::table('tbl_equipments')
                    ->where('equipment_id', $equipmentId)
                    ->update([
                        // Clamped at 0 rather than trusted to stay positive —
                        // this seeder should never itself become a source of
                        // the same kind of unchecked-arithmetic corruption it
                        // was written to stop compounding.
                        'available_quantity' => DB::raw("GREATEST(0, available_quantity - {$releasedQty})"),
                    ]);
            }
        });

        $this->command?->info('EquipmentBorrowingSeeder: created '.count($rows).' borrowings.');

        if ($releasedByEquipment->isNotEmpty()) {
            $this->command?->info(
                'EquipmentBorrowingSeeder: decremented available_quantity for '
                .$releasedByEquipment->count().' equipment row(s) to match seeded Released loans.'
            );
        }
    }
}
