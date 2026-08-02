<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ResidentController extends Controller
{
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
            'photo' => 'nullable|string',
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
            'photo'         => $validated['photo'] ?? null,
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
}