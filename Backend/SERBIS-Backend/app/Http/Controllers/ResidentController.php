<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ResidentController extends Controller
{
    // Profile photos live on the same private disk as the ID scans, for the same
    // reason: on a host with an ephemeral filesystem a local disk loses every
    // file at the next deploy while the rows that reference them survive.
    private static function privateDisk(): string
    {
        return config('filesystems.uploads.private');
    }

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
            'first_name' => 'required|string',
            'middle_name' => 'nullable|string',
            'last_name' => 'required|string',
            'phone_number' => 'required|string',
            'email_address' => 'required|email|unique:tbl_residents,email_address',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'status' => 'required|string',
        ]);

        // Columns assigned one at a time, never a splat of $validated. A splat
        // makes every future addition to the rules — or to $fillable — silently
        // client-settable, which is how the dead 'role' rule reached
        // /api/register. 'status' is deliberately here: this is the admin CRUD,
        // and activating a resident is the admin's job.
        $resident = Resident::create([
            'barangay_id'   => $validated['barangay_id'],
            'first_name'    => $validated['first_name'],
            'middle_name'   => $validated['middle_name'] ?? null,
            'last_name'     => $validated['last_name'],
            'phone_number'  => $validated['phone_number'],
            'email_address' => $validated['email_address'],
            'password'      => bcrypt($validated['password']),
            // No 'photo'. It is the resident's own face, uploaded from the
            // mobile app by POST /api/me/photo; an admin creating the account
            // has no file to attach and no business naming one.
            'status'        => $validated['status'],
        ]);

        return response()->json($resident, 201);
    }

    public function show($id)
    {
        $resident = Resident::with('barangay')->find($id);

        if (!$resident) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        return response()->json($resident);
    }

    public function update(Request $request, $id)
    {
        $resident = Resident::find($id);

        if (!$resident) {
            return response()->json(['message' => 'Resident not found'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'required|string',
            'middle_name' => 'nullable|string',
            'last_name' => 'required|string',
            'phone_number' => 'required|string',
            'email_address' => 'required|email|unique:tbl_residents,email_address,' . $id . ',resident_id',
            'barangay_id' => 'required|integer|exists:tbl_barangay,barangay_id',
            'status' => 'required|string',
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()], // Must be nullable on update
        ]);

        // Explicit, for the same reason as store(). This method never accepted
        // the OTP columns, but it splatted whatever the rules happened to
        // contain, so adding a rule here was one line away from making a new
        // column writable.
        $changes = [
            'first_name'    => $validated['first_name'],
            'middle_name'   => $validated['middle_name'] ?? null,
            'last_name'     => $validated['last_name'],
            'phone_number'  => $validated['phone_number'],
            'email_address' => $validated['email_address'],
            'barangay_id'   => $validated['barangay_id'],
            'status'        => $validated['status'],
        ];

        // An omitted or blank password leaves the stored hash alone; assigning
        // null would lock the resident out of their own account.
        if (!empty($validated['password'])) {
            $changes['password'] = bcrypt($validated['password']);
        }

        $resident->update($changes);

        return response()->json($resident);
    }

    public function destroy($id)
    {
        $resident = Resident::find($id);

        if (!$resident) {
            return response()->json(['message' => 'Resident not found'], 404);
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
        if ($user instanceof Resident && (string) $user->getKey() !== (string) $id) {
            return response()->json(['message' => 'Resident not found'], 404);
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