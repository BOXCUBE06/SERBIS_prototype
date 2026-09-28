<?php

namespace App\Http\Controllers;

use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use App\Rules\PhoneAvailable;
use App\Support\PhoneNumber;
use App\Traits\ResolvesUploadDisks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ResidentController extends Controller
{
    use ResolvesUploadDisks;

    public function index()
    {
        $residents = Resident::with('barangay')->get();

        return response()->json($residents);
    }

    /**
     * Who a request can be filed for: an id, a name and a barangay, nothing else.
     *
     * The request boards need to pick a resident when staff file a walk-in, but
     * index() hands back the whole directory — phone number, email, street
     * address, account type — and that belongs to the Residents page alone. This
     * is the part the boards actually use, so holding Resident Requests or
     * Ambulance does not open the rest.
     *
     * The same shape index() returns for these fields (a `barangay` object), so
     * the picker reads it the way it always did. Projected by hand rather than
     * serialised from the model: the model appends derived fields (has_photo,
     * is_phone_verified) that read columns this query does not select, so they
     * would come back as confident-looking wrong answers.
     */
    public function lookup()
    {
        $residents = Resident::with('barangay:barangay_id,barangay_name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['resident_id', 'first_name', 'last_name', 'barangay_id'])
            ->map(fn (Resident $resident) => [
                'resident_id' => $resident->resident_id,
                'first_name' => $resident->first_name,
                'last_name' => $resident->last_name,
                'barangay_id' => $resident->barangay_id,
                'barangay' => $resident->barangay
                    ? ['barangay_id' => $resident->barangay->barangay_id, 'barangay_name' => $resident->barangay->barangay_name]
                    : null,
            ]);

        return response()->json(['data' => $residents]);
    }

    public function store(Request $request)
    {
        // No 'otp' / 'otp_verified_at'. There is no OTP flow anywhere in this
        // system — nothing generates a code, sends one, or checks one, and no
        // client references either column. Accepting them here only meant that
        // if verification were ever built, an admin could mark any phone
        // verified without the resident receiving anything, and could seed a
        // code they already knew. Same shape as the valid_id finding: inert
        // until a feature reads the column, then a hole that predates it.
        $validated = $request->validate([
            'barangay_id' => 'required|integer|exists:tbl_barangay,barangay_id',
            'street_address' => 'nullable|string|max:255',
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            // Unique in canonical form: the number is the resident's login. An
            // officer who is also a head of the family needs a second number for
            // the institutional account, hence the plain message.
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX, new PhoneAvailable(null, 'This number is already used by another account. Give this account a different number.')],
            'email_address' => 'nullable|email|unique:tbl_residents,email_address',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            // The column carries exactly three values and 'required|string'
            // accepted every other one, 'banana' included. That was not
            // theoretical: SmsController::sendBlast() is the only functional
            // reader and it matches 'Active' exactly, so a typo or a miscased
            // status dropped the resident out of every blast with nothing
            // logged and nothing visibly wrong in the admin list.
            'status' => 'required|in:Active,Inactive,Deactivated',
            ...$this->accountTypeRules(),
        ]);

        $this->assertOneBarangayAccount($validated);

        // Columns assigned one at a time, never a splat of $validated. A splat
        // makes every future addition to the rules — or to $fillable — silently
        // client-settable, which is how the dead 'role' rule reached
        // /api/register. 'status' is deliberately here: this is the admin CRUD,
        // and activating a resident is the admin's job.
        $resident = new Resident([
            'barangay_id' => $validated['barangay_id'],
            'street_address' => $validated['street_address'] ?? null,
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'phone_number' => $validated['phone_number'],
            'email_address' => $validated['email_address'] ?? null,
            'password' => bcrypt($validated['password']),
            // No 'photo'. A create carries no file; staff add a barangay's or
            // organization's photo afterwards through POST /api/residents/{id}/photo.
            'status' => $validated['status'],
        ]);
        $this->applyAccountType($resident, $validated);
        // An account made here is vouched for by the admin who made it — a
        // barangay hall, an organization, a walk-in — so its number counts as
        // verified and it signs in like any other. forceFill: the column is
        // deliberately not mass-assignable.
        $resident->forceFill(['phone_verified_at' => now()]);
        $resident->save();

        return response()->json($resident, 201);
    }

    /**
     * Shared by store() and update(). Only an admin can choose the type; the
     * column default (head_of_family) is what every self-registered account
     * gets, because /register never reads this key.
     */
    private function accountTypeRules(): array
    {
        return [
            'account_type' => ['sometimes', 'required', Rule::in(Resident::ACCOUNT_TYPES)],
            'organization_name' => 'required_if:account_type,organization|nullable|string|max:150',
        ];
    }

    /**
     * One shared account per barangay. MySQL has no partial unique index, so
     * this is checked here rather than in the schema.
     */
    private function assertOneBarangayAccount(array $validated, ?int $ignoreId = null): void
    {
        if (($validated['account_type'] ?? null) !== Resident::TYPE_BARANGAY) {
            return;
        }

        $taken = Resident::where('account_type', Resident::TYPE_BARANGAY)
            ->where('barangay_id', $validated['barangay_id'])
            ->when($ignoreId, fn ($q) => $q->where('resident_id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'account_type' => 'This barangay already has its account.',
            ]);
        }
    }

    /**
     * Assigned attribute by attribute: account_type is not fillable. An update
     * that omits it leaves the stored type alone.
     */
    private function applyAccountType(Resident $resident, array $validated): void
    {
        if (isset($validated['account_type'])) {
            $resident->account_type = $validated['account_type'];
        }

        // Only an organization has a name to keep; switching type clears it.
        $resident->organization_name = $resident->account_type === Resident::TYPE_ORGANIZATION
            ? ($validated['organization_name'] ?? $resident->organization_name)
            : null;
    }

    public function show($id)
    {
        $resident = Resident::with('barangay')->find($id);

        if (! $resident) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        return response()->json($resident);
    }

    /**
     * How this resident's past loans came back, newest first, so staff can see a
     * pattern before approving the next request. Display only — nothing here
     * blocks or flags a borrow. Only Returned loans: a loan still out has no
     * condition yet. `return_condition` is null on returns recorded before that
     * column existed, and those are counted as `unrecorded`, not as good.
     * The photo itself is fetched from GET /borrowings/{id}/photo/return; this
     * only says whether there is one.
     */
    public function returnHistory($id)
    {
        $resident = Resident::find($id);

        if (! $resident) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        $returns = EquipmentBorrowing::with('equipment')
            ->where('resident_id', $resident->getKey())
            ->where('status', 'Returned')
            ->orderByDesc('returned_at')
            ->orderByDesc('borrow_id')
            ->get();

        return response()->json([
            'summary' => [
                'total' => $returns->count(),
                'good' => $returns->where('return_condition', 'Good')->count(),
                'bad' => $returns->where('return_condition', 'Bad')->count(),
                'unrecorded' => $returns->whereNull('return_condition')->count(),
            ],
            'data' => $returns->map(fn (EquipmentBorrowing $b) => [
                'borrow_id' => $b->borrow_id,
                'item' => $b->equipment?->item_name ?? $b->other_equipment_text,
                'quantity' => $b->quantity,
                'returned_at' => $b->returned_at,
                'return_condition' => $b->return_condition,
                'return_condition_note' => $b->return_condition_note,
                'has_return_photo' => $b->has_return_photo,
            ])->values(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $resident = Resident::find($id);

        if (! $resident) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhoneNumber::REGEX, new PhoneAvailable((int) $id, 'This number is already used by another account. Give this account a different number.')],
            'email_address' => 'nullable|email|unique:tbl_residents,email_address,'.$id.',resident_id',
            'barangay_id' => 'required|integer|exists:tbl_barangay,barangay_id',
            'street_address' => 'nullable|string|max:255',
            // Same three values as store(). Both admin write paths reach this
            // method — the list's status toggle and the edit form's radio —
            // and the vocabulary is mirrored in the panel at
            // Web/serbis-admin-vue/src/composables/residentStatus.ts.
            'status' => 'required|in:Active,Inactive,Deactivated',
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()], // Must be nullable on update
            ...$this->accountTypeRules(),
        ]);

        $this->assertOneBarangayAccount($validated, $resident->getKey());
        $this->applyAccountType($resident, $validated);

        // Explicit, for the same reason as store(). This method never accepted
        // the OTP columns, but it splatted whatever the rules happened to
        // contain, so adding a rule here was one line away from making a new
        // column writable.
        $changes = [
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'phone_number' => $validated['phone_number'],
            'barangay_id' => $validated['barangay_id'],
            'street_address' => $validated['street_address'] ?? null,
            'status' => $validated['status'],
        ];

        // Email is no longer collected — a phone number is the login — but the
        // column keeps what residents gave before. The panel's form does not
        // send it any more, so an omitted key must leave the stored address
        // alone; only a key that is actually present moves it (an explicit null
        // clears it).
        if (array_key_exists('email_address', $validated)) {
            $changes['email_address'] = $validated['email_address'];
        }

        // An omitted or blank password leaves the stored hash alone; assigning
        // null would lock the resident out of their own account.
        $passwordChanged = ! empty($validated['password']);

        // Deactivating has to end the session too, or the button is only half
        // true: a resident token lives 30 days (SANCTUM_RESIDENT_EXPIRATION), so
        // without this the phone already signed in kept working for a month
        // after the account was closed. Login now refuses them, which leaves the
        // live token as the only remaining way in.
        //
        // Compared exactly rather than through Resident::isDeactivated(): the
        // rule above is `in:Active,Inactive,Deactivated`, so the case of the
        // incoming value is already guaranteed here. The model helper exists for
        // read paths, which see whatever happens to be stored.
        //
        // Keyed on the new value rather than on a transition. Saving Deactivated
        // twice costs one redundant delete of nothing; missing a token because
        // the row was already Deactivated costs a live session on a closed
        // account.
        $deactivating = $validated['status'] === 'Deactivated';

        if ($passwordChanged) {
            $changes['password'] = bcrypt($validated['password']);
        }

        $resident->update($changes);

        // Mirrors AdminController::update(). A password change that leaves the
        // old tokens valid is not a password change: the case this exists for
        // is an account whose credentials leaked, and setting a new password
        // from the panel is the only recovery an office has — there is no
        // self-serve reset. A resident token lives 30 days
        // (SANCTUM_RESIDENT_EXPIRATION), so without this the copy taken
        // beforehand kept working for a month after the reset, and the account
        // stayed compromised while the panel said it had been dealt with.
        //
        // Nothing is spared here, unlike the admin path, which keeps the
        // caller's own token so an admin changing their own password is not
        // logged out mid-click. This route is is.admin-only and the caller is
        // always a User while the target is always a Resident, so the caller
        // can never be revoking their own session.
        if ($passwordChanged || $deactivating) {
            $resident->tokens()->delete();
        }

        return response()->json($resident);
    }

    public function destroy($id)
    {
        $resident = Resident::find($id);

        if (! $resident) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        // tbl_service_request.resident_id is a plain RESTRICT foreign key, so
        // deleting a resident with any request on file used to throw an
        // uncaught 500 instead of a message an admin could act on. Checked
        // here rather than caught after the fact: a caught QueryException
        // cannot tell a resident-in-use failure apart from any other write
        // error, and the count is worth showing.
        $requestCount = DB::table('tbl_service_request')->where('resident_id', $id)->count();

        if ($requestCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$requestCount} service request(s) still reference this resident. Set their status to Deactivated instead.",
            ], 422);
        }

        // The other two RESTRICT keys onto a resident, same reason.
        $logCount = DB::table('tbl_system_logs')->where('resident_id', $id)->count();

        if ($logCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$logCount} activity log record(s) still reference this resident. Set their status to Deactivated instead.",
            ], 422);
        }

        $smsCount = DB::table('tbl_recipients')->where('resident_id', $id)->count();

        if ($smsCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$smsCount} SMS delivery record(s) still reference this resident. Set their status to Deactivated instead.",
            ], 422);
        }

        $resident->delete();

        return response()->json(['message' => 'Resident successfully deleted']);
    }

    /**
     * The resident replaces their own profile photo. Deliberately not part of
     * PATCH /me, and an admin cannot use it for someone else: for a head of the
     * family the column is their own face and the only writer is their own
     * account. Staff set a barangay's or organization's photo through
     * uploadPhoto() below, which is the one exception.
     */
    public function uploadMyPhoto(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        if (! $this->savePhoto($request, $user)) {
            return response()->json(['message' => 'Could not store the photo.'], 500);
        }

        return response()->json([
            'message' => 'Profile photo updated.',
            'user' => $user->load('barangay'),
        ]);
    }

    /**
     * Removes the photo and the file behind it. A resident who uploaded the
     * wrong image needs a way back to initials that does not involve an admin.
     */
    public function deleteMyPhoto(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        $this->clearPhoto($user);

        return response()->json([
            'message' => 'Profile photo removed.',
            'user' => $user->load('barangay'),
        ]);
    }

    /**
     * Staff set or replace a barangay's or organization's photo. Those accounts
     * are shared by several people, so no one's face is at stake; a head of the
     * family stays the owner of their own photo and is refused here.
     */
    public function uploadPhoto(Request $request, $id)
    {
        $resident = $this->institutionForPhoto($id);

        if (! $this->savePhoto($request, $resident)) {
            return response()->json(['message' => 'Could not store the photo.'], 500);
        }

        $this->logPhotoChange($request, $resident, 'photo_changed');

        return response()->json([
            'message' => 'Photo updated.',
            'resident' => $resident->load('barangay'),
        ]);
    }

    public function deletePhoto(Request $request, $id)
    {
        $resident = $this->institutionForPhoto($id);

        $this->clearPhoto($resident);
        $this->logPhotoChange($request, $resident, 'photo_removed');

        return response()->json([
            'message' => 'Photo removed.',
            'resident' => $resident->load('barangay'),
        ]);
    }

    /** 404 for an unknown id; 422 for a head of the family, whose photo is their own. */
    private function institutionForPhoto($id): Resident
    {
        $resident = Resident::findOrFail($id);

        if (! in_array($resident->account_type, [Resident::TYPE_BARANGAY, Resident::TYPE_ORGANIZATION], true)) {
            throw ValidationException::withMessages([
                'account_type' => 'Only barangay and organization accounts can have a photo set by staff.',
            ]);
        }

        return $resident;
    }

    /**
     * Validates the upload, stores it and swaps it in. False when the disk
     * refused the file, which the caller reports as a 500.
     */
    private function savePhoto(Request $request, Resident $resident): bool
    {
        $request->validate([
            // Same ceiling as site_photo. A phone camera JPEG clears 4 MB after
            // the platform's own compression; raising it further mostly buys
            // slow uploads on the mobile connections this app is used on.
            'photo' => 'required|file|mimes:jpg,jpeg,png|max:4096',
        ]);

        $file = $request->file('photo');

        $path = $file->storeAs(
            'resident-photos/'.$resident->getKey(),
            (string) Str::uuid().'.'.$file->extension(),
            self::privateDisk()
        );

        if (! $path) {
            return false;
        }

        // Read the old path before overwriting it: once the column is updated
        // nothing points at the previous file and it would sit on the disk for
        // the life of the deployment.
        $previous = $resident->photo;

        $resident->photo = $path;
        $resident->save();

        $this->discardUpload($previous);

        return true;
    }

    private function clearPhoto(Resident $resident): void
    {
        $previous = $resident->photo;

        $resident->photo = null;
        $resident->save();

        $this->discardUpload($previous);
    }

    // TracksHistory ignores the photo column (the path is not audit material), so
    // a staff change would leave no trace without this row. No path in it.
    private function logPhotoChange(Request $request, Resident $resident, string $action): void
    {
        DB::table('tbl_system_logs')->insert([
            'admin_id' => $request->user()->getKey(),
            'resident_id' => null,
            'action_type' => $action,
            'auditable_type' => Resident::class,
            'auditable_id' => $resident->getKey(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Serves the stored image. Staff may read any resident's photo — the admin
     * list draws one per row — while a resident may read only their own.
     */
    public function photo(Request $request, $id)
    {
        $user = $request->user();

        // 404 rather than 403 for another resident's id, matching validId() and
        // sitePhoto(): the response must not confirm which accounts exist.
        if ($user instanceof Resident) {
            if ((string) $user->getKey() !== (string) $id) {
                return response()->json(['message' => 'Resident not found'], 404);
            }
        } else {
            // Staff branch. This route sits outside `is.admin` so that staff can
            // read any resident's photo while a resident reads only their own,
            // which means neither of that middleware's checks has run — see
            // ServiceRequestController::guardPrivateFile for the same guard and
            // the full reasoning. `role` is an unconstrained varchar, and a
            // deactivation applied by direct database edit revokes no tokens.
            if (! $user instanceof User || ! $user->isAdmin()) {
                return response()->json(['message' => 'Resident not found'], 404);
            }

            if ($user->isDeactivated()) {
                return response()->json(['message' => 'This account has been deactivated.'], 403);
            }
        }

        $resident = Resident::find($id);

        if (! $resident || ! $resident->photo) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        if (! Storage::disk(self::privateDisk())->exists($resident->photo)) {
            return response()->json(['message' => 'Photo file not found'], 404);
        }

        return Storage::disk(self::privateDisk())->response($resident->photo);
    }

    // Best-effort, for the same reason as ServiceRequestController's copy: the
    // row is already correct, and a storage error here must not turn a
    // successful upload into a failed request.
    private function discardUpload(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::disk(self::privateDisk())->delete($path);
        } catch (\Throwable) {
            // An orphaned file is the lesser failure.
        }
    }
}
