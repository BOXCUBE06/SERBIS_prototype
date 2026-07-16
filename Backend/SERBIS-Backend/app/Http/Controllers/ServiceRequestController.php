<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServiceRequestController extends Controller
{
   public function adminIndex()
    {
        // Added 'resident.barangay'
        $requests = ServiceRequest::with(['resident.barangay', 'service', 'admin', 'vehicle'])
            ->latest()
            ->get();
            
        return response()->json(['data' => $requests]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user instanceof \App\Models\User && $user->role === 'admin') {
            // Added 'resident.barangay'
            $serviceRequests = ServiceRequest::with(['resident.barangay', 'service', 'admin'])->get();
        } else {
            $residentId = $user->getKey();
            // Added 'resident.barangay'
            $serviceRequests = ServiceRequest::with(['resident.barangay', 'service', 'admin'])
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
            'required_vehicle_type' => 'nullable|string|exists:tbl_vehicles,type',
        ]);

        $filePath = null;
        if ($request->hasFile('valid_id')) {
            $file = $request->file('valid_id');

            // Private disk: government ID photos must never be reachable by URL.
            $filePath = $file->storeAs(
                'valid-ids/'.$request->user()->getKey(),
                (string) Str::uuid().'.'.$file->extension(),
                'local'
            );
        }

        $serviceRequest = DB::transaction(function () use ($request, $validated, $filePath) {
            $vehicle = null;
            $vehicleId = null;

            if (!empty($validated['required_vehicle_type'])) {
                $vehicle = Vehicle::where('type', $validated['required_vehicle_type'])
                                  ->where('status', 'Available')
                                  ->lockForUpdate()
                                  ->first();

                if (!$vehicle) {
                    return false; 
                }
                $vehicleId = $vehicle->vehicle_id;
            }

            $newServiceRequest = ServiceRequest::create([
                'resident_id' => $request->user()->getKey(),
                'service_id' => $validated['service_id'],
                'description' => $validated['description'],
                'valid_id' => $filePath,
                'status' => 'Pending',
                'processed_by' => null,
                'vehicle_id' => $vehicleId,
            ]);

            if ($vehicle) {
                $vehicle->update(['status' => 'Dispatched']);
            }

            return $newServiceRequest;
        });

        if ($serviceRequest === false) {
            return response()->json(['message' => 'No available vehicles at this time.'], 422);
        }

        return response()->json($serviceRequest, 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        // Added 'resident.barangay'
        $query = ServiceRequest::with(['resident.barangay', 'service', 'admin']);

        if ($user instanceof \App\Models\Resident) {
            $query->where('resident_id', $user->getKey());
        }

        $serviceRequest = $query->find($id);

        if (!$serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        return response()->json($serviceRequest);
    }

    public function validId(Request $request, $id)
    {
        $user = $request->user();

        $query = ServiceRequest::query();

        if ($user instanceof \App\Models\Resident) {
            $query->where('resident_id', $user->getKey());
        }

        $serviceRequest = $query->find($id);

        // 404 rather than 403 for a non-owner, so the response does not disclose
        // that the request exists.
        if (!$serviceRequest || !$serviceRequest->valid_id) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if (!Storage::disk('local')->exists($serviceRequest->valid_id)) {
            return response()->json(['message' => 'Valid ID file not found'], 404);
        }

        return Storage::disk('local')->response($serviceRequest->valid_id);
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
            // 'valid_id' is deliberately not accepted here. It is a storage path written
            // only by store(); allowing it to be set would let any admin point it at an
            // arbitrary file for validId() to stream back.
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