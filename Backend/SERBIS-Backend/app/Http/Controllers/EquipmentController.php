<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EquipmentController extends Controller
{
    /**
     * Scoped by who is asking, the same way EquipmentBorrowingController::index
     * is. An `Unavailable` item was still listed to residents, because the only
     * thing the borrow screen gates on is `available_quantity > 0` -- so an
     * item withdrawn from lending (under repair, condemned, reserved) kept
     * appearing with a working Borrow button as long as its count was above
     * zero. `status` was decorative for everyone except the admin who set it.
     *
     * Admins still get the whole inventory: the panel has to show, and edit,
     * exactly the rows a resident must not see.
     */
    public function index(Request $request)
    {
        $query = Equipment::query();

        if ($request->user() instanceof \App\Models\Resident) {
            $query->where('status', 'Available');
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255|unique:tbl_equipments,item_name',
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
                'max:255',
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

        // tbl_equipment_borrowing.equipment_id cascades on delete, so this used
        // to succeed silently and take every borrowing record with it — even a
        // Released one, where the units are still physically out with a
        // resident. The cascade stays (it is what a resolved item's old
        // history should do); this only blocks deleting while a borrowing is
        // still live enough to change state on its own.
        $activeCount = DB::table('tbl_equipment_borrowing')
            ->where('equipment_id', $id)
            ->whereIn('status', ['Pending', 'Approved', 'Released'])
            ->count();

        if ($activeCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$activeCount} borrowing(s) still reference this equipment.",
            ], 422);
        }

        $equipment->delete();

        return response()->json(['message' => 'Equipment successfully deleted']);
    }
}