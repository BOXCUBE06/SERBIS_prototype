<?php

namespace App\Http\Controllers;

use App\Models\User; // Represents Admins/Staff
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    // Resident self-registration for the mobile app. Admins live in tbl_user and
    // are deliberately not creatable here — there is no public route that writes
    // to that table.
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name'    => 'required|string|max:255',
            'middle_name'   => 'nullable|string|max:255',
            'last_name'     => 'required|string|max:255',
            'barangay_id'   => 'required|integer|exists:tbl_barangay,barangay_id',
            'phone_number'  => 'required|string|max:20',
            'email_address' => 'required|email|unique:tbl_residents,email_address',
            'password'      => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Every column is assigned explicitly rather than splatting $validated, so
        // no extra key in the payload can reach a column. Two matter in particular:
        // `status` (see below) and `role`, which the mobile client currently sends
        // and which must never be client-settable.
        $resident = Resident::create([
            'barangay_id'   => $validated['barangay_id'],
            'first_name'    => $validated['first_name'],
            'middle_name'   => $validated['middle_name'] ?? null,
            'last_name'     => $validated['last_name'],
            'phone_number'  => $validated['phone_number'],
            'email_address' => $validated['email_address'],
            'password'      => Hash::make($validated['password']),
            // Starts Inactive on purpose. SmsController only blasts residents with
            // status 'Active', and SkySMS bills per real send with no sandbox, so a
            // self-registered account must not opt an unverified phone number into
            // paid SMS until an admin activates it from the Users view.
            'status'        => 'Inactive',
        ]);

        // No token and no 'message' key, both deliberate. The mobile client reads any
        // `message` on this response as an error to show the resident, and a token
        // issued here would never be stored by that client — it would just be a
        // non-expiring credential nobody holds. The resident logs in straight after.
        return response()->json($resident, 201);
    }

    // Lets a client rebuild the signed-in user from a stored token. Without this,
    // restoring a session yields a valid token attached to an empty profile,
    // because login is the only place the user object is ever returned.
    public function me(Request $request)
    {
        $user = $request->user();

        if ($user instanceof Resident) {
            return response()->json([
                'role' => 'resident',
                // Residents have no address column; location is the barangay relation.
                'user' => $user->load('barangay'),
            ]);
        }

        return response()->json([
            'role' => 'admin',
            'user' => $user,
        ]);
    }

    // Resident-scoped profile update. Deliberately not part of the admin
    // ResidentController::update(), which accepts status and barangay_id and is
    // reachable only behind is.admin — opening that to residents would hand every
    // resident the admin's own write surface.
    public function updateMe(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        $validated = $request->validate([
            'first_name'    => 'sometimes|required|string|max:255',
            'middle_name'   => 'nullable|string|max:255',
            'last_name'     => 'sometimes|required|string|max:255',
            'phone_number'  => 'sometimes|required|string|max:20',
            'email_address' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('tbl_residents', 'email_address')->ignore($user->getKey(), 'resident_id'),
            ],
        ]);

        // Assigned key by key, never a splat of $validated. Four columns are
        // absent from the rules above and must stay that way:
        //
        //   barangay_id  every service request is dispatched on it, so a resident
        //                who could move themselves could redirect their own
        //                dispatch. Changing barangay is an MDRRMO operation.
        //   status       an Inactive account could otherwise activate itself and
        //                opt an unverified number into billed SMS.
        //   photo        it is a storage path now, not a value anyone types.
        //                POST /me/photo owns it — see ResidentController.
        //   password     a change needs the current password, which is a separate
        //                endpoint, not a field on a profile PATCH.
        foreach (['first_name', 'middle_name', 'last_name', 'phone_number', 'email_address'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $validated[$field];
            }
        }

        $user->save();

        return response()->json([
            'role' => 'resident',
            'user' => $user->load('barangay'),
        ]);
    }

    // Both logins issue a token with an explicit expiry (audit #30). Before this,
    // a token was valid forever: logout revoked the one in hand, but any copy
    // taken beforehand — from localStorage via XSS, a shared machine, a proxy log
    // — stayed usable indefinitely. The two lifetimes differ on purpose; see
    // config/sanctum.php for why. The client-side expiry in the Vue app is
    // browser hygiene and is not what enforces this.

    // Endpoint specifically for the Web Frontend
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
            'password' => 'required',
        ]);

        $admin = User::where('email_address', $request->email_address)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Unauthorized. MDRRMO Admin access only.'
            ], 401);
        }

        // A closed account (audit #29). Named rather than folded into the line
        // above on purpose: the caller has already proved the password, so this
        // discloses nothing they did not know, and "wrong credentials" would
        // send a former employee to reset a password that was never the
        // problem. Deactivation is how a departing employee's access ends —
        // the row cannot be deleted while the audit trail points at it.
        if ($admin->isDeactivated()) {
            return response()->json([
                'message' => 'This account has been deactivated. Contact another MDRRMO admin.'
            ], 403);
        }

        return response()->json([
            'token' => $admin->createToken(
                'admin-token',
                ['*'],
                now()->addMinutes(config('sanctum.admin_expiration')),
            )->plainTextToken,
            'role' => 'admin',
            'user' => $admin
        ], 200);
    }

    // Endpoint specifically for the Mobile App
    public function residentLogin(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
            'password' => 'required',
        ]);

        $resident = Resident::where('email_address', $request->email_address)->first();

        if (!$resident || !Hash::check($request->password, $resident->password)) {
            return response()->json([
                'message' => 'Invalid resident credentials.'
            ], 401);
        }

        return response()->json([
            'token' => $resident->createToken(
                'resident-token',
                ['*'],
                now()->addMinutes(config('sanctum.resident_expiration')),
            )->plainTextToken,
            'role' => 'resident',
            // Load the barangay relation so the mobile profile has a location on
            // login, matching what /me returns. Residents have no address column.
            'user' => $resident->load('barangay')
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }
}