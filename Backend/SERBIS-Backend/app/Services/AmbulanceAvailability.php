<?php

namespace App\Services;

use App\Http\Controllers\ServiceRequestController;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for "which ambulances are free between two
 * instants". Three callers need this answer — the booking form's picker, the
 * booking write path's own re-check, and the availability endpoint — and all
 * three must agree, so none of them may reimplement the overlap test.
 *
 * All times taken and compared here are UTC instants. Any Manila-wall-clock
 * conversion happens at the controller boundary, before a Carbon instance
 * ever reaches this class.
 */
class AmbulanceAvailability
{
    /**
     * A unit is unavailable for [$start, $end) if either is true:
     *
     *   - its own status is 'Dispatched' — out on a trip of unknown duration,
     *     scheduled or not, so no window can clear it;
     *   - it has an active (non-terminal) booking whose own window overlaps
     *     the requested one, using the standard half-open overlap test
     *     `existing.start < new.end AND existing.end > new.start`. A booking
     *     touching the window only at a boundary (existing.end == new.start,
     *     or the reverse) is adjacent, not overlapping, and does not exclude
     *     the unit.
     *
     * A booking with no `scheduled_at` has no window to overlap with anything
     * — it is excluded from this comparison by construction, not by an extra
     * null check: `NULL < $end` and `NULL > $start` are both false in SQL, so
     * such a row can never satisfy the overlap. A unit out on that kind of
     * trip is still caught by the 'Dispatched' rule above.
     *
     * `scheduled_end` itself is null on every Booked request until approve()
     * sets a real one — store()/adminStore() only ever write `scheduled_at`.
     * The same `NULL > $start` fact above meant an unapproved booking held no
     * window at all: two residents could both be told a unit was free for a
     * slot one of them had already (unapproved) claimed. Derived here with
     * `COALESCE(...DATE_ADD(scheduled_at, INTERVAL DEFAULT_BOOKING_HOURS
     * HOUR))` rather than backfilling the column, so an unapproved booking
     * reserves the same default window approve() itself falls back to, and
     * the two can never disagree.
     *
     * `$excludeServiceRequestId` is for rescheduling a booking that already
     * holds a unit: without it, the booking's own not-yet-updated row would
     * count as a conflict against itself the moment the new window is checked
     * against the old one still on record. The two other callers — filing a
     * new request, and the read-only endpoint — never have a row to exclude.
     */
    public function availableAmbulances(Carbon $start, Carbon $end, ?int $excludeServiceRequestId = null): Collection
    {
        return Vehicle::query()
            ->where('type', 'Ambulance')
            ->where('status', '!=', 'Maintenance')
            ->where('status', '!=', 'Dispatched')
            ->whereDoesntHave('serviceRequests', function (Builder $query) use ($start, $end, $excludeServiceRequestId): void {
                // whereDoesntHave() defaults this subquery's SELECT to *,
                // which after the join below would pull in
                // tbl_ambulance_bookings' own request_id/created_at/updated_at
                // alongside tbl_service_request's — harmless for a NOT EXISTS
                // (MySQL never materialises the columns), but not a pattern to
                // leave standing. One real, qualified column is enough for an
                // existence check.
                $query->select('tbl_service_request.request_id')
                    ->join('tbl_ambulance_bookings', 'tbl_ambulance_bookings.request_id', '=', 'tbl_service_request.request_id')
                    ->whereNotIn('tbl_service_request.status', ServiceRequestController::TERMINAL_STATUSES)
                    ->where('tbl_ambulance_bookings.scheduled_at', '<', $end)
                    ->whereRaw(self::windowEnd('tbl_ambulance_bookings').' > ?', [$start->toDateTimeString()])
                    ->when($excludeServiceRequestId, fn (Builder $q) => $q->where('tbl_service_request.request_id', '!=', $excludeServiceRequestId));
            })
            ->get();
    }

    /**
     * Active bookings that share a unit with another active booking whose
     * window overlaps theirs, limited to bookings starting in [$from, $to).
     * The write path should never let this happen; the dashboard flags it
     * when it does. Same half-open test and default window as above.
     */
    public function conflictingRequestIds(Carbon $from, Carbon $to): array
    {
        return DB::table('tbl_ambulance_bookings as a')
            ->join('tbl_service_request as ra', 'ra.request_id', '=', 'a.request_id')
            ->join('tbl_service_request as rb', fn ($join) => $join->on('rb.vehicle_id', '=', 'ra.vehicle_id')->on('rb.request_id', '!=', 'ra.request_id'))
            ->join('tbl_ambulance_bookings as b', 'b.request_id', '=', 'rb.request_id')
            ->whereNotIn('ra.status', ServiceRequestController::TERMINAL_STATUSES)
            ->whereNotIn('rb.status', ServiceRequestController::TERMINAL_STATUSES)
            ->whereRaw('b.scheduled_at < '.self::windowEnd('a'))
            ->whereRaw(self::windowEnd('b').' > a.scheduled_at')
            ->where('a.scheduled_at', '>=', $from)
            ->where('a.scheduled_at', '<', $to)
            ->distinct()
            ->pluck('a.request_id')
            ->all();
    }

    /** A booking's end: scheduled_end, or the default window approve() falls back to. */
    private static function windowEnd(string $table): string
    {
        return sprintf('COALESCE(%1$s.scheduled_end, DATE_ADD(%1$s.scheduled_at, INTERVAL %2$d HOUR))', $table, ServiceRequestController::DEFAULT_BOOKING_HOURS);
    }
}
