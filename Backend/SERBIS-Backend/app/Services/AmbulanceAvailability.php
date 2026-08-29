<?php

namespace App\Services;

use App\Http\Controllers\ServiceRequestController;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     */
    public function availableAmbulances(Carbon $start, Carbon $end): Collection
    {
        return Vehicle::query()
            ->where('type', 'Ambulance')
            ->where('status', '!=', 'Maintenance')
            ->where('status', '!=', 'Dispatched')
            ->whereDoesntHave('serviceRequests', function (Builder $query) use ($start, $end): void {
                $query->whereNotIn('status', ServiceRequestController::TERMINAL_STATUSES)
                    ->where('scheduled_at', '<', $end)
                    ->where('scheduled_end', '>', $start);
            })
            ->get();
    }
}
