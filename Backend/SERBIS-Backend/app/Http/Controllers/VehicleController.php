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
        $validated = $request->validate([
            'unit_identifier' => 'required|unique:tbl_vehicles,unit_identifier',
            'plate_no' => 'nullable|string|max:32',
            'type' => 'required|in:Ambulance,Rescue Vehicle,Fire Truck,Boat',
            'specification' => 'nullable|string',
            'status' => 'required|in:Available,Dispatched,Maintenance',
        ]);

        $vehicle = Vehicle::create($validated);

        return response()->json($vehicle, 201);
    }

    public function show($id)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        return response()->json($vehicle);
    }

    public function update(Request $request, $id)
    {
        $vehicle = Vehicle::find($id);
        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        $validated = $request->validate([
            'unit_identifier' => [
                'sometimes',
                'required',
                Rule::unique('tbl_vehicles')->ignore($vehicle->vehicle_id, 'vehicle_id')
            ],
            'plate_no' => 'nullable|string|max:32',
            'type' => 'sometimes|required|in:Ambulance,Rescue Vehicle,Fire Truck,Boat',
            'specification' => 'nullable|string',
            'status' => 'sometimes|required|in:Available,Dispatched,Maintenance',
        ]);

        // A unit going into Maintenance is going away for an unknown length of
        // time. Nothing here reconciles that against a booking already resting
        // on this exact unit for a specific future window — an admin flipping
        // the status alone would silently orphan every one of them. No force
        // flag: reassigning those bookings is a decision for a person, made
        // with the list below in hand, not a checkbox that skips past it.
        if (($validated['status'] ?? null) === 'Maintenance' && $vehicle->status !== 'Maintenance') {
            $futureBookings = ServiceRequest::where('vehicle_id', $vehicle->vehicle_id)
                ->where('status', 'Booked')
                ->where('scheduled_at', '>', now())
                ->orderBy('scheduled_at')
                ->get(['request_id', 'scheduled_at']);

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
        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        $vehicle->delete();

        return response()->json(['message' => 'Vehicle successfully deleted']);
    }
}