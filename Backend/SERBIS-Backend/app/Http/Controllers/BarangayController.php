<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BarangayController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Barangay::all());
    }

    /**
     * Type-ahead source for the purok/street field (MDRRMO feedback,
     * 2026-09-19): every distinct, non-empty `street_address` a resident of
     * this barangay has already entered. No seed data — the list builds
     * itself from real entries, so a barangay nobody has registered a purok
     * for yet simply offers nothing.
     *
     * Public, same as index() above: the registration form needs suggestions
     * before the resident has an account to authenticate with. Only bare
     * strings come back — no resident_id, no name — so this discloses
     * nothing more sensitive than a street name already typed by someone
     * else in the same barangay.
     */
    public function puroks($id)
    {
        Barangay::findOrFail($id);

        $puroks = DB::table('tbl_residents')
            ->where('barangay_id', $id)
            ->whereNotNull('street_address')
            ->where('street_address', '!=', '')
            ->distinct()
            ->orderBy('street_address')
            ->limit(50)
            ->pluck('street_address');

        return response()->json(['data' => $puroks]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'barangay_name' => 'required|string|max:255',
        ]);

        $barangay = Barangay::create($validated);

        return response()->json($barangay, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $barangay = Barangay::findOrFail($id);

        return response()->json($barangay);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $barangay = Barangay::findOrFail($id);

        $validated = $request->validate([
            'barangay_name' => 'required|string|max:255',
        ]);

        $barangay->update($validated);

        return response()->json($barangay);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $barangay = Barangay::findOrFail($id);

        // Both keys onto a barangay are RESTRICT, so an unchecked delete of one
        // in use was an uncaught 500. Same guard as ServiceController::destroy().
        $residentCount = DB::table('tbl_residents')->where('barangay_id', $id)->count();

        if ($residentCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$residentCount} resident(s) still reference this barangay.",
            ], 422);
        }

        $smsCount = DB::table('tbl_sms_logs')->where('target_area_id', $id)->count();

        if ($smsCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$smsCount} SMS blast(s) still reference this barangay.",
            ], 422);
        }

        $barangay->delete();

        return response()->json(['message' => 'Barangay deleted successfully']);
    }
}
