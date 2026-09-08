<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EquipmentController extends Controller
{
    /**
     * total_quantity/available_quantity are a plain MySQL `integer` column —
     * signed INT, no `unsigned()`. The `integer` validation rule accepts
     * PHP's much larger range, so a value past this bound reached the insert
     * and threw SQLSTATE[22003] instead of a validation error.
     */
    private const MAX_QUANTITY = 2147483647;

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

        if ($request->user() instanceof Resident) {
            $query->where('status', 'Available');
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255|unique:tbl_equipments,item_name',
            'total_quantity' => 'required|integer|min:1|max:'.self::MAX_QUANTITY,
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

        if (! $equipment) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }

        return response()->json($equipment);
    }

    public function update(Request $request, $id)
    {
        $equipment = Equipment::find($id);

        if (! $equipment) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }

        $validated = $request->validate([
            'item_name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('tbl_equipments')->ignore($equipment->equipment_id, 'equipment_id'),
            ],
            'total_quantity' => 'sometimes|required|integer|min:0|max:'.self::MAX_QUANTITY,
            'available_quantity' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
                'max:'.self::MAX_QUANTITY,
                // lte:total_quantity compares only against another field in
                // THIS request. A solo available_quantity edit — adjusting
                // stock without touching the total — carries no
                // total_quantity at all, and Laravel's own lte semantics
                // treat a missing comparison field as failing every value,
                // not as skipping the rule: this endpoint could never accept
                // a solo available_quantity edit, for any value including 0.
                // Compares against the request's total_quantity when one was
                // sent (unchanged), the stored value otherwise.
                function ($attribute, $value, $fail) use ($request, $equipment) {
                    $total = $request->has('total_quantity')
                        ? (int) $request->input('total_quantity')
                        : (int) $equipment->total_quantity;

                    if ($value > $total) {
                        $fail("The available quantity field must be less than or equal to {$total}.");
                    }
                },
            ],
            'status' => 'sometimes|required|in:Available,Unavailable',
        ]);

        // available_quantity's lte:total_quantity rule above only fires when
        // both fields are sent together — it compares the incoming values
        // against each other, not against what is already stored. A
        // total_quantity sent alone skipped this entirely, so a total could be
        // dropped below the available_quantity already on the row, leaving
        // available > total with nothing to say so.
        if (array_key_exists('total_quantity', $validated) && ! array_key_exists('available_quantity', $validated)) {
            if ($validated['total_quantity'] < $equipment->available_quantity) {
                $onLoan = $equipment->total_quantity - $equipment->available_quantity;

                return response()->json([
                    'message' => "Cannot set total to {$validated['total_quantity']} — "
                        ."{$equipment->available_quantity} unit(s) are currently available "
                        ."and {$onLoan} on loan; total cannot drop below what is available.",
                ], 422);
            }
        }

        $equipment->update($validated);

        return response()->json($equipment);
    }

    public function destroy($id)
    {
        $equipment = Equipment::find($id);

        if (! $equipment) {
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
