<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Traits\ScopesToOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipmentBorrowingController extends Controller
{
    use ScopesToOwner;

    /**
     * Which status each status may move to.
     *
     * The validation rule below constrains the status *word* and never the
     * *move*, so any record could be pushed into any state — including
     * backwards into Released, which re-ran the stock deduction for an item
     * already back on the shelf and dropped the count for good.
     *
     * Returned and Denied are terminal: an item that came back has nothing left
     * to decide, and a refusal is answered by filing a new request rather than
     * by reviving the old one.
     */
    private const TRANSITIONS = [
        'Pending' => ['Approved', 'Denied'],
        'Approved' => ['Released', 'Denied'],
        'Released' => ['Returned'],
        'Returned' => [],
        'Denied' => [],
    ];

    public function index(Request $request)
    {
        // Added 'resident.barangay'
        $query = EquipmentBorrowing::with(['resident.barangay', 'equipment'])->orderBy('created_at', 'desc');

        // Scopes to the caller for a resident, and refuses anything that is not
        // active staff. This used to be a bare `instanceof Resident` check with
        // no else, so a deactivated admin — or a tbl_user row with some other
        // role — was handed every borrowing in the system, each carrying the
        // borrower's name, barangay, phone number and email.
        if ($refusal = $this->scopeToOwner($request, $query, 'Borrowing record not found')) {
            return $refusal;
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'equipment_id' => 'required|exists:tbl_equipments,equipment_id',
            'quantity' => 'required|integer|min:1',
            // Required on the way in, nullable in the column: rows filed
            // before the column existed have no purpose, but a new request
            // that does not say what the item is for gives MDRRMO nothing to
            // decide on beyond stock. TrimStrings + ConvertEmptyStringsToNull
            // run ahead of this, so a box of spaces fails `required` here
            // rather than storing as a blank reason.
            'purpose' => 'required|string|max:255',
        ]);

        // The rules above bound the shape and never the amount, so a resident
        // could file for fifty of an item the office owns four of. Nothing
        // rejected it until an admin tried to release it, by which point the
        // request had already been approved.
        //
        // This is a satisfiability check, not a reservation: stock moves only on
        // Released, so two Pending requests for the whole shelf are both filed
        // and the second one fails at release time. Reserving on Pending would
        // let anyone empty the inventory with requests nobody ever approves.
        // For the same reason no lock is taken — there is no write to race with.
        $equipment = Equipment::find($validated['equipment_id']);

        if (! $equipment || $equipment->available_quantity < $validated['quantity']) {
            $available = $equipment?->available_quantity ?? 0;

            // Raised as a field error rather than a bare message so a client can
            // put it on the quantity input. `update()` answers with a plain
            // message because its 422 is about the record, not about one field.
            throw ValidationException::withMessages([
                'quantity' => "Only {$available} of this item are available to borrow.",
            ]);
        }

        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $request->user()->getKey(),
            'equipment_id' => $validated['equipment_id'],
            'quantity' => $validated['quantity'],
            'purpose' => $validated['purpose'],
            'status' => 'Pending',
        ]);

        return response()->json($borrowing, 201);
    }

    public function show(Request $request, $id)
    {
        // Added 'resident.barangay'
        $query = EquipmentBorrowing::with(['resident.barangay', 'equipment']);

        if ($refusal = $this->scopeToOwner($request, $query, 'Borrowing record not found')) {
            return $refusal;
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
            //
            // Bounded in both directions, which it was not at all. A loan is
            // due back after it is lent, so a date already past is a typo, not
            // an instruction — and a year is far beyond the panel's own
            // seven-day default (DEFAULT_LOAN_DAYS, EquipmentBorrowingView.vue)
            // while still catching the mis-keyed century.
            //
            // Safe against the overdue case specifically: the panel sends
            // `due_date` only when approving, or when releasing a row that
            // never got one. Marking an overdue item Returned or Denied sends
            // the status alone, so closing one out is untouched by the lower
            // bound. `today` resolves in app.timezone (UTC) while the office
            // reads Manila, which can admit yesterday-in-Manila for eight
            // hours — the rule is here to reject 2019 and 9999, not to police
            // a day boundary.
            'due_date' => 'sometimes|nullable|date|after_or_equal:today|before_or_equal:+1 year',
            'denial_reason' => 'sometimes|nullable|string|max:255',
        ]);

        $newStatus = $validated['status'];
        $oldStatus = $borrowing->status;

        // Checked before the transaction opens, so an illegal move costs no
        // lock and touches no stock. Resending the current status is a no-op
        // rather than a transition: `status` is required, so a call that only
        // edits `due_date` has to carry it, and no branch below fires when the
        // two are equal. A row whose status is not one of the five falls
        // through to an empty list and is rejected, which is the safe way to
        // fail on data drift.
        if ($newStatus !== $oldStatus && ! in_array($newStatus, self::TRANSITIONS[$oldStatus] ?? [], true)) {
            return response()->json([
                'message' => "A borrowing that is {$oldStatus} cannot be moved to {$newStatus}.",
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Named for the only status Released can be reached from. The old
            // condition was `$oldStatus !== 'Released'`, which was true of a
            // Returned record too and is what deducted the stock twice.
            if ($newStatus === 'Released' && $oldStatus === 'Approved') {
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