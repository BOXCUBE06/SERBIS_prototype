<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        
        $barangay->delete();

        return response()->json(['message' => 'Barangay deleted successfully']);
    }
}
