<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use App\Services\Fcm;
use App\Traits\ResolvesUploadDisks;
use App\Traits\ScopesToOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EquipmentBorrowingController extends Controller
{
    use ResolvesUploadDisks;
    use ScopesToOwner;

    public function __construct(private readonly Fcm $fcm) {}

    /** The calendar a due date is read in — the office's, same as the panel's picker. */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    /** Agency policy cap (MDRRMO feedback, 2026-09-14); DEFAULT_LOAN_DAYS in EquipmentBorrowingView.vue. */
    private const MAX_LOAN_DAYS = 7;

    /** Agency policy floor (MDRRMO feedback, 2026-09-17): a same-day loan is not a real borrow term. */
    private const MIN_LOAN_DAYS = 1;

    /** Shown as the notification's title on every push this controller sends, matching ServiceRequestController. */
    private const PUSH_TITLE = 'SERBIS';

    /**
     * Which column each handover stage writes, and which statuses it may be
     * photographed in.
     *
     * A release photo is a record of what left the building, so it means
     * nothing before the item has left: Released is when that happened, and
     * Returned is still allowed because staff photographing after the fact is
     * a late record, not a false one. A return photo is only meaningful once
     * the item is back.
     *
     * Deliberately NOT open on Pending or Approved. Nothing has changed hands
     * yet, so a photo filed against either would be evidence of a handover
     * that has not happened — the exact claim a dispute would turn on.
     *
     * `removable_in` is narrower than `statuses`, and the difference is the
     * point: a photo may be deleted only while the borrowing is still in the
     * stage that photo belongs to. Staff who attached the wrong file can fix it
     * at the counter; once the item has moved on — a release photo on a record
     * that is now Returned — the picture is part of the trail of a finished
     * handover and only replacement is left. Note the asymmetry with upload:
     * a release photo may still be ADDED after the item is back (a late record
     * is not a false one), but not removed then.
     */
    private const PHOTO_STAGES = [
        'release' => [
            'column' => 'release_photo_path',
            'statuses' => ['Released', 'Returned'],
            'removable_in' => ['Released'],
        ],
        'return' => [
            'column' => 'return_photo_path',
            'statuses' => ['Returned'],
            'removable_in' => ['Returned'],
        ],
    ];

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
            // Exactly one of these two names the item, which the CHECK
            // constraint on the table enforces underneath. `required_without`
            // on both sides catches a request that names neither;
            // `prohibits` catches one that names both, which would be a
            // request that disagrees with itself about what is being borrowed.
            // These rules are the readable 422; the constraint is the floor
            // under a seeder or a tinker session that never reaches them.
            'equipment_id' => 'required_without:other_equipment_text|nullable|exists:tbl_equipments,equipment_id',
            'other_equipment_text' => 'required_without:equipment_id|nullable|string|max:255|prohibits:equipment_id',
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
            // `sometimes` for the same reason as fulfillment_method above: an
            // older client that says nothing meant a resident borrowing for
            // themselves, which is what the column's default writes.
            'borrower_type' => 'sometimes|in:Resident,Organization',
            // Required for an organisation because there is nowhere else to get
            // it from — the account behind the request is a person, and their
            // name is not the group's. Capped at the column width.
            'organization_name' => 'required_if:borrower_type,Organization|nullable|string|max:150',
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
        //
        // Skipped entirely when the request names free text instead of a
        // catalogued item: there is no stock figure to check against, because
        // the whole point of `other_equipment_text` is that MDRRMO has not
        // catalogued the thing. An uncatalogued request is bounded at release
        // time instead, by update() refusing to release one at all until staff
        // have attached a real equipment row — see the guard there.
        $equipmentId = $validated['equipment_id'] ?? null;

        if ($equipmentId !== null) {
            $equipment = Equipment::find($equipmentId);

            if (! $equipment || $equipment->available_quantity < $validated['quantity']) {
                $available = $equipment?->available_quantity ?? 0;

                // Raised as a field error rather than a bare message so a client can
                // put it on the quantity input. `update()` answers with a plain
                // message because its 422 is about the record, not about one field.
                throw ValidationException::withMessages([
                    'quantity' => "Only {$available} of this item are available to borrow.",
                ]);
            }
        }

        $method = $validated['fulfillment_method'] ?? 'Pickup';
        $borrowerType = $validated['borrower_type'] ?? 'Resident';

        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $request->user()->getKey(),
            // Exactly one of the next two is non-null, which the validation
            // above and the table's CHECK constraint both guarantee. Written
            // as an explicit either/or rather than passing both straight
            // through, so an empty string surviving from a client cannot land
            // as a second item source and trip the constraint with a 500.
            'equipment_id' => $equipmentId,
            'other_equipment_text' => $equipmentId === null ? $validated['other_equipment_text'] : null,
            'quantity' => $validated['quantity'],
            'purpose' => $validated['purpose'],
            'fulfillment_method' => $method,
            // Dropped rather than stored when the method is Pickup, so an
            // address typed into the form and then switched away from cannot
            // survive as a delivery instruction on a request nobody is
            // delivering.
            'delivery_address' => $method === 'Delivery' ? ($validated['delivery_address'] ?? null) : null,
            'borrower_type' => $borrowerType,
            // Dropped on a Resident request for the same reason the address is
            // dropped on a Pickup: an organisation name typed into the form and
            // then switched away from must not survive as a claim that this
            // loan was institutional.
            'organization_name' => $borrowerType === 'Organization' ? ($validated['organization_name'] ?? null) : null,
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

        if (! $borrowing) {
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

    /** The catalogued item's name, or the free-text description for an uncatalogued ("Other") request. */
    private function itemLabel(EquipmentBorrowing $borrowing): string
    {
        return $borrowing->equipment?->item_name ?? $borrowing->other_equipment_text;
    }

    private function approvedPushBody(EquipmentBorrowing $borrowing): string
    {
        return 'Your request to borrow '.$this->itemLabel($borrowing).' has been approved. — MDRRMO Echague';
    }

    /** denial_reason is optional (unlike a service request's rejection remarks), so the sentence only grows one when there is one. */
    private function deniedPushBody(EquipmentBorrowing $borrowing): string
    {
        $reason = $borrowing->denial_reason;

        return 'Your request to borrow '.$this->itemLabel($borrowing).' was not approved.'
            .($reason ? ' Reason: '.$reason.'.' : '').' — MDRRMO Echague';
    }

    /** Pickup and Delivery are materially different instructions, not a wording preference. */
    private function releasedPushBody(EquipmentBorrowing $borrowing): string
    {
        $item = $this->itemLabel($borrowing);

        return $borrowing->fulfillment_method === 'Delivery'
            ? 'Your '.$item.' is ready and will be delivered to you. — MDRRMO Echague'
            : 'Your '.$item.' is ready for pickup. — MDRRMO Echague';
    }

    public function update(Request $request, $id)
    {
        $borrowing = EquipmentBorrowing::find($id);
        if (! $borrowing) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        // Counted from Manila's calendar, not app.timezone (UTC). Between 00:00
        // and 08:00 Manila the UTC date is still yesterday, so `+7 days` capped
        // one day short of what the panel's picker offers and its own default
        // was refused with the raw rule text.
        $officeToday = Carbon::now(self::OFFICE_TIMEZONE)->startOfDay();
        $earliestDue = $officeToday->copy()->addDays(self::MIN_LOAN_DAYS);
        $latestDue = $officeToday->copy()->addDays(self::MAX_LOAN_DAYS);

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Released,Returned,Denied',
            // Both optional: a status change on its own is still a valid call,
            // and only two of the five transitions carry either of these.
            //
            // Bounded in both directions per agency policy: a loan runs
            // 1-7 days. A same-day due date is not a real loan term any more
            // than one already past is, and the upper bound is the agency's
            // own policy cap. This used to allow same-day and +1 year, which
            // were never real loan terms.
            //
            // Safe against the overdue case specifically: the panel sends
            // `due_date` only when approving, or when releasing a row that
            // never got one. Marking an overdue item Returned or Denied sends
            // the status alone, so closing one out is untouched by the lower
            // bound.
            'due_date' => [
                'sometimes', 'nullable', 'date',
                'after_or_equal:'.$earliestDue->toDateString(),
                'before_or_equal:'.$latestDue->toDateString(),
            ],
            'denial_reason' => 'sometimes|nullable|string|max:255',
            // Good needs nothing beyond the flag itself; Bad needs the note
            // to say what's wrong, or "bad" is a label with no information
            // behind it for the next person deciding whether to lend again.
            'return_condition' => 'sometimes|nullable|in:Good,Bad',
            // No `sometimes` here: that rule skips everything else when the
            // field is absent, which would let required_if never fire at all
            // for exactly the case it exists to catch — Bad sent with no
            // note key in the payload, not just an empty one.
            'return_condition_note' => 'nullable|string|max:500|required_if:return_condition,Bad',
        ], [
            'due_date.date' => 'Pick a valid due date.',
            'due_date.after_or_equal' => 'A loan runs at least '.self::MIN_LOAN_DAYS.' day — pick '.$earliestDue->format('M j, Y').' or later.',
            'due_date.before_or_equal' => 'A loan runs at most '.self::MAX_LOAN_DAYS.' days — pick '.$latestDue->format('M j, Y').' or earlier.',
            'return_condition_note.required_if' => 'Say what\'s wrong with it — a bad return needs a note.',
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

        // An uncatalogued request has no equipment row, so there is no stock to
        // deduct and nothing to hand over that the inventory knows about.
        // Releasing one would leave the office having lent a physical item with
        // no record of what left the building.
        //
        // Refused explicitly rather than skipped: releasing with the deduction
        // quietly not happening is the same bug class as the double-deduction
        // this branch was written to fix, and staff would have no way to tell
        // the release had been half-processed. Approve and deny stay open — the
        // office can still consider the request; they just have to catalogue
        // the item before it goes out. Checked before the transaction opens, so
        // it costs no lock.
        if ($newStatus === 'Released' && $borrowing->equipment_id === null) {
            return response()->json([
                'message' => 'This request is for an item that is not in the inventory ('
                    .$borrowing->other_equipment_text
                    .'). Add it to the equipment list and attach it to this request before releasing.',
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

                    return response()->json(['message' => 'We wish to comply but as of the moment the equipment is not available.'], 422);
                }
                $equipment->decrement('available_quantity', $borrowing->quantity);
                $borrowing->released_at = now();
            }

            // Handle stock addition when returning
            //
            // Needs no null-equipment guard of its own, unlike the release
            // branch above: Returned is reachable only from Released, and the
            // check before this transaction refuses to release a record whose
            // equipment_id is null. So anything arriving here has already been
            // proved to have an equipment row.
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
                        'admin_id' => Auth::user() instanceof User ? Auth::id() : null,
                        'resident_id' => Auth::user() instanceof Resident ? Auth::id() : null,
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
                // A rescheduled due date invalidates any reminder already
                // sent for the old one — see SendReturnDueReminders, which
                // would otherwise stay silent for the rest of the loan.
                if ($validated['due_date'] !== $borrowing->due_date?->format('Y-m-d')) {
                    $borrowing->return_reminder_sent_at = null;
                }

                $borrowing->due_date = $validated['due_date'];
            }

            if (array_key_exists('return_condition_note', $validated)) {
                $borrowing->return_condition_note = $validated['return_condition_note'];
            }

            if (array_key_exists('return_condition', $validated)) {
                $borrowing->return_condition = $validated['return_condition'];
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

            $pushBody = match ($newStatus) {
                'Approved' => $this->approvedPushBody($borrowing),
                'Denied' => $this->deniedPushBody($borrowing),
                'Released' => $this->releasedPushBody($borrowing),
                default => null,
            };

            if ($pushBody !== null) {
                $this->fcm->notifyResident(
                    $borrowing->resident_id,
                    self::PUSH_TITLE,
                    $pushBody,
                    ['borrow_id' => (string) $borrowing->borrow_id],
                );
            }

            return response()->json($borrowing);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to process borrowing update'], 500);
        }
    }

    /**
     * POST /borrowings/{id}/photo — what the item looked like at handover.
     *
     * Its own route rather than a field on update(), because update() takes
     * JSON and a file needs multipart. Folding it in would have meant every
     * status change carrying a multipart encoder for a field it never sends.
     *
     * Admin-only, via the route group: releasing and returning are counter
     * actions, and the photograph is taken by whoever is standing at the
     * counter. The resident can read it back — see photo() — but never write
     * it, or the evidence would be supplied by one side of any dispute it
     * exists to settle.
     *
     * Never blocks anything. The status has already moved by the time this is
     * called, and a borrowing with no photo is a normal, complete record. The
     * office releases equipment in conditions where stopping to photograph it
     * is the wrong advice; a hard requirement would be answered with a photo
     * of the floor.
     */
    public function uploadPhoto(Request $request, $id)
    {
        $validated = $request->validate([
            'stage' => 'required|in:release,return',
            // Matches site_photo on tbl_service_request: images only, 4MB. No
            // pdf and no doc — this is a photograph of an object, and every
            // other accepted type would only widen what can be written to
            // disk. `mimes` checks the file's guessed type, not the name the
            // client sent.
            'photo' => 'required|file|mimes:jpg,jpeg,png|max:4096',
        ]);

        $borrowing = EquipmentBorrowing::find($id);

        if (! $borrowing) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        $stage = self::PHOTO_STAGES[$validated['stage']];

        if (! in_array($borrowing->status, $stage['statuses'], true)) {
            $allowed = implode(' or ', $stage['statuses']);

            return response()->json([
                'message' => "A {$validated['stage']} photo can only be added to a borrowing that is {$allowed}."
                    ." This one is {$borrowing->status}.",
            ], 422);
        }

        $file = $request->file('photo');

        // Private disk, same as valid_id and site_photo. A handover photo shows
        // a named resident's item and often their doorway, and it is evidence
        // in a dispute between them and the office — a guessable public URL is
        // the mistake this codebase has already paid for once. Foldered by
        // borrow_id and named with a uuid, so a client filename never reaches
        // the filesystem.
        $path = $file->storeAs(
            'borrowing-photos/'.$borrowing->getKey(),
            (string) Str::uuid().'.'.$file->extension(),
            self::privateDisk()
        );

        // One photo per stage, so a re-upload replaces. The old file is deleted
        // after the new path is safely on the row rather than before: losing
        // the write would otherwise leave the record pointing at a file that
        // has already been removed.
        $previous = $borrowing->{$stage['column']};

        // Assigned directly rather than through fill(). These two columns are
        // NOT in $fillable on purpose — a filesystem path is written by this
        // method and by nothing else, and leaving it mass-assignable would let
        // any future update() splat point a record at an arbitrary file on the
        // private disk, which is where government ID scans live.
        $borrowing->{$stage['column']} = $path;
        $borrowing->save();

        if ($previous && $previous !== $path) {
            Storage::disk(self::privateDisk())->delete($previous);
        }

        return response()->json($borrowing);
    }

    /**
     * DELETE /borrowings/{id}/photo/{stage} — takes one back off the record.
     *
     * Admin-only, like the upload. This exists for the wrong-file mistake —
     * the photo of the previous borrower's item, the accidental shot of the
     * counter — and for nothing else, which is why the window is narrower than
     * the one for adding: see `removable_in` on PHOTO_STAGES. Once the record
     * has moved past the stage, the photo is part of a finished handover, and
     * a dispute is exactly when someone would want it gone.
     *
     * The row is written before the file is deleted, for the same reason
     * uploadPhoto() deletes the old file last: a record pointing at a file that
     * is not there is worse than a file with no record pointing at it.
     */
    public function destroyPhoto(Request $request, $id, string $stage)
    {
        if (! array_key_exists($stage, self::PHOTO_STAGES)) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        $borrowing = EquipmentBorrowing::find($id);

        if (! $borrowing) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        $config = self::PHOTO_STAGES[$stage];
        $path = $borrowing->{$config['column']};

        if (! $path) {
            return response()->json(['message' => 'There is no '.$stage.' photo on this borrowing.'], 404);
        }

        if (! in_array($borrowing->status, $config['removable_in'], true)) {
            $allowed = implode(' or ', $config['removable_in']);

            return response()->json([
                'message' => "A {$stage} photo can only be removed while the borrowing is {$allowed}."
                    ." This one is {$borrowing->status}, so the photo can be replaced but not deleted.",
            ], 422);
        }

        // Direct assignment, not fill(): these columns are outside $fillable so
        // that a path is written by this class and by nothing else.
        $borrowing->{$config['column']} = null;
        $borrowing->save();

        Storage::disk(self::privateDisk())->delete($path);

        return response()->json($borrowing);
    }

    /**
     * GET /borrowings/{id}/photo/{stage} — reads one back.
     *
     * Read is wider than write. Staff need it to settle a dispute; the
     * borrower needs it for the same reason, and evidence only one side can
     * see is not evidence. scopeToOwner() gives exactly that: staff see every
     * record, a resident sees their own, and a non-owner gets the same 404 a
     * missing record gets so the response never confirms it exists.
     */
    public function photo(Request $request, $id, string $stage)
    {
        if (! array_key_exists($stage, self::PHOTO_STAGES)) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        $query = EquipmentBorrowing::query();

        if ($refusal = $this->scopeToOwner($request, $query, 'Borrowing record not found')) {
            return $refusal;
        }

        $borrowing = $query->find($id);
        // $stage is checked against the constant above before it reaches this
        // line, so the dynamic property is always one of two literals and
        // carries no injection surface.
        $column = self::PHOTO_STAGES[$stage]['column'];

        if (! $borrowing || ! $borrowing->{$column}) {
            return response()->json(['message' => 'Borrowing record not found'], 404);
        }

        if (! Storage::disk(self::privateDisk())->exists($borrowing->{$column})) {
            return response()->json(['message' => 'Handover photo file not found'], 404);
        }

        return Storage::disk(self::privateDisk())->response($borrowing->{$column});
    }
}
