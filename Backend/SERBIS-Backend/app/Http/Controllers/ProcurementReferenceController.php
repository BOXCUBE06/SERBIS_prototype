<?php

namespace App\Http\Controllers;

use App\Models\EquipmentBorrowing;

/**
 * The Procurement Reference page's data: every borrow request that named an
 * item the catalogue does not carry.
 *
 * The page used to read the whole borrowings list and filter it in the browser,
 * which handed a procurement-only account every borrowing in the system with
 * each borrower's contact details. This returns only the rows the page shows and
 * only the columns it draws, so holding Procurement does not open Equipment
 * Borrowing.
 */
class ProcurementReferenceController extends Controller
{
    public function index()
    {
        // The CHECK constraint on the table guarantees exactly one of
        // equipment_id and other_equipment_text is set, so "has text" is the
        // whole definition. TRIM guards the blank string a form can still send.
        $rows = EquipmentBorrowing::query()
            ->with([
                'resident:resident_id,first_name,last_name,barangay_id',
                'resident.barangay:barangay_id,barangay_name',
            ])
            ->whereNotNull('other_equipment_text')
            ->whereRaw("TRIM(other_equipment_text) <> ''")
            ->orderByDesc('created_at')
            ->get(['borrow_id', 'resident_id', 'other_equipment_text', 'purpose', 'quantity', 'status', 'created_at'])
            // Projected by hand: the model appends has_release_photo and
            // has_return_photo, which read columns this query does not select
            // and would come back as wrong answers rather than absent ones.
            ->map(fn (EquipmentBorrowing $borrowing) => [
                'borrow_id' => $borrowing->borrow_id,
                'resident_id' => $borrowing->resident_id,
                'other_equipment_text' => $borrowing->other_equipment_text,
                'purpose' => $borrowing->purpose,
                'quantity' => $borrowing->quantity,
                'status' => $borrowing->status,
                'created_at' => $borrowing->created_at,
                'resident' => $borrowing->resident ? [
                    'first_name' => $borrowing->resident->first_name,
                    'last_name' => $borrowing->resident->last_name,
                    'barangay' => $borrowing->resident->barangay
                        ? ['barangay_name' => $borrowing->resident->barangay->barangay_name]
                        : null,
                ] : null,
            ]);

        return response()->json(['data' => $rows]);
    }
}
