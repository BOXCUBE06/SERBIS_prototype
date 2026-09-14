<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function index()
    {
        return response()->json(Vehicle::all());
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
            'type' => 'required|in:Ambulance,Rescue Vehicle,Fire Truck,Boat',
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
            'type' => 'sometimes|required|in:Ambulance,Rescue Vehicle,Fire Truck,Boat',
            'specification' => 'nullable|string|max:255',
            'status' => 'sometimes|required|in:Available,Dispatched,Maintenance',
        ]);

        // A unit going into Maintenance is going away for an unknown length of
        // time. Nothing here reconciles that against a booking already resting
        // on this exact unit for a specific future window — an admin flipping
        // the status alone would silently orphan every one of them. No force
        // flag: reassigning those bookings is a decision for a person, made
        // with the list below in hand, not a checkbox that skips past it.
        if (($validated['status'] ?? null) === 'Maintenance' && $vehicle->status !== 'Maintenance') {
            // Joined: scheduled_at now lives on tbl_ambulance_bookings.
            // Selected under its plain name so it hydrates onto the model
            // as the usual `scheduled_at` attribute, cast to Carbon as always.
            $futureBookings = ServiceRequest::query()
                ->join('tbl_ambulance_bookings', 'tbl_ambulance_bookings.request_id', '=', 'tbl_service_request.request_id')
                ->where('tbl_service_request.vehicle_id', $vehicle->vehicle_id)
                ->where('tbl_service_request.status', 'Booked')
                ->where('tbl_ambulance_bookings.scheduled_at', '>', now())
                ->orderBy('tbl_ambulance_bookings.scheduled_at')
                ->get(['tbl_service_request.request_id', 'tbl_ambulance_bookings.scheduled_at']);

            if ($futureBookings->isNotEmpty()) {
                $names = $futureBookings
                    ->map(fn (ServiceRequest $r) => "#{$r->request_id} ({$r->scheduled_at->toIso8601String()})")
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'status' => "Cannot set this unit to Maintenance: it still holds future booked requests — {$names}.",
                ]);
            }
        }

        $vehicle->update($validated);

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
