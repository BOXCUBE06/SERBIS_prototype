<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Ambulance schedule popup: every unit, and every trip that touches a range
 * of days, in one read. Admin only (section:ambulance) because it names patients;
 * ambulance-availability is the resident-safe sibling and stays as it is.
 *
 * A trip starts when the unit was dispatched, once it has been; before that at the
 * booking's scheduled_at, else when the request was filed. It lasts as long as it
 * was booked for (scheduled_at to scheduled_end, set on approval), or
 * DEFAULT_BOOKING_HOURS when there is no such window, flagged in end_is_default.
 * Cancelled and Disapproved never appear.
 *
 * Open work comes back whatever the range: every Responding trip (a unit out now),
 * and every request still waiting with no time and no unit (`waiting`), which the
 * popup carries forward onto today.
 */
class AmbulanceScheduleController extends Controller
{
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    /** A 6-week month grid plus a day either side. */
    private const MAX_DAYS = 62;

    private const SHOWN = ['Pending', 'Booked', 'Responding', 'Resolved'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        $from = Carbon::createFromFormat('Y-m-d', $validated['from'], self::OFFICE_TIMEZONE)->startOfDay();
        $until = Carbon::createFromFormat('Y-m-d', $validated['to'], self::OFFICE_TIMEZONE)->startOfDay()->addDay();

        if ($from->diffInDays($until) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Ask for at most '.self::MAX_DAYS.' days at a time.']);
        }

        $dispatched = "r.status IN ('Responding', 'Resolved') AND r.first_responded_at IS NOT NULL";
        $start = "CASE WHEN {$dispatched} THEN r.first_responded_at ELSE COALESCE(b.scheduled_at, r.created_at) END";
        $minutes = 'COALESCE(TIMESTAMPDIFF(MINUTE, b.scheduled_at, b.scheduled_end), '.(ServiceRequestController::DEFAULT_BOOKING_HOURS * 60).')';
        $end = "DATE_ADD({$start}, INTERVAL {$minutes} MINUTE)";
        $waiting = "r.status = 'Pending' AND b.scheduled_at IS NULL AND r.vehicle_id IS NULL";

        $trips = DB::table('tbl_service_request as r')
            ->join('tbl_ambulance_bookings as b', 'b.request_id', '=', 'r.request_id')
            ->leftJoin('tbl_residents as res', 'res.resident_id', '=', 'r.resident_id')
            ->leftJoin('tbl_barangay as brg', 'brg.barangay_id', '=', 'r.barangay_id')
            ->whereIn('r.status', self::SHOWN)
            ->where(fn ($q) => $q
                ->where(fn ($inRange) => $inRange
                    ->whereRaw("{$start} < ?", [$until->copy()->utc()->toDateTimeString()])
                    ->whereRaw("{$end} > ?", [$from->copy()->utc()->toDateTimeString()]))
                ->orWhere('r.status', 'Responding')
                ->orWhereRaw($waiting))
            ->orderByRaw($start)
            ->selectRaw("r.request_id, r.status, r.vehicle_id, {$start} as starts_at, {$end} as ends_at, b.scheduled_end is null as end_is_default,
                {$waiting} as waiting, b.patient_name, b.pickup_location, r.walk_in_name, res.first_name, res.last_name, brg.barangay_name")
            ->get()
            ->map(fn ($row) => [
                'request_id' => $row->request_id,
                'status' => $row->status,
                'vehicle_id' => $row->vehicle_id,
                'starts_at' => Carbon::parse($row->starts_at, 'UTC')->toIso8601String(),
                'ends_at' => Carbon::parse($row->ends_at, 'UTC')->toIso8601String(),
                'end_is_default' => (bool) $row->end_is_default,
                'waiting' => (bool) $row->waiting,
                'patient_name' => $row->patient_name,
                'requested_by' => $row->walk_in_name ?: trim($row->first_name.' '.$row->last_name),
                'from' => $row->barangay_name ?: $row->pickup_location,
            ]);

        return response()->json([
            'units' => Vehicle::where('type', 'Ambulance')->orderBy('unit_identifier')->get()->map(fn (Vehicle $unit) => [
                'vehicle_id' => $unit->vehicle_id,
                'unit_identifier' => $unit->unit_identifier,
                'specification' => $unit->specification,
                'is_maintenance' => $unit->status === 'Maintenance',
            ])->values(),
            'trips' => $trips->values(),
        ]);
    }
}
