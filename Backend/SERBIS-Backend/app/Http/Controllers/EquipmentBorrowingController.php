<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Traits\ScopesToOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * Returned, Denied and Cancelled are terminal: an item that came back has
     * nothing left to decide, a refusal is answered by filing a new request
     * rather than by reviving the old one, and a request the resident withdrew
     * is not MDRRMO's to revive at all.
     *
     * Cancelled is a key here but appears in no list, which is deliberate:
     * update()'s `in:` rule does not accept the word, so staff cannot put a
     * record into it from the panel. cancel() is its only writer, and it works
     * off CANCELLABLE_FROM below. The key still has to exist so that a record
     * already Cancelled is refused every move by the check in update() with
     * the same message as any other illegal transition.
     */
    private const TRANSITIONS = [
        'Pending' => ['Approved', 'Denied'],
        'Approved' => ['Released', 'Denied'],
        'Released' => ['Returned'],
        'Returned' => [],
        'Denied' => [],
        'Cancelled' => [],
    ];

    /**
     * Which statuses the resident may withdraw their own request from.
     *
     * The line is at Released because that is when the item is physically in
     * the resident's hands — the same place ServiceRequestController::cancel()
     * draws it at Responding. It is NOT a stock line: available_quantity moves
     * in exactly two places, both in update() (decrement on Released from
     * Approved, increment on Returned from Released), and store()'s own check
     * is a satisfiability check, not a reservation. So neither Pending nor
     * Approved has reserved anything, and cancelling one returns nothing to
     * the shelf. Do not add stock handling to cancel() on the assumption that
     * it does.
     */
    private const CANCELLABLE_FROM = ['Pending', 'Approved'];

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
            // `sometimes` rather than `required`: a client that says nothing
            // about fulfilment means the only thing it could have meant before
            // this existed, which is a pickup. The column's own default writes
            // that, so an older mobile build keeps working unchanged rather
            // than having every request rejected until it is updated.
            'fulfillment_method' => 'sometimes|in:Pickup,Delivery',
            // Only meaningful for a delivery, and required for one: there is
            // nowhere else to get it from. tbl_residents holds a barangay and
            // no street address, so an unanswered delivery is a run nobody can
            // actually make.
            'delivery_address' => 'required_if:fulfillment_method,Delivery|nullable|string|max:255',
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

        $method = $validated['fulfillment_method'] ?? 'Pickup';

        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $request->user()->getKey(),
            'equipment_id' => $validated['equipment_id'],
            'quantity' => $validated['quantity'],
            'purpose' => $validated['purpose'],
            'fulfillment_method' => $method,
            // Dropped rather than stored when the method is Pickup, so an
            // address typed into the form and then switched away from cannot
            // survive as a delivery instruction on a request nobody is
            // delivering.
            'delivery_address' => $method === 'Delivery' ? ($validated['delivery_address'] ?? null) : null,
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

    /**
     * PATCH /borrowings/{id}/cancel — the resident withdraws their own request.
     *
     * Sits outside the is.admin group, so it takes the same scopeToOwner()
     * guard the reads take. Without the staff branch that guard supplies, any
     * token that was not a resident's could cancel any resident's request; with
     * it, a non-owner resident is scoped out of the query and gets the same 404
     * a missing record gets, so the response never confirms the record exists.
     *
     * No transaction and no lock: this writes one column on one row and touches
     * no stock at all — see CANCELLABLE_FROM for why cancelling returns nothing
     * to the shelf.
     */
    public function cancel(Request $request, $id)
    {
        $query = EquipmentBorrowing::query();

        if ($refusal = $this->scopeToOwner($request, $query, 'Borrowing record not found')) {
            return $refusal;
        }

        $borrowing = $query->find($id);

        if (! $borrowing) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        // 422 rather than a silent no-op: a resident who taps Cancel on a row
        // the office has already released is owed the reason, and the app
        // reads this message straight onto the screen.
        if (! in_array($borrowing->status, self::CANCELLABLE_FROM, true)) {
            return response()->json([
                'message' => 'Only a pending or approved request can be cancelled. Call the office instead.',
            ], 422);
        }

        $borrowing->status = 'Cancelled';
        $borrowing->save();

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

                // increment() alone has no upper bound: a borrowing whose
                // Released side never actually decremented stock (a seeded
                // row, a manual fix, a double-processed record) returns into
                // a total that never moved, pushing available_quantity past
                // total_quantity with nothing to say so — this is exactly
                // how the Oxygen Tank row (45 total, 46 available) got that
                // way. Clamped here rather than trusted.
                $requested = $equipment->available_quantity + $borrowing->quantity;
                $clampedTo = min($requested, $equipment->total_quantity);

                // Logged explicitly rather than left to TracksHistory: a
                // fully-clamped return (available_quantity already at
                // total, nothing to add) leaves the column unchanged, and
                // TracksHistory's own "no dirty attributes, don't log" rule
                // (logAction, action 'updated') would silently drop that a
                // clamp was even attempted.
                if ($clampedTo < $requested) {
                    DB::table('tbl_system_logs')->insert([
                        'admin_id' => Auth::user() instanceof \App\Models\User ? Auth::id() : null,
                        'resident_id' => Auth::user() instanceof \App\Models\Resident ? Auth::id() : null,
                        'action_type' => 'stock_clamped',
                        'auditable_type' => Equipment::class,
                        'auditable_id' => $equipment->getKey(),
                        'old_values' => json_encode([
                            'borrow_id' => $borrowing->getKey(),
                            'available_quantity' => $equipment->available_quantity,
                            'total_quantity' => $equipment->total_quantity,
                            'return_quantity' => $borrowing->quantity,
                            'would_have_been' => $requested,
                        ]),
                        'new_values' => json_encode(['available_quantity' => $clampedTo]),
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $equipment->available_quantity = $clampedTo;
                $equipment->save();
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