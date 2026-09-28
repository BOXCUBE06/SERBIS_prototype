<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    /**
     * Every Ambulance unit's future Booked bookings, one query grouped in PHP
     * by vehicle_id — what index() attaches to each row so the Vehicles page
     * already has the conflict list before a status change is even picked,
     * without a per-vehicle query or a call to a route the page may not hold
     * the section for (see futureBookedBookings() below for the single-unit
     * version the Maintenance guard and update() use).
     */
    private function futureBookedBookingsByVehicle(): Collection
    {
        return ServiceRequest::query()
            ->join('tbl_ambulance_bookings', 'tbl_ambulance_bookings.request_id', '=', 'tbl_service_request.request_id')
            ->whereNotNull('tbl_service_request.vehicle_id')
            ->where('tbl_service_request.status', 'Booked')
            ->where('tbl_ambulance_bookings.scheduled_at', '>', now())
            ->orderBy('tbl_ambulance_bookings.scheduled_at')
            ->get([
                'tbl_service_request.request_id',
                'tbl_service_request.vehicle_id',
                'tbl_ambulance_bookings.scheduled_at',
                'tbl_ambulance_bookings.patient_name',
            ])
            ->groupBy('vehicle_id');
    }

    /**
     * One vehicle's slice of the query above — the Maintenance guard and
     * update()'s response share this. Runs the same batched query rather
     * than a lighter single-vehicle one; both callers fire at most once per
     * request and the fleet is small, so the extra rows cost nothing worth
     * a second query to avoid.
     */
    private function futureBookedBookings(Vehicle $vehicle): Collection
    {
        return $this->futureBookedBookingsByVehicle()->get($vehicle->vehicle_id) ?? collect();
    }

    private function conflictPayload(Collection $bookings): Collection
    {
        return $bookings->map(fn (ServiceRequest $r) => [
            'request_id' => $r->request_id,
            'scheduled_at' => $r->scheduled_at,
            'patient_name' => $r->patient_name,
        ])->values();
    }

    public function index()
    {
        $vehicles = Vehicle::all();
        $conflicts = $this->futureBookedBookingsByVehicle();

        $vehicles->each(function (Vehicle $v) use ($conflicts) {
            $v->setAttribute('conflicting_bookings', $this->conflictPayload($conflicts->get($v->vehicle_id) ?? collect()));
        });

        return response()->json($vehicles);
    }

    public function store(Request $request)
    {
        // Both string columns are varchar(255). Without the ceilings the
        // validator passes an over-length value straight to MySQL, which is in
        // strict mode and answers error 1406 — a 500 where the admin should
        // have been shown a 422. `unit_identifier` also lacked `string`
        // entirely, so an array reached the unique rule.
        $validated = $request->validate([
            'unit_identifier' => 'required|string|max:255|unique:tbl_vehicles,unit_identifier',
            'type' => ['required', Rule::in(Vehicle::TYPES)],
            'specification' => 'nullable|string|max:255',
            'status' => 'required|in:Available,Dispatched,Maintenance',
        ]);

        $vehicle = Vehicle::create($validated);

        return response()->json($vehicle, 201);
    }

    public function show($id)
    {
        $vehicle = Vehicle::find($id);

        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        return response()->json($vehicle);
    }

    public function update(Request $request, $id)
    {
        $vehicle = Vehicle::find($id);
        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        $validated = $request->validate([
            'unit_identifier' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('tbl_vehicles')->ignore($vehicle->vehicle_id, 'vehicle_id'),
            ],
            'type' => ['sometimes', 'required', Rule::in(Vehicle::TYPES)],
            'specification' => 'nullable|string|max:255',
            'status' => 'sometimes|required|in:Available,Dispatched,Maintenance',
        ]);

        // A unit going into Maintenance is going away for an unknown length of
        // time. Nothing here reconciles that against a booking already resting
        // on this exact unit for a specific future window — an admin flipping
        // the status alone would silently orphan every one of them. No force
        // flag: reassigning those bookings is a decision for a person, made
        // with the list below in hand, not a checkbox that skips past it.
        //
        // Any OTHER status (in practice, just Dispatched — a unit pulled for a
        // real emergency) does not block the same way: the change goes through
        // and the conflict list rides along on the response instead, for the
        // panel to act on (reassign the affected bookings) rather than being
        // stopped from recording where the unit actually is.
        $futureBookings = $this->futureBookedBookings($vehicle);

        if (($validated['status'] ?? null) === 'Maintenance' && $vehicle->status !== 'Maintenance' && $futureBookings->isNotEmpty()) {
            $names = $futureBookings
                ->map(fn (ServiceRequest $r) => "#{$r->request_id} ({$r->scheduled_at->toIso8601String()})")
                ->implode(', ');

            throw ValidationException::withMessages([
                'status' => "Cannot set this unit to Maintenance: it still holds future booked requests — {$names}.",
            ]);
        }

        $vehicle->update($validated);
        $vehicle->setAttribute('conflicting_bookings', $this->conflictPayload($futureBookings));

        return response()->json($vehicle);
    }

    public function destroy($id)
    {
        $vehicle = Vehicle::find($id);
        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        // Deleting a unit is the harder version of the Maintenance flip above:
        // that one takes the unit away for an unknown length of time, this one
        // takes it away permanently and drops the row every request points at.
        // Same reasoning, so the same query decides it, widened from 'Booked'
        // to the whole non-terminal set — a Pending or Responding request on
        // this unit is live work, and a delete would strand it just as badly.
        if ($vehicle->status === 'Dispatched') {
            return response()->json([
                'message' => 'Cannot delete this unit: it is currently Dispatched.',
            ], 422);
        }

        // Joined: scheduled_at now lives on tbl_ambulance_bookings. Same
        // select-under-its-plain-name approach as the Maintenance guard above.
        $futureRequests = ServiceRequest::query()
            ->join('tbl_ambulance_bookings', 'tbl_ambulance_bookings.request_id', '=', 'tbl_service_request.request_id')
            ->where('tbl_service_request.vehicle_id', $vehicle->vehicle_id)
            ->whereNotIn('tbl_service_request.status', ServiceRequestController::TERMINAL_STATUSES)
            ->where('tbl_ambulance_bookings.scheduled_at', '>', now())
            ->orderBy('tbl_ambulance_bookings.scheduled_at')
            ->get(['tbl_service_request.request_id', 'tbl_ambulance_bookings.scheduled_at']);

        if ($futureRequests->isNotEmpty()) {
            $names = $futureRequests
                ->map(fn (ServiceRequest $r) => "#{$r->request_id} ({$r->scheduled_at->toIso8601String()})")
                ->implode(', ');

            return response()->json([
                'message' => "Cannot delete this unit: it still holds future booked requests — {$names}.",
            ], 422);
        }

        $vehicle->delete();

        return response()->json(['message' => 'Vehicle successfully deleted']);
    }
}
