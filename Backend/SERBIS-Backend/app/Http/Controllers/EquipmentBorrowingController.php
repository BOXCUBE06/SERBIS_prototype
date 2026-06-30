<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentBorrowingController extends Controller
{
    public function index()
    {
        $borrowings = EquipmentBorrowing::with(['resident', 'equipment'])->orderBy('created_at', 'desc')->get();
        return response()->json($borrowings);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'resident_id' => 'required|exists:tbl_residents,resident_id',
            'equipment_id' => 'required|exists:tbl_equipments,equipment_id',
            'quantity' => 'required|integer|min:1',
        ]);

        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $validated['resident_id'],
            'equipment_id' => $validated['equipment_id'],
            'quantity' => $validated['quantity'],
            'status' => 'Pending',
        ]);

        return response()->json($borrowing, 201);
    }

    public function show($id)
    {
        $borrowing = EquipmentBorrowing::with(['resident', 'equipment'])->find($id);

        if (!$borrowing) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        return response()->json($borrowing);
    }

    public function update(Request $request, $id)
    {
        $borrowing = EquipmentBorrowing::find($id);
        if (!$borrowing) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Released,Returned,Denied',
        ]);

        $newStatus = $validated['status'];
        $oldStatus = $borrowing->status;

        DB::beginTransaction();

        try {
            // Handle stock deduction when releasing
            if ($newStatus === 'Released' && $oldStatus !== 'Released') {
                $equipment = Equipment::lockForUpdate()->find($borrowing->equipment_id);
                if ($equipment->available_quantity < $borrowing->quantity) {
                    return response()->json(['message' => 'Not enough equipment available to release.'], 422);
                }
                $equipment->decrement('available_quantity', $borrowing->quantity);
                $borrowing->released_at = now();
            }

            // Handle stock addition when returning
            if ($newStatus === 'Returned' && $oldStatus === 'Released') {
                $equipment = Equipment::lockForUpdate()->find($borrowing->equipment_id);
                $equipment->increment('available_quantity', $borrowing->quantity);
                $borrowing->returned_at = now();
            }

            $borrowing->status = $newStatus;
            $borrowing->save();

            DB::commit();
            return response()->json($borrowing);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to process borrowing update'], 500);
        }
    }
}