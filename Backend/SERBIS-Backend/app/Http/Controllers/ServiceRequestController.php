<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;

class ServiceRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user instanceof \App\Models\User && $user->role === 'admin') {
            $serviceRequests = ServiceRequest::with(['resident', 'service', 'admin'])->get();
        } else {
            $residentId = $user->getKey();
            $serviceRequests = ServiceRequest::with(['resident', 'service', 'admin'])
                ->where('resident_id', $residentId)
                ->get();
        }
        
        return response()->json($serviceRequests);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:tbl_services,service_id',
            'description' => 'required|string',
            'valid_id' => 'required|file|mimes:jpg,jpeg,png|max:2048', 
        ]);

        // Handle the file upload
        $filePath = null;
        if ($request->hasFile('valid_id')) {
            $filePath = $request->file('valid_id')->store('ids', 'public');
        }

        $serviceRequest = ServiceRequest::create([
            'resident_id' => auth()->id(),
            'service_id' => $validated['service_id'],
            'description' => $validated['description'],
            'valid_id' => $filePath, // Save the generated file path
            'status' => 'Pending',
            'processed_by' => null,
        ]);

        return response()->json($serviceRequest, 201);
    }

    public function show($id)
    {
        $serviceRequest = ServiceRequest::with(['resident', 'service', 'admin'])->find($id);

        if (!$serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        return response()->json($serviceRequest);
    }

    public function update(Request $request, $id)
    {
        $serviceRequest = ServiceRequest::find($id);

        if (!$serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        $validated = $request->validate([
            'resident_id' => 'sometimes|required|integer|exists:tbl_residents,resident_id',
            'service_id' => 'sometimes|required|integer|exists:tbl_services,service_id',
            'processed_by' => 'nullable|integer|exists:tbl_user,admin_id',
            'description' => 'nullable|string',
            'valid_id' => 'nullable|string|max:255',
            'status' => 'sometimes|required|string|max:50',
            'remarks' => 'nullable|string',
        ]);

        $serviceRequest->update($validated);

        return response()->json($serviceRequest);
    }

    public function destroy($id)
    {
        $serviceRequest = ServiceRequest::find($id);

        if (!$serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        $serviceRequest->delete();

        return response()->json(['message' => 'Service request successfully deleted']);
    }
}