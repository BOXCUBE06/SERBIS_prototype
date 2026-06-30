<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentController extends Controller
{
    public function index()
    {
        return response()->json(Equipment::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_name' => 'required|string|unique:tbl_equipments,item_name',
            'total_quantity' => 'required|integer|min:1',
            'status' => 'required|in:Available,Unavailable',
        ]);

        // When first created, available quantity equals total quantity
        $validated['available_quantity'] = $validated['total_quantity'];

        $equipment = Equipment::create($validated);

        return response()->json($equipment, 201);
    }

    public function show($id)
    {
        $equipment = Equipment::find($id);

        if (!$equipment) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }

        return response()->json($equipment);
    }

    public function update(Request $request, $id)
    {
        $equipment = Equipment::find($id);
        
        if (!$equipment) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }

        $validated = $request->validate([
            'item_name' => [
                'sometimes', 
                'required', 
                'string',
                Rule::unique('tbl_equipments')->ignore($equipment->equipment_id, 'equipment_id')
            ],
            'total_quantity' => 'sometimes|required|integer|min:0',
            'available_quantity' => 'sometimes|required|integer|min:0|lte:total_quantity',
            'status' => 'sometimes|required|in:Available,Unavailable',
        ]);

        $equipment->update($validated);

        return response()->json($equipment);
    }

    public function destroy($id)
    {
        $equipment = Equipment::find($id);
        
        if (!$equipment) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }

        $equipment->delete();

        return response()->json(['message' => 'Equipment successfully deleted']);
    }
}