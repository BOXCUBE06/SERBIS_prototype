<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use App\Models\User;
use App\Services\PhilSms;
use App\Traits\ResolvesUploadDisks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ResidentController extends Controller
{
    use ResolvesUploadDisks;

    public function index()
    {
        $residents = Resident::with('barangay')->get();

        return response()->json($residents);
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
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhilSms::PHONE_REGEX],
            'email_address' => 'required|email|unique:tbl_residents,email_address',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            // The column carries exactly three values and 'required|string'
            // accepted every other one, 'banana' included. That was not
            // theoretical: SmsController::sendBlast() is the only functional
            // reader and it matches 'Active' exactly, so a typo or a miscased
            // status dropped the resident out of every blast with nothing
            // logged and nothing visibly wrong in the admin list.
            'status' => 'required|in:Active,Inactive,Deactivated',
        ]);

        // Columns assigned one at a time, never a splat of $validated. A splat
        // makes every future addition to the rules — or to $fillable — silently
        // client-settable, which is how the dead 'role' rule reached
        // /api/register. 'status' is deliberately here: this is the admin CRUD,
        // and activating a resident is the admin's job.
        $resident = Resident::create([
            'barangay_id' => $validated['barangay_id'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'phone_number' => $validated['phone_number'],
            'email_address' => $validated['email_address'],
            'password' => bcrypt($validated['password']),
            // No 'photo'. It is the resident's own face, uploaded from the
            // mobile app by POST /api/me/photo; an admin creating the account
            // has no file to attach and no business naming one.
            'status' => $validated['status'],
        ]);

        return response()->json($resident, 201);
    }

    public function show($id)
    {
        $resident = Resident::with('barangay')->find($id);

        if (! $resident) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        return response()->json($resident);
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
            'phone_number' => ['required', 'string', 'max:20', 'regex:'.PhilSms::PHONE_REGEX],
            'email_address' => 'required|email|unique:tbl_residents,email_address,'.$id.',resident_id',
            'barangay_id' => 'required|integer|exists:tbl_barangay,barangay_id',
            // Same three values as store(). Both admin write paths reach this
            // method — the list's status toggle and the edit form's radio —
            // and the vocabulary is mirrored in the panel at
            // Web/serbis-admin-vue/src/composables/residentStatus.ts.
            'status' => 'required|in:Active,Inactive,Deactivated',
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()], // Must be nullable on update
        ]);

        // Explicit, for the same reason as store(). This method never accepted
        // the OTP columns, but it splatted whatever the rules happened to
        // contain, so adding a rule here was one line away from making a new
        // column writable.
        $changes = [
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'phone_number' => $validated['phone_number'],
            'email_address' => $validated['email_address'],
            'barangay_id' => $validated['barangay_id'],
            'status' => $validated['status'],
        ];

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
     * PATCH /me and not reachable by an admin: the column is the resident's own
     * face, and the only writer is the account it belongs to.
     */
    public function uploadMyPhoto(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        $request->validate([
            // Same ceiling as site_photo. A phone camera JPEG clears 4 MB after
            // the platform's own compression; raising it further mostly buys
            // slow uploads on the mobile connections this app is used on.
            'photo' => 'required|file|mimes:jpg,jpeg,png|max:4096',
        ]);

        $file = $request->file('photo');

        $path = $file->storeAs(
            'resident-photos/'.$user->getKey(),
            (string) Str::uuid().'.'.$file->extension(),
            self::privateDisk()
        );

        if (! $path) {
            return response()->json(['message' => 'Could not store the photo.'], 500);
        }

        // Read the old path before overwriting it: once the column is updated
        // nothing points at the previous file and it would sit on the disk for
        // the life of the deployment.
        $previous = $user->photo;

        $user->photo = $path;
        $user->save();

        $this->discardUpload($previous);

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

        $previous = $user->photo;

        $user->photo = null;
        $user->save();

        $this->discardUpload($previous);

        return response()->json([
            'message' => 'Profile photo removed.',
            'user' => $user->load('barangay'),
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
