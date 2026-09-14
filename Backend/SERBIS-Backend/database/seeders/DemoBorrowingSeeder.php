<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A full board of borrowings for looking at: five rows in every status the
 * column accepts, with the fulfilment, borrower-type and uncatalogued-item
 * fields varied so the panel and the procurement reference have something to
 * render.
 *
 * Separate from EquipmentBorrowingSeeder, which stays as it is: that one is
 * part of `db:seed` and makes six rows, one per state, as a minimum for a
 * fresh database. This one is demo data, run on its own, and is not in
 * DatabaseSeeder.
 *
 * ## Every row is labelled
 *
 * `purpose` begins with [demo]. That is the marker, and it is deliberately a
 * user-visible column rather than a hidden flag: someone looking at the board
 * can see which rows are fabricated without querying anything. It is also what
 * makes this seeder repeatable — it deletes its own rows before inserting, so
 * running it twice leaves 30 rows and not 60, and it never touches a row a
 * person filed.
 *
 * ## Statuses
 *
 * Read off `2026_09_03_100000_add_cancelled_to_tbl_equipment_borrowing_status`,
 * which is the migration that last rewrote the enum, not off the model:
 * Pending, Approved, Released, Returned, Denied, Cancelled.
 *
 * ## Two rules the data has to keep
 *
 * `chk_equipment_borrowing_item_source` (from the other_equipment migration)
 * requires exactly one of `equipment_id` and `other_equipment_text` — the CHECK
 * is real on MariaDB 10.4, so a row breaking it fails the insert rather than
 * landing wrong.
 *
 * An uncatalogued row is never Released or Returned here.
 * EquipmentBorrowingController::update() refuses that transition outright —
 * there is no stock to deduct and nothing the inventory knows about — so a
 * seeded one would be a state the application cannot produce. The uncatalogued
 * rows sit in Pending, Approved, Denied and Cancelled, which is where the
 * office can actually consider them.
 *
 * ## What this does to stock
 *
 * A Released row means units are physically out, and a raw insert does not go
 * through update(), which is the only thing that decrements. So this seeder
 * subtracts for its own Released rows and reports what it changed; the purge
 * adds the same quantities back. A Returned row nets to zero and is left alone.
 * Leaving the subtraction out is what once let a real Released -> Returned
 * transition push available_quantity past total_quantity — the return had no
 * matching withdrawal to restore (see the clamp in
 * EquipmentBorrowingController::update()).
 */
class DemoBorrowingSeeder extends Seeder
{
    /**
     * Marker and prefix in one. Matched with a LIKE on purpose, so it has to
     * stay at the front of the string.
     */
    public const MARKER = '[demo]';

    /**
     * Barangays as seeded by BarangaySeeder — a delivery address has to name a
     * place a resident of this municipality would actually give.
     */
    private const ADDRESSES = [
        'Purok 2, San Fabian — beside the barangay hall',
        'Purok 4, San Miguel — near the elementary school',
        'Purok 1, San Antonio Ugad — end of the riverside road',
        'Purok 3, San Fabian — across the covered court',
        'Purok 5, San Miguel — corner of the national road',
    ];

    private const ORGANIZATIONS = [
        'Barangay San Fabian BDRRMC',
        'Isabela State University — Echague Campus',
        'Echague National High School Red Cross Youth',
        'San Miguel Purok 3 Neighborhood Watch',
        'St. Ferdinand Parish Youth Ministry',
        'Echague Rural Health Unit',
    ];

    /**
     * Items the office does not stock. These are what the procurement
     * reference is for: real things a barangay asks for and MDRRMO cannot lend.
     */
    private const UNCATALOGUED = [
        'Portable generator, 5kVA or larger',
        'Chainsaw with a 20-inch bar',
        'Inflatable rescue boat, 6-person',
        'Water pump for flooded ground floors',
        'Portable floodlight tower for night operations',
        'Two-way radios, at least six handsets',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'DemoBorrowingSeeder skipped: refuses to seed outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        // Real ids only. Literal ids are what orphaned rows in an earlier
        // seeder, and a stale one would fail the FK at insert.
        $residentIds = DB::table('tbl_residents')->orderBy('resident_id')->pluck('resident_id')->all();

        // Ordered by what there is most of: the Released rows below hold stock
        // out, and taking it from the deepest shelves keeps a two-unit item
        // like the Generator from going to zero on demo data alone.
        $equipment = DB::table('tbl_equipments')
            ->orderByDesc('available_quantity')
            ->get(['equipment_id', 'item_name', 'available_quantity'])
            ->all();

        if (count($residentIds) < 5 || count($equipment) < 3) {
            $this->command?->warn(
                'DemoBorrowingSeeder skipped: needs at least 5 residents and 3 equipment items, found '
                .count($residentIds).' resident(s) and '.count($equipment).' item(s). '
                .'Run ResidentSeeder and EquipmentSeeder first — this seeder never invents either.'
            );

            return;
        }

        $removed = self::purge($this->command);

        $rows = $this->buildRows($residentIds, $equipment);

        // Only Released holds units out. Returned released and took back, so it
        // nets to zero and must not be counted twice.
        $held = collect($rows)
            ->where('status', 'Released')
            ->whereNotNull('equipment_id')
            ->groupBy('equipment_id')
            ->map(fn ($group) => collect($group)->sum('quantity'));

        DB::transaction(function () use ($rows, $held) {
            DB::table('tbl_equipment_borrowing')->insert($rows);

            foreach ($held as $equipmentId => $quantity) {
                DB::table('tbl_equipments')
                    ->where('equipment_id', $equipmentId)
                    // Clamped at zero: this seeder must never itself become a
                    // source of the negative stock it exists to avoid.
                    ->update(['available_quantity' => DB::raw("GREATEST(0, available_quantity - {$quantity})")]);
            }
        });

        if ($removed > 0) {
            $this->command?->info("DemoBorrowingSeeder: replaced {$removed} row(s) from a previous run.");
        }

        $this->command?->info('DemoBorrowingSeeder: created '.count($rows).' borrowings, all marked '.self::MARKER.'.');

        foreach ($held as $equipmentId => $quantity) {
            $name = collect($equipment)->firstWhere('equipment_id', $equipmentId)?->item_name ?? "#{$equipmentId}";
            $this->command?->info("DemoBorrowingSeeder: available_quantity -{$quantity} on {$name} (units out on a demo Released loan).");
        }

        $this->command?->info('Undo with: php artisan db:seed --class=DemoBorrowingPurgeSeeder');
    }

    /**
     * Deletes every row this seeder made and gives back the stock its Released
     * rows were holding. Shared with DemoBorrowingPurgeSeeder so there is one
     * implementation of "undo" rather than two that can drift.
     *
     * Returns how many rows were removed.
     */
    public static function purge(?object $command = null): int
    {
        $marked = DB::table('tbl_equipment_borrowing')
            ->where('purpose', 'like', self::MARKER.'%')
            ->get(['borrow_id', 'equipment_id', 'quantity', 'status']);

        if ($marked->isEmpty()) {
            return 0;
        }

        $toRestore = $marked
            ->where('status', 'Released')
            ->whereNotNull('equipment_id')
            ->groupBy('equipment_id')
            ->map(fn ($group) => collect($group)->sum('quantity'));

        DB::transaction(function () use ($marked, $toRestore) {
            DB::table('tbl_equipment_borrowing')->whereIn('borrow_id', $marked->pluck('borrow_id'))->delete();

            foreach ($toRestore as $equipmentId => $quantity) {
                DB::table('tbl_equipments')
                    ->where('equipment_id', $equipmentId)
                    // Capped at total_quantity, because a demo row that someone
                    // moved Released -> Returned in the panel has already had
                    // its units added back by update(). Without the cap this
                    // would restore them a second time and put the shelf above
                    // what the office owns.
                    ->update(['available_quantity' => DB::raw("LEAST(total_quantity, available_quantity + {$quantity})")]);
            }
        });

        foreach ($toRestore as $equipmentId => $quantity) {
            $command?->info("DemoBorrowingSeeder: available_quantity +{$quantity} returned to equipment #{$equipmentId}.");
        }

        return $marked->count();
    }

    /**
     * Thirty rows: five per status, in the order the enum lists them.
     *
     * Every timestamp is derived from `created_at` by adding to it, never by
     * subtracting from now independently, so the ordering
     * created <= released <= returned can not come out backwards for any row.
     */
    private function buildRows(array $residentIds, array $equipment): array
    {
        $resident = fn (int $i) => $residentIds[$i % count($residentIds)];
        $item = fn (int $i) => $equipment[$i % count($equipment)]->equipment_id;

        // Released rows are the only ones that hold stock out, so they draw
        // from the three deepest shelves rather than the general rotation.
        // Sending them round the whole list put two units of a two-unit
        // Megaphone on demo loans and left the catalogue reading zero
        // available for it — true, but a poor thing to be looking at.
        $deepItem = fn (int $i) => $equipment[$i % min(3, count($equipment))]->equipment_id;

        $rows = [];
        $n = 0;

        // ------------------------------------------------------------ Pending
        // Nothing has been decided, so no due date and no timestamps beyond
        // filing. Two are uncatalogued.
        $pending = [
            ['days' => 1, 'qty' => 2, 'purpose' => 'Barangay flood drill this Saturday.', 'other' => null],
            ['days' => 2, 'qty' => 1, 'purpose' => 'Standby first aid for the fiesta parade.', 'other' => null],
            ['days' => 3, 'qty' => 4, 'purpose' => 'Evacuation centre setup ahead of the storm.', 'other' => null],
            ['days' => 2, 'qty' => 1, 'purpose' => 'Clearing fallen branches along the riverside road.', 'other' => 0],
            ['days' => 5, 'qty' => 1, 'purpose' => 'Power for the evacuation centre if the lines go down.', 'other' => 1],
        ];

        foreach ($pending as $i => $p) {
            $created = Carbon::now()->subDays($p['days'])->setTime(8 + $i, 15);
            $rows[] = $this->row($n, $resident($n), $p, $created, 'Pending', $item($n), null, null, null, null);
            $n++;
        }

        // ----------------------------------------------------------- Approved
        // Decided but not yet handed over: a due date exists, nothing has left
        // the building. One uncatalogued — the office can still consider it,
        // they simply cannot release it until the item is catalogued.
        $approved = [
            ['days' => 4, 'qty' => 1, 'purpose' => 'Medical standby at the inter-barangay basketball league.', 'other' => null],
            ['days' => 6, 'qty' => 2, 'purpose' => 'House-to-house health check in the upper puroks.', 'other' => null],
            ['days' => 3, 'qty' => 1, 'purpose' => 'Search training with the barangay tanod.', 'other' => null],
            ['days' => 7, 'qty' => 3, 'purpose' => 'Relief packing at the covered court.', 'other' => null],
            ['days' => 5, 'qty' => 1, 'purpose' => 'Pumping out the flooded ground floor of the health station.', 'other' => 3],
        ];

        foreach ($approved as $i => $p) {
            $created = Carbon::now()->subDays($p['days'])->setTime(9 + $i, 30);
            $due = (clone $created)->addDays(10);
            $rows[] = $this->row($n, $resident($n), $p, $created, 'Approved', $item($n), $due, null, null, null);
            $n++;
        }

        // ----------------------------------------------------------- Released
        // Out with the borrower now. released_at sits after created_at and the
        // due date is ahead of it. Never uncatalogued: update() refuses that
        // transition, so it is not a state the application can produce.
        $released = [
            ['days' => 9, 'qty' => 1, 'purpose' => 'Rescue standby during the river clearing operation.', 'other' => null],
            ['days' => 12, 'qty' => 2, 'purpose' => 'First aid post at the barangay health caravan.', 'other' => null],
            ['days' => 6, 'qty' => 1, 'purpose' => 'Transporting a bedridden patient to the district hospital.', 'other' => null],
            ['days' => 15, 'qty' => 2, 'purpose' => 'Debris clearing after the last typhoon.', 'other' => null],
            ['days' => 4, 'qty' => 1, 'purpose' => 'Crowd announcements at the evacuation centre.', 'other' => null],
        ];

        foreach ($released as $i => $p) {
            $created = Carbon::now()->subDays($p['days'])->setTime(7 + $i, 45);
            $releasedAt = (clone $created)->addHours(20);
            $due = (clone $releasedAt)->addDays(7);
            $rows[] = $this->row($n, $resident($n), $p, $created, 'Released', $deepItem($i), $due, $releasedAt, null, null);
            $n++;
        }

        // ----------------------------------------------------------- Returned
        // Finished loans: released, then back. returned_at is always after
        // released_at, and both are in the past.
        $returned = [
            ['days' => 30, 'qty' => 1, 'purpose' => 'Medical standby at the barangay assembly.', 'other' => null],
            ['days' => 24, 'qty' => 2, 'purpose' => 'First aid station at the school sports meet.', 'other' => null],
            ['days' => 40, 'qty' => 1, 'purpose' => 'Patient transfer during the dengue outbreak.', 'other' => null],
            ['days' => 18, 'qty' => 3, 'purpose' => 'Relief distribution at the covered court.', 'other' => null],
            ['days' => 21, 'qty' => 1, 'purpose' => 'Announcements during the flood advisory.', 'other' => null],
        ];

        foreach ($returned as $i => $p) {
            $created = Carbon::now()->subDays($p['days'])->setTime(8 + $i, 0);
            $releasedAt = (clone $created)->addHours(18);
            $due = (clone $releasedAt)->addDays(7);
            $returnedAt = (clone $releasedAt)->addDays(5)->addHours(3);
            $rows[] = $this->row($n, $resident($n), $p, $created, 'Returned', $item($n), $due, $releasedAt, $returnedAt, null);
            $n++;
        }

        // ------------------------------------------------------------- Denied
        // Refused with a reason the resident is shown, so each one says what
        // would make a future request succeed. One uncatalogued.
        $denied = [
            ['days' => 11, 'qty' => 2, 'purpose' => 'Personal use at a family reunion.', 'other' => null,
                'reason' => 'Reserved for emergency response. File again for a barangay activity.'],
            ['days' => 8, 'qty' => 5, 'purpose' => 'Standby for a private construction crew.', 'other' => null,
                'reason' => 'Quantity exceeds what the office can lend at once — request up to two.'],
            ['days' => 14, 'qty' => 1, 'purpose' => 'Overnight use with no named contact person.', 'other' => null,
                'reason' => 'No contact person named. Add one and file again.'],
            ['days' => 6, 'qty' => 1, 'purpose' => 'Requested for the same week as the municipal drill.', 'other' => null,
                'reason' => 'All units are committed to the flood drill that week.'],
            ['days' => 9, 'qty' => 1, 'purpose' => 'Night search along the riverbank.', 'other' => 4,
                'reason' => 'MDRRMO does not carry this item. Logged for procurement.'],
        ];

        foreach ($denied as $i => $p) {
            $created = Carbon::now()->subDays($p['days'])->setTime(10 + $i, 20);
            $rows[] = $this->row($n, $resident($n), $p, $created, 'Denied', $item($n), null, null, null, $p['reason']);
            $n++;
        }

        // ---------------------------------------------------------- Cancelled
        // Withdrawn by the resident before pickup. No denial_reason: nobody
        // refused these, and cancel() writes none.
        $cancelled = [
            ['days' => 5, 'qty' => 1, 'purpose' => 'Activity moved to next month.', 'other' => null],
            ['days' => 10, 'qty' => 2, 'purpose' => 'Borrowed from the parish instead.', 'other' => null],
            ['days' => 13, 'qty' => 1, 'purpose' => 'Training postponed by the weather.', 'other' => null],
            ['days' => 7, 'qty' => 1, 'purpose' => 'Sourced from the barangay office instead.', 'other' => null],
            ['days' => 16, 'qty' => 1, 'purpose' => 'Clean-up drive called off.', 'other' => 5],
        ];

        foreach ($cancelled as $i => $p) {
            $created = Carbon::now()->subDays($p['days'])->setTime(11 + $i, 5);
            $rows[] = $this->row($n, $resident($n), $p, $created, 'Cancelled', $item($n), null, null, null, null);
            $n++;
        }

        return $rows;
    }

    /**
     * One insert row.
     *
     * The item source is the part to read carefully: `$other` is an index into
     * UNCATALOGUED or null, and exactly one of the two columns is ever set —
     * `equipment_id` goes null the moment there is free text, which is what
     * chk_equipment_borrowing_item_source requires.
     *
     * Fulfilment and borrower type alternate off the row's position so the
     * board shows a mix without a random seed making two runs disagree.
     */
    private function row(
        int $n,
        int $residentId,
        array $spec,
        Carbon $created,
        string $status,
        int $equipmentId,
        ?Carbon $due,
        ?Carbon $releasedAt,
        ?Carbon $returnedAt,
        ?string $denialReason,
    ): array {
        $uncatalogued = $spec['other'] !== null;
        $isDelivery = $n % 3 === 0;
        $isOrganization = $n % 4 === 0;

        return [
            'resident_id' => $residentId,
            'equipment_id' => $uncatalogued ? null : $equipmentId,
            'other_equipment_text' => $uncatalogued ? self::UNCATALOGUED[$spec['other']] : null,
            'quantity' => $spec['qty'],
            'purpose' => self::MARKER.' '.$spec['purpose'],
            'fulfillment_method' => $isDelivery ? 'Delivery' : 'Pickup',
            // Only ever set on a Delivery: the column is dropped on a Pickup by
            // both the controller and the app, so a seeded address on one would
            // be a state the application never writes.
            'delivery_address' => $isDelivery ? self::ADDRESSES[$n % count(self::ADDRESSES)] : null,
            'borrower_type' => $isOrganization ? 'Organization' : 'Resident',
            'organization_name' => $isOrganization ? self::ORGANIZATIONS[$n % count(self::ORGANIZATIONS)] : null,
            'due_date' => $due?->toDateString(),
            'status' => $status,
            'denial_reason' => $denialReason,
            'released_at' => $releasedAt,
            'returned_at' => $returnedAt,
            'created_at' => $created,
            'updated_at' => $returnedAt ?? $releasedAt ?? $created,
        ];
    }
}
