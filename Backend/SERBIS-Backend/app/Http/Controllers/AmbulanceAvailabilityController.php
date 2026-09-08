<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\Vehicle;
use App\Services\AmbulanceAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Read-only, shared between residents (the booking picker) and admins (the
 * scheduling calendar) — both sit behind plain auth:sanctum, no is.admin.
 *
 * Neither shape below ever serialises a ServiceRequest's patient_name,
 * medical_diagnosis-adjacent `description`, `remarks`, or resident/walk-in
 * identity: a resident booking the picker must not learn who else has the
 * ambulance that day. Only the time range and the unit it belongs to leave
 * this controller.
 */
class AmbulanceAvailabilityController extends Controller
{
    /**
     * The wall clock `date`, `start` and `end` are typed against. Duplicated
     * from ConductionRequestController::OFFICE_TIMEZONE rather than shared —
     * both are the same fixed IANA name, not a business rule that could drift.
     */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    public function __construct(private readonly AmbulanceAvailability $availability) {}

    public function index(Request $request)
    {
        if ($request->filled('date')) {
            return $this->byDate($request);
        }

        if ($request->filled('start') || $request->filled('end')) {
            return $this->byWindow($request);
        }

        throw ValidationException::withMessages([
            'date' => 'Provide either a date, or both start and end.',
        ]);
    }

    /**
     * Every Ambulance unit for the given Manila calendar day, each with its
     * booked windows that day and whether it is under Maintenance. Not a
     * free/not-free verdict — the calendar renders the windows itself.
     */
    private function byDate(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $dayStart = Carbon::createFromFormat('Y-m-d', $validated['date'], self::OFFICE_TIMEZONE)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        $dayStartUtc = $dayStart->copy()->utc();
        $dayEndUtc = $dayEnd->copy()->utc();

        $vehicles = Vehicle::query()
            ->where('type', 'Ambulance')
            ->orderBy('unit_identifier')
            ->with(['serviceRequests' => function ($query) use ($dayStartUtc, $dayEndUtc) {
                $query->whereNotIn('status', ServiceRequestController::TERMINAL_STATUSES)
                    ->whereNotNull('scheduled_at')
                    ->whereNotNull('scheduled_end')
                    // Same half-open overlap test as AmbulanceAvailability: a
                    // booking touching the day boundary only is adjacent, not
                    // shown as booked on this day.
                    ->where('scheduled_at', '<', $dayEndUtc)
                    ->where('scheduled_end', '>', $dayStartUtc)
                    ->orderBy('scheduled_at');
            }])
            ->get();

        return response()->json($vehicles->map(fn (Vehicle $vehicle) => [
            'vehicle_id' => $vehicle->vehicle_id,
            'unit_identifier' => $vehicle->unit_identifier,
            'specification' => $vehicle->specification,
            'is_maintenance' => $vehicle->status === 'Maintenance',
            'booked_windows' => $vehicle->serviceRequests->map(fn (ServiceRequest $bookedRequest) => [
                'scheduled_at' => $bookedRequest->scheduled_at,
                'scheduled_end' => $bookedRequest->scheduled_end,
            ])->values(),
        ])->values());
    }

    /** Units free for the exact [start, end) window — delegates the verdict entirely to the service. */
    private function byWindow(Request $request)
    {
        $validated = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after:start',
        ]);

        $start = Carbon::parse($validated['start'], self::OFFICE_TIMEZONE)->utc();
        $end = Carbon::parse($validated['end'], self::OFFICE_TIMEZONE)->utc();

        $vehicles = $this->availability->availableAmbulances($start, $end);

        return response()->json($vehicles->map(fn (Vehicle $vehicle) => [
            'vehicle_id' => $vehicle->vehicle_id,
            'unit_identifier' => $vehicle->unit_identifier,
            'specification' => $vehicle->specification,
        ])->values());
    }
}
