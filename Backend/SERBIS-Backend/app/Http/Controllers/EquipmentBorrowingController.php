<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentBorrowingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Added 'resident.barangay'
        $query = EquipmentBorrowing::with(['resident.barangay', 'equipment'])->orderBy('created_at', 'desc');

        if ($user instanceof \App\Models\Resident) {
            $query->where('resident_id', $user->getKey());
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'equipment_id' => 'required|exists:tbl_equipments,equipment_id',
            'quantity' => 'required|integer|min:1',
        ]);

        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $request->user()->getKey(),
            'equipment_id' => $validated['equipment_id'],
            'quantity' => $validated['quantity'],
            'status' => 'Pending',
        ]);

        return response()->json($borrowing, 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        // Added 'resident.barangay'
        $query = EquipmentBorrowing::with(['resident.barangay', 'equipment']);

        if ($user instanceof \App\Models\Resident) {
            $query->where('resident_id', $user->getKey());
        }

        $borrowing = $query->find($id);

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
            // Both optional: a status change on its own is still a valid call,
            // and only two of the five transitions carry either of these.
            'due_date' => 'sometimes|nullable|date',
            'denial_reason' => 'sometimes|nullable|string|max:255',
        ]);

        $newStatus = $validated['status'];
        $oldStatus = $borrowing->status;

        DB::beginTransaction();

        try {
            // Handle stock deduction when releasing
            if ($newStatus === 'Released' && $oldStatus !== 'Released') {
                $equipment = Equipment::lockForUpdate()->find($borrowing->equipment_id);
                if ($equipment->available_quantity < $borrowing->quantity) {
                    DB::rollBack();
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

            if ($newStatus === 'Denied' && $oldStatus === 'Released') {
                $equipment = Equipment::lockForUpdate()->find($borrowing->equipment_id);
                $equipment->increment('available_quantity', $borrowing->quantity);
}

            // Assigned key by key rather than by splat: `status` is handled by
            // the transition logic above, and a splat would let a caller write
            // any other fillable column through this route.
            if (array_key_exists('due_date', $validated)) {
                $borrowing->due_date = $validated['due_date'];
            }

            // Only a denial carries a reason. Moving off Denied clears it, or a
            // request re-approved after a refusal keeps explaining a refusal
            // that no longer applies.
            if ($newStatus === 'Denied') {
                if (array_key_exists('denial_reason', $validated)) {
                    $borrowing->denial_reason = $validated['denial_reason'];
                }
            } else {
                $borrowing->denial_reason = null;
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