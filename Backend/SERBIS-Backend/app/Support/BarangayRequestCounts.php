<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Request counts per barangay, for the dashboard choropleth and for the
 * analytics page's totals line.
 *
 * Both surfaces call this rather than writing their own join. The previous
 * dashboard-only version inner-joined through tbl_residents, which silently
 * dropped every walk-in request: tbl_service_request.resident_id is nullable
 * and is null for a request filed at the counter, so an inner join removed
 * the row entirely rather than bucketing it. On local data that hid 20 of 50
 * requests — the choropleth's totals were 40% short of the real count and
 * nothing on the page said so.
 *
 * The fix is a LEFT JOIN plus an explicit unplaced bucket. A walk-in carries
 * no barangay anywhere in the schema, so it cannot be put on the map; it is
 * reported as its own line instead, which is what makes the section's total
 * reconcile against the raw row count.
 */
class BarangayRequestCounts
{
    /**
     * Service requests and equipment loans are counted together — the
     * dashboard's map has always shown combined demand, and splitting them
     * here would change what the existing card means.
     */
    private const TABLES = ['tbl_service_request', 'tbl_equipment_borrowing'];

    /**
     * @return array{barangays: list<array{name: string, requests: int}>, walkIn: int, total: int}
     *
     * `total` is barangays + walkIn and is asserted in
     * BarangayWalkInReconciliationTest to equal the raw row count for the
     * window. Any future join that drops rows breaks that assertion.
     */
    public static function forWindow(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $placed = [];
        $walkIn = 0;

        foreach (self::TABLES as $table) {
            foreach (self::countByBarangay($table, $since, $until) as $row) {
                // LEFT JOIN misses land here: a request with no resident, or
                // (defensively) a resident whose barangay row has gone.
                if ($row->name === null) {
                    $walkIn += (int) $row->total;

                    continue;
                }

                $placed[$row->name] = ($placed[$row->name] ?? 0) + (int) $row->total;
            }
        }

        arsort($placed);

        $barangays = [];
        foreach ($placed as $name => $requests) {
            $barangays[] = ['name' => $name, 'requests' => $requests];
        }

        return [
            'barangays' => $barangays,
            'walkIn' => $walkIn,
            'total' => array_sum($placed) + $walkIn,
        ];
    }

    /**
     * DB::table rather than the Eloquent model: ServiceRequest sets
     * `protected $with = ['ambulanceBooking']`, which fires an eager load on
     * every ->get() — pointless against an aggregate, and it would hydrate
     * models this never reads.
     *
     * `$until` is exclusive so a caller can pass a period's own end boundary
     * without double-counting the row that lands exactly on it.
     */
    private static function countByBarangay(string $table, ?CarbonInterface $since, ?CarbonInterface $until)
    {
        $query = DB::table($table);

        // A service request carries the barangay it was filed under. A loan
        // still follows the resident's current barangay (accepted mismatch).
        if ($table === 'tbl_service_request') {
            $query->leftJoin('tbl_barangay', "{$table}.barangay_id", '=', 'tbl_barangay.barangay_id');
        } else {
            $query->leftJoin('tbl_residents', "{$table}.resident_id", '=', 'tbl_residents.resident_id')
                ->leftJoin('tbl_barangay', 'tbl_residents.barangay_id', '=', 'tbl_barangay.barangay_id');
        }

        return $query
            ->when($since, fn ($q) => $q->where("{$table}.created_at", '>=', $since))
            ->when($until, fn ($q) => $q->where("{$table}.created_at", '<', $until))
            ->groupBy('tbl_barangay.barangay_name')
            ->selectRaw('tbl_barangay.barangay_name as name, COUNT(*) as total')
            ->get();
    }
}
