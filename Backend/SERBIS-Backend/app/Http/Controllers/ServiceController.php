<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Same shared-endpoint-different-audience split as
        // ServiceRequestController::index() — mobile residents only ever see
        // what they can actually file against; the admin panel's Manage
        // Services page needs every row, disabled ones included, or a
        // disabled service could never be re-enabled from there.
        $services = ($user instanceof \App\Models\User && $user->isAdmin())
            ? Service::all()
            : Service::where('is_active', true)->get();

        return ServiceResource::collection($services);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        $service = Service::create($validated);

        return response()->json($service, 201);
    }

    public function show(Request $request, $id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        return new ServiceResource($service);
    }

    public function update(Request $request, $id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        $validated = $request->validate([
            'service_name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'is_active' => 'sometimes|required|boolean',
        ]);

        $service->update($validated);

        return response()->json($service);
    }

    public function destroy($id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        // tbl_service_request.service_id is a plain RESTRICT foreign key, same
        // as resident_id on ResidentController::destroy() — same bug, same fix.
        $requestCount = DB::table('tbl_service_request')->where('service_id', $id)->count();

        if ($requestCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$requestCount} service request(s) still reference this service.",
            ], 422);
        }

        $service->delete();

        return response()->json(['message' => 'Service successfully deleted']);
    }
}