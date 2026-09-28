<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\ServiceAudience;
use App\Models\User;
use App\Services\EquipmentAvailabilityNotifier;
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

    public function __construct(
        private readonly Fcm $fcm,
        private readonly EquipmentAvailabilityNotifier $availabilityNotifier,
    ) {}

    /** The calendar a due date is read in — the office's, same as the panel's picker. */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    /** Agency policy cap (MDRRMO feedback, 2026-09-14); DEFAULT_LOAN_DAYS in EquipmentBorrowingView.vue. */
    private const MAX_LOAN_DAYS = 7;

    /** Agency policy floor (MDRRMO feedback, 2026-09-17): a same-day loan is not a real borrow term. */
    private const MIN_LOAN_DAYS = 1;

    /** Shown as the notification's title on every push this controller sends, matching ServiceRequestController. */
    private const PUSH_TITLE = 'SERBIS';

    /** Column and allowed statuses per handover stage. A photo is evidence of a handover, so never before Released (release) or Returned (return); a release photo may still be added after return (a late record is not a false one) but is removable only while Released (`removable_in`). */
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

    /** Legal status moves. The `in:` rule constrained the word, never the move, so a record could go back to Released and re-run the stock deduction for an item already on the shelf. Returned, Denied and Cancelled are terminal; Cancelled is a key only so a cancelled row is refused with the same message (cancel() is its only writer). */
    private const TRANSITIONS = [
        'Pending' => ['Approved', 'Denied'],
        'Approved' => ['Released', 'Denied'],
        'Released' => ['Returned'],
        'Returned' => [],
        'Denied' => [],
        'Cancelled' => [],
    ];

    /** Withdrawable up to Released, when the item is physically with the resident. Not a stock line: stock moves only in update() on Released and Returned, so Pending and Approved reserved nothing and cancel() must not touch stock. */
    private const CANCELLABLE_FROM = ['Pending', 'Approved'];

    public function index(Request $request)
    {
        // Added 'resident.barangay'
        $query = EquipmentBorrowing::with(['resident.barangay', 'equipment'])->orderBy('created_at', 'desc');

        // Scopes to the caller for a resident and refuses anything but active staff; a bare instanceof once handed every borrowing (names, phones, emails) to a deactivated admin.
        if ($refusal = $this->scopeToOwner($request, $query, 'Borrowing record not found')) {
            return $refusal;
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        // Restrictable by account type (Service Audience page); the app hides the tile, this holds for a hand-built request.
        $account = $request->user();

        if ($account instanceof Resident && $account->isAwaitingApproval()) {
            return response()->json([
                'message' => 'Your organization account is awaiting MDRRMO approval. You can borrow equipment once it is activated.',
                'code' => 'account_pending',
            ], 403);
        }

        if ($account instanceof Resident
            && ! ServiceAudience::allows(ServiceAudience::EQUIPMENT_BORROWING, $account->account_type)) {
            return response()->json([
                'message' => 'This account type cannot borrow equipment.',
                'code' => 'service_not_allowed',
            ], 403);
        }

        $validated = $request->validate([
            // Exactly one names the item (the table's CHECK enforces it): required_without catches neither, prohibits catches both; these give the readable 422 the constraint would not.
            'equipment_id' => 'required_without:other_equipment_text|nullable|exists:tbl_equipments,equipment_id',
            'other_equipment_text' => 'required_without:equipment_id|nullable|string|max:255|prohibits:equipment_id',
            'quantity' => 'required|integer|min:1',
            // Required on the way in, nullable in the column (old rows have none); TrimStrings + ConvertEmptyStringsToNull run first, so spaces fail required.
            'purpose' => 'required|string|max:255',
            // sometimes: a client that says nothing means Pickup (the column default), so older mobile builds keep working.
            'fulfillment_method' => 'sometimes|in:Pickup,Delivery',
            // Required for a delivery (tbl_residents has no street address), else it is a run nobody can make.
            'delivery_address' => 'required_if:fulfillment_method,Delivery|nullable|string|max:255',
        ]);

        // A satisfiability check, not a reservation: stock moves only on Released, and reserving on Pending would let anyone empty the inventory with unapproved requests, so no lock either. Skipped for free-text items (no stock figure); update() refuses to release those until an equipment row is attached.
        $equipmentId = $validated['equipment_id'] ?? null;

        if ($equipmentId !== null) {
            $equipment = Equipment::find($equipmentId);

            if (! $equipment || $equipment->available_quantity < $validated['quantity']) {
                $available = $equipment?->available_quantity ?? 0;

                // A field error, so a client can put it on the quantity input (update()'s 422 is about the record, so it is a plain message).
                throw ValidationException::withMessages([
                    'quantity' => "Only {$available} of this item are available to borrow.",
                ]);
            }
        }

        $method = $validated['fulfillment_method'] ?? 'Pickup';
        // Who the loan is for comes from the account, never the request: institutions borrow as Organization, a head of the family as Resident.
        $isInstitution = $account instanceof Resident && ! $account->isHeadOfFamily();
        $borrowerType = $isInstitution ? 'Organization' : 'Resident';
        $organizationName = $isInstitution ? $this->institutionName($account) : null;

        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $request->user()->getKey(),
            // Exactly one is non-null (validation and the CHECK guarantee it); written either/or so a stray empty string cannot become a second item source and 500 on the constraint.
            'equipment_id' => $equipmentId,
            'other_equipment_text' => $equipmentId === null ? $validated['other_equipment_text'] : null,
            'quantity' => $validated['quantity'],
            'purpose' => $validated['purpose'],
            'fulfillment_method' => $method,
            // Dropped for Pickup, so an address typed and then switched away from is not kept as a delivery instruction.
            'delivery_address' => $method === 'Delivery' ? ($validated['delivery_address'] ?? null) : null,
            'borrower_type' => $borrowerType,
            'organization_name' => $organizationName,
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

    /** PATCH /borrowings/{id}/cancel — the resident withdraws their own request. Outside the is.admin group, so it uses scopeToOwner(): a non-owner gets the same 404 as a missing record. One column, no transaction, no stock (see CANCELLABLE_FROM). */
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

        // 422, not a silent no-op: the app shows this message to a resident whose row the office has already released.
        if (! in_array($borrowing->status, self::CANCELLABLE_FROM, true)) {
            return response()->json([
                'message' => 'Only a pending or approved request can be cancelled. Call the office instead.',
            ], 422);
        }

        $borrowing->status = 'Cancelled';
        $borrowing->save();

        return response()->json($borrowing);
    }

    /** The borrower as named on the loan: the organization's own name, or "Barangay <name>" for a barangay hall's shared account. */
    private function institutionName(Resident $account): string
    {
        if ($account->account_type === Resident::TYPE_ORGANIZATION && $account->organization_name) {
            return $account->organization_name;
        }

        return 'Barangay '.($account->barangay?->barangay_name ?? '');
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

        // Counted from Manila's calendar, not UTC: between 00:00 and 08:00 Manila the UTC date is still yesterday, which capped +7 days one short of the panel's picker and refused its own default.
        $officeToday = Carbon::now(self::OFFICE_TIMEZONE)->startOfDay();
        $earliestDue = $officeToday->copy()->addDays(self::MIN_LOAN_DAYS);
        $latestDue = $officeToday->copy()->addDays(self::MAX_LOAN_DAYS);

        // Checked ahead of validate() and only for a real status value (garbage falls to the enum rule): after validate(), return_condition_note's required_if would fire on an illegal Returned attempt and mask "cannot be moved to Returned".
        $requestedStatus = $request->input('status');
        $oldStatus = $borrowing->status;

        // The five values the `in:` rule accepts, not TRANSITIONS' keys: 'Cancelled' is a key (so a cancelled row gets a transition message) but never a legal target here, so it must fall through to validate()'s field error.
        $updatableStatuses = ['Pending', 'Approved', 'Released', 'Returned', 'Denied'];

        if (
            is_string($requestedStatus)
            && in_array($requestedStatus, $updatableStatuses, true)
            && $requestedStatus !== $oldStatus
            && ! in_array($requestedStatus, self::TRANSITIONS[$oldStatus] ?? [], true)
        ) {
            return response()->json([
                'message' => "A borrowing that is {$oldStatus} cannot be moved to {$requestedStatus}.",
            ], 422);
        }

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Released,Returned,Denied',
            // Both optional (a bare status change is valid; only two transitions carry them). A loan runs 1-7 days per agency policy; it used to allow same-day and +1 year. Closing an overdue loan sends the status alone, so the lower bound never blocks it.
            'due_date' => [
                'sometimes', 'nullable', 'date',
                'after_or_equal:'.$earliestDue->toDateString(),
                'before_or_equal:'.$latestDue->toDateString(),
            ],
            'denial_reason' => 'sometimes|nullable|string|max:255',
            // What keys the "still needed?" reconfirm (EquipmentAvailabilityNotifier); denial_reason is free text. Optional: a non-availability denial sends neither.
            'denial_reason_code' => 'sometimes|nullable|in:Unavailable,Other',
            'return_condition' => 'sometimes|nullable|in:Good,Bad',
            // MDRRMO feedback, 2026-09-19: required on every return, not just Bad. Keyed on status so it fires even with no condition sent; no `sometimes`, which would skip required_if when the key is absent.
            'return_condition_note' => 'nullable|string|max:500|required_if:status,Returned',
        ], [
            'due_date.date' => 'Pick a valid due date.',
            'due_date.after_or_equal' => 'A loan runs at least '.self::MIN_LOAN_DAYS.' day — pick '.$earliestDue->format('M j, Y').' or later.',
            'due_date.before_or_equal' => 'A loan runs at most '.self::MAX_LOAN_DAYS.' days — pick '.$latestDue->format('M j, Y').' or earlier.',
            'return_condition_note.required_if' => 'Say what condition it came back in — every return needs a note.',
        ]);

        $newStatus = $validated['status'];

        // An uncatalogued request has no stock row, so releasing would lend an item with no record of what left. Refused, not skipped (a half-processed release is the double-deduction bug class); approve and deny stay open. Checked before the transaction, so it costs no lock.
        if ($newStatus === 'Released' && $borrowing->equipment_id === null) {
            return response()->json([
                'message' => 'This request is for an item that is not in the inventory ('
                    .$borrowing->other_equipment_text
                    .'). Add it to the equipment list and attach it to this request before releasing.',
            ], 422);
        }

        DB::beginTransaction();

        // Set in the Returned branch, read after commit: the availability check sends push/SMS and must not hold the row lock.
        $restockedEquipment = null;

        try {
            // Named for the only status Released is reachable from; `!== 'Released'` was also true of Returned and deducted stock twice.
            if ($newStatus === 'Released' && $oldStatus === 'Approved') {
                $equipment = Equipment::lockForUpdate()->find($borrowing->equipment_id);
                if ($equipment->available_quantity < $borrowing->quantity) {
                    DB::rollBack();

                    return response()->json(['message' => 'We wish to comply but as of the moment the equipment is not available.'], 422);
                }
                $equipment->decrement('available_quantity', $borrowing->quantity);
                $borrowing->released_at = now();
            }

            // Restock on return. No null-equipment guard: Returned is reachable only from Released, and releasing refuses a null equipment_id.
            if ($newStatus === 'Returned' && $oldStatus === 'Released') {
                $equipment = Equipment::lockForUpdate()->find($borrowing->equipment_id);

                // Clamped: a Released side that never decremented (seeded row, manual fix, double-processed) would push available past total, which is how the Oxygen Tank got 45 total, 46 available.
                $requested = $equipment->available_quantity + $borrowing->quantity;
                $clampedTo = min($requested, $equipment->total_quantity);

                // Logged explicitly: a fully-clamped return changes nothing, and TracksHistory drops updates with no dirty attributes, so the clamp would go unrecorded.
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
                $restockedEquipment = $equipment;
            }

            // Key by key, not a splat: a splat would let a caller write any other fillable column through this route.
            if (array_key_exists('due_date', $validated)) {
                // A rescheduled due date invalidates the reminder already sent for the old one (SendReturnDueReminders would stay silent).
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

            // Only a denial carries a reason; moving off Denied clears it, or a re-approved request keeps explaining a refusal that no longer applies.
            if ($newStatus === 'Denied') {
                if (array_key_exists('denial_reason', $validated)) {
                    $borrowing->denial_reason = $validated['denial_reason'];
                }
                if (array_key_exists('denial_reason_code', $validated)) {
                    $borrowing->denial_reason_code = $validated['denial_reason_code'];
                }
            } else {
                $borrowing->denial_reason = null;
                $borrowing->denial_reason_code = null;
                $borrowing->availability_reconfirm_sent_at = null;
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

            if ($restockedEquipment !== null) {
                $this->availabilityNotifier->notifyIfAvailable($restockedEquipment);
            }

            return response()->json($borrowing);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to process borrowing update'], 500);
        }
    }

    /** POST /borrowings/{id}/photo — the item at handover. Its own multipart route (update() takes JSON); admin-only, since the resident can read it (photo()) but supplying it would put the evidence in one side's hands. Never blocks: the status has moved and a photo-less record is complete, and a hard requirement would get a photo of the floor. */
    public function uploadPhoto(Request $request, $id)
    {
        $validated = $request->validate([
            'stage' => 'required|in:release,return',
            // Images only, 4MB, like site_photo; `mimes` checks the guessed type, not the client's filename.
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

        // Private disk like valid_id: a handover photo shows a resident's item and often their doorway and is dispute evidence, and a guessable public URL is a mistake this codebase has paid for. Foldered by borrow_id and named by uuid, so no client filename reaches the disk.
        $path = $file->storeAs(
            'borrowing-photos/'.$borrowing->getKey(),
            (string) Str::uuid().'.'.$file->extension(),
            self::privateDisk()
        );

        // One photo per stage, so a re-upload replaces; the old file is deleted after the new path is on the row, so a failed write cannot leave a dangling record.
        $previous = $borrowing->{$stage['column']};

        // Direct assignment, not fill(): the path columns are outside $fillable so a future update() splat cannot point a record at any private-disk file (government ID scans live there).
        $borrowing->{$stage['column']} = $path;
        $borrowing->save();

        if ($previous && $previous !== $path) {
            Storage::disk(self::privateDisk())->delete($previous);
        }

        return response()->json($borrowing);
    }

    /** DELETE /borrowings/{id}/photo/{stage} — for the wrong-file mistake only, so the window (`removable_in`) is narrower than for adding; after that the photo is part of a finished handover. The row is written before the file is deleted: a record pointing at a missing file is worse than an orphan file. */
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

    /** GET /borrowings/{id}/photo/{stage} — staff and the borrower can read it (evidence one side cannot see is not evidence); scopeToOwner() gives a non-owner the same 404 as a missing record. */
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
        // $stage was checked against the constant above, so the dynamic property is one of two literals (no injection surface).
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
