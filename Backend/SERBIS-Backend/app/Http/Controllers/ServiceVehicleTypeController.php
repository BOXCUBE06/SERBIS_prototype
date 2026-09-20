<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceVehicleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The admin side of the vehicle mapping: which kinds of unit may be sent on each
 * service. Admin-only (see routes/api.php). The dispatch picker reads the same
 * list, and ServiceRequestController::update enforces it.
 */
class ServiceVehicleTypeController extends Controller
{
    /** The ambulance service is fixed to Ambulance units, so it is not listed. */
    private const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response';

    /**
     * Every service that dispatches a unit, each with its mapped types. An empty
     * `vehicle_types` means any non-ambulance unit. Programs are left out: they
     * take no vehicle.
     */
    public function index()
    {
        $rows = Service::orderBy('service_id')
            ->where('code', '!=', self::AMBULANCE_SERVICE_CODE)
            ->where('category', '!=', 'programs')
            ->get(['service_id', 'service_name', 'code', 'is_active'])
            ->map(fn (Service $service) => [
                'code' => $service->code,
                'name' => $service->service_name,
                'is_active' => (bool) $service->is_active,
                'vehicle_types' => ServiceVehicleType::typesFor($service->code),
            ]);

        return response()->json([
            'data' => $rows->values(),
            'types' => ServiceVehicleType::mappableTypes(),
        ]);
    }

    /**
     * Replaces the types for one service. An empty list is allowed and means
     * "any non-ambulance unit", the default a service has before anything is set.
     */
    public function update(Request $request, string $code)
    {
        $service = Service::where('code', $code)->first();

        if (! $service || $code === self::AMBULANCE_SERVICE_CODE || $service->category === 'programs') {
            return response()->json(['message' => 'Service not found'], 404);
        }

        $validated = $request->validate([
            'vehicle_types' => ['present', 'array'],
            'vehicle_types.*' => ['required', 'distinct', Rule::in(ServiceVehicleType::mappableTypes())],
        ]);

        DB::transaction(function () use ($code, $validated) {
            $existing = ServiceVehicleType::where('service_code', $code)->get();

            foreach ($existing->whereNotIn('vehicle_type', $validated['vehicle_types']) as $row) {
                $row->delete();
            }

            foreach ($validated['vehicle_types'] as $type) {
                if ($existing->where('vehicle_type', $type)->isEmpty()) {
                    ServiceVehicleType::create(['service_code' => $code, 'vehicle_type' => $type]);
                }
            }
        });

        return response()->json([
            'code' => $code,
            'vehicle_types' => ServiceVehicleType::typesFor($code),
        ]);
    }
}
