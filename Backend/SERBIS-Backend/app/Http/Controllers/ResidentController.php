<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use Illuminate\Http\Request;

class ResidentController extends Controller
{
    public function index()
    {
        $residents = Resident::with('barangay')->get();
        return response()->json($residents);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'barangay_id' => 'required|integer|exists:tbl_barangay,barangay_id',
            'first_name' => 'required|string',
            'middle_name' => 'nullable|string',
            'last_name' => 'required|string',
            'phone_number' => 'required|string',
            'email_address' => 'required|email|unique:tbl_residents,email_address',
            'password' => 'required|string|min:8',
            'photo' => 'nullable|string',
            'status' => 'required|string',
            'otp' => 'nullable|string',
            'otp_verified_at' => 'nullable|date',
        ]);

        $validated['password'] = bcrypt($validated['password']);

        $resident = Resident::create($validated);

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
    'password' => 'nullable|string|min:8', // Must be nullable on update
]);

if (!empty($validated['password'])) {
    $validated['password'] = bcrypt($validated['password']);
} else {
    unset($validated['password']); // Prevent overwriting with null
}

$resident->update($validated);

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