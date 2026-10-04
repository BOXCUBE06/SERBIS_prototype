<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Service requests per barangay, and equipment loans per barangay, for the
 * analytics page's Barangays tab.
 *
 * The two are kept apart: a loan is not a request, and a loan has no
 * walk-in form (its borrower always has an account). Requests are LEFT JOINed
 * to the barangay roster. The first version of this inner-joined through
 * tbl_residents, which silently dropped every walk-in request:
 * tbl_service_request.resident_id is nullable and is null for a request filed
 * at the counter, so an inner join removed the row entirely rather than
 * bucketing it. A walk-in carries no barangay anywhere in the schema, so it
 * cannot be placed on a barangay; it is reported as its own count, which is
 * what makes the section's total reconcile against the raw row count.
 */
class BarangayRequestCounts
{
    /**
     * Service requests per barangay, plus the ones with no barangay.
     *
     * Optional filters narrow the requests the same way the page's filters do;
     * a barangay filter leaves no walk-ins, since a walk-in has no barangay.
     *
     * @return array{barangays: list<array{id: int, name: string, requests: int}>, walkIn: int, total: int}
     *
     * `total` is barangays + walkIn and is asserted in
     * BarangayWalkInReconciliationTest to equal the raw request count for the
     * window. Any future join that drops rows breaks that assertion.
     *
     * DB::table rather than the Eloquent model: ServiceRequest sets
     * `protected $with = ['ambulanceBooking']`, which fires an eager load on
     * every ->get() — pointless against an aggregate.
     *
     * `$until` is exclusive so a caller can pass a period's own end boundary
     * without double-counting the row that lands exactly on it.
     */
    public static function forWindow(?CarbonInterface $since = null, ?CarbonInterface $until = null, ?int $barangayId = null, ?int $serviceId = null): array
    {
        $rows = DB::table('tbl_service_request')
            ->leftJoin('tbl_barangay', 'tbl_service_request.barangay_id', '=', 'tbl_barangay.barangay_id')
            ->when($since, fn ($q) => $q->where('tbl_service_request.created_at', '>=', $since))
            ->when($until, fn ($q) => $q->where('tbl_service_request.created_at', '<', $until))
            ->when($barangayId, fn ($q) => $q->where('tbl_service_request.barangay_id', $barangayId))
            ->when($serviceId, fn ($q) => $q->where('tbl_service_request.service_id', $serviceId))
            ->groupBy('tbl_service_request.barangay_id', 'tbl_barangay.barangay_name')
            ->selectRaw('tbl_service_request.barangay_id as id, tbl_barangay.barangay_name as name, COUNT(*) as total')
            ->get();

        $barangays = [];
        $walkIn = 0;

        foreach ($rows as $row) {
            // LEFT JOIN misses land here: a request with no barangay, or
            // (defensively) one whose barangay row has gone.
            if ($row->name === null) {
                $walkIn += (int) $row->total;

                continue;
            }

            $barangays[] = ['id' => (int) $row->id, 'name' => $row->name, 'requests' => (int) $row->total];
        }

        usort($barangays, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        return [
            'barangays' => $barangays,
            'walkIn' => $walkIn,
            'total' => array_sum(array_column($barangays, 'requests')) + $walkIn,
        ];
    }

    /**
     * Equipment loans per barangay id, by the borrower's current barangay
     * (a loan records none of its own; accepted mismatch).
     *
     * @return array<int, int>
     */
    public static function loansByBarangay(?CarbonInterface $since = null, ?CarbonInterface $until = null, ?int $barangayId = null): array
    {
        return DB::table('tbl_equipment_borrowing')
            ->join('tbl_residents', 'tbl_equipment_borrowing.resident_id', '=', 'tbl_residents.resident_id')
            ->when($since, fn ($q) => $q->where('tbl_equipment_borrowing.created_at', '>=', $since))
            ->when($until, fn ($q) => $q->where('tbl_equipment_borrowing.created_at', '<', $until))
            ->when($barangayId, fn ($q) => $q->where('tbl_residents.barangay_id', $barangayId))
            ->groupBy('tbl_residents.barangay_id')
            ->selectRaw('tbl_residents.barangay_id as id, COUNT(*) as total')
            ->pluck('total', 'id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
