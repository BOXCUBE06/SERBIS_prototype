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
        // Sorted here so every picker (app register, admin filters) is in the same order.
        return response()->json(Barangay::orderBy('barangay_name')->get());
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

        // Every key onto a barangay is RESTRICT, so an unchecked delete of one
        // in use was an uncaught 500. Same guard as ServiceController::destroy().
        $residentCount = DB::table('tbl_residents')->where('barangay_id', $id)->count();

        if ($residentCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$residentCount} resident(s) still reference this barangay.",
            ], 422);
        }

        $requestCount = DB::table('tbl_service_request')->where('barangay_id', $id)->count();

        if ($requestCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$requestCount} service request(s) were filed under this barangay.",
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
