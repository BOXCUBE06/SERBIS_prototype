<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'type' => 'sometimes|required|in:Ambulance,Rescue Vehicle,Fire Truck,Boat',
            'specification' => 'nullable|string',
            'status' => 'sometimes|required|in:Available,Dispatched,Maintenance',
        ]);

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