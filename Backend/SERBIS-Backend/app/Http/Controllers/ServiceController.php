<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServiceResource;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceAudience;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $services = ($user instanceof User && $user->isAdmin())
            ? Service::all()
            : Service::where('is_active', true)->get();

        if (! $user instanceof Resident) {
            return ServiceResource::collection($services);
        }

        // Only what this kind of account may request. store() enforces the same
        // rule, so this is a convenience for the app and not the gate.
        $services = $services->filter(fn (Service $s) => ServiceAudience::allows($s->code, $user->account_type))->values();

        // Equipment Borrowing and "Others" are not service rows, so the app is
        // told about them alongside the list.
        return ServiceResource::collection($services)->additional([
            'audience' => [
                'equipment_borrowing' => ServiceAudience::allows(ServiceAudience::EQUIPMENT_BORROWING, $user->account_type),
                'others' => ServiceAudience::allows(ServiceAudience::OTHERS, $user->account_type),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            // Omitted means the column default (relief); the panel always sends it.
            'category' => ['sometimes', 'required', Rule::in(Service::CATEGORIES)],
        ]);

        $service = Service::create($validated);

        return response()->json($service, 201);
    }

    public function show(Request $request, $id)
    {
        $service = Service::find($id);

        if (! $service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        return new ServiceResource($service);
    }

    public function update(Request $request, $id)
    {
        $service = Service::find($id);

        if (! $service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        // Name and category are fixed once a service exists. The name is what
        // push notifications and the admin lists print while the app shows its
        // own translation by code, so a rename splits the two; the category
        // switches dispatch, vehicle mapping and the app's "Approved" wording
        // while the filing rules stay keyed on code. Resending the stored value
        // is harmless and allowed.
        $locked = fn (string $field, string $label) => function (string $attribute, mixed $value, \Closure $fail) use ($service, $field, $label): void {
            if ($value !== $service->{$field}) {
                $fail("The {$label} of an existing service cannot be changed.");
            }
        };

        $validated = $request->validate([
            'service_name' => ['sometimes', 'required', 'string', 'max:255', $locked('service_name', 'name')],
            'description' => 'nullable|string|max:5000',
            'category' => ['sometimes', 'required', Rule::in(Service::CATEGORIES), $locked('category', 'category')],
            'is_active' => 'sometimes|required|boolean',
        ]);

        $service->update($validated);

        return response()->json($service);
    }

    public function destroy($id)
    {
        $service = Service::find($id);

        if (! $service) {
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
