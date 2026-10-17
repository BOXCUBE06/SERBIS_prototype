<?php

namespace App\Services;

use App\Http\Controllers\ServiceRequestController;
use App\Models\ConductionRequest;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one way a service request reaches Responding, ambulance or not, from
 * ServiceRequestController::update() and ConductionRequestController::store().
 * Busy is read from requests and trips, never from tbl_vehicles.status.
 */
class VehicleDispatch
{
    /** Unique index on tbl_service_request.active_vehicle_id (migration 2026_10_02_100000). */
    public const ACTIVE_UNIT_INDEX = 'tbl_service_request_active_vehicle_unique';

    public function __construct(private readonly Fcm $fcm) {}

    /**
     * @param  (Closure(ServiceRequest): ConductionRequest)|null  $createTrip  ambulance only; the request
     *                                                                         already carries its vehicle_id
     */
    public function dispatch(ServiceRequest $request, ?int $vehicleId, bool $isAmbulance, ?Closure $createTrip = null): ?ConductionRequest
    {
        if ($isAmbulance && ! $vehicleId) {
            throw ValidationException::withMessages(['vehicle_id' => 'Assign a unit before dispatching.']);
        }

        if ($isAmbulance && $request->status === 'Booked' && ! $request->ambulanceBooking?->approved_at) {
            throw ValidationException::withMessages(['status' => 'This booking must be approved before it can be dispatched.']);
        }

        try {
            $trip = $this->run($request, $vehicleId, $createTrip);
        } catch (QueryException $e) {
            // Last line behind lockFree(): the DB's one-Responding-request-per-unit index.
            if (($e->errorInfo[1] ?? null) !== 1062 || ! str_contains($e->getMessage(), self::ACTIVE_UNIT_INDEX)) {
                throw $e;
            }

            throw new HttpResponseException(response()->json([
                'message' => Vehicle::whereKey($vehicleId)->value('unit_identifier').' is already responding to another request.',
            ], 409));
        }

        // After the outermost commit, so a rolled-back dispatch sends nothing.
        DB::afterCommit(fn () => $this->fcm->notifyResident(
            $request->resident_id,
            ServiceRequestController::PUSH_TITLE,
            ServiceRequestController::respondingPushBody($request),
            ServiceRequestController::pushData($request),
        ));

        return $trip;
    }

    private function run(ServiceRequest $request, ?int $vehicleId, ?Closure $createTrip): ?ConductionRequest
    {
        return DB::transaction(function () use ($request, $vehicleId, $createTrip) {
            $vehicle = $vehicleId ? $this->lockFree($vehicleId, $request->getKey()) : null;

            // Swapped or dropped unit: hand the old one back.
            if ($request->vehicle_id && $request->vehicle_id !== $vehicleId) {
                Vehicle::whereKey($request->vehicle_id)->where('status', 'Dispatched')->update(['status' => 'Available']);
            }

            $vehicle?->update(['status' => 'Dispatched']);

            $request->update(['vehicle_id' => $vehicleId, 'status' => 'Responding']);

            return $createTrip?->__invoke($request);
        });
    }

    /**
     * Locks the unit and refuses it (409) when busy. Call inside a transaction.
     * $exceptRequestId / $exceptTripId: the request or trip being moved, which
     * may already hold the unit.
     */
    public function lockFree(int $vehicleId, ?int $exceptRequestId = null, ?int $exceptTripId = null): Vehicle
    {
        $vehicle = Vehicle::whereKey($vehicleId)->lockForUpdate()->firstOrFail();

        if ($vehicle->status === 'Maintenance') {
            throw ValidationException::withMessages(['vehicle_id' => 'That unit is under Maintenance and cannot be assigned.']);
        }

        $busy = $this->busy([$vehicleId], $exceptRequestId, $exceptTripId, lock: true)[$vehicleId] ?? null;
        $requestId = $busy['request_id'] ?? null;
        $tripId = $busy['trip_id'] ?? null;

        if ($requestId || $tripId) {
            throw new HttpResponseException(response()->json([
                'message' => $requestId
                    ? "{$vehicle->unit_identifier} is already responding to request #{$requestId}."
                    : "{$vehicle->unit_identifier} is already out on trip #{$tripId}.",
            ], 409));
        }

        return $vehicle;
    }

    /**
     * Ids of units that are busy right now: the one rule lockFree() enforces and
     * the pickers' "On trip" flag reads, so the two cannot disagree.
     *
     * @return list<int>
     */
    public function busyVehicleIds(?int $exceptRequestId = null): array
    {
        return array_keys($this->busy(null, $exceptRequestId));
    }

    /**
     * @param  list<int>|null  $onlyIds  null = every unit
     * @return array<int, array{request_id: ?int, trip_id: ?int}> keyed by vehicle_id
     */
    private function busy(?array $onlyIds, ?int $exceptRequestId = null, ?int $exceptTripId = null, bool $lock = false): array
    {
        $busy = [];

        // Busy: another Responding request holds it...
        $requests = ServiceRequest::whereNotNull('vehicle_id')
            ->where('status', 'Responding')
            ->when($onlyIds, fn ($q) => $q->whereIn('vehicle_id', $onlyIds))
            ->when($exceptRequestId, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['request_id', 'vehicle_id']);

        foreach ($requests as $r) {
            $busy[$r->vehicle_id] ??= ['request_id' => $r->request_id, 'trip_id' => null];
        }

        // ...or it is out on a trip with no request behind it: departed, not back.
        $trips = ConductionRequest::whereNotNull('vehicle_id')
            ->whereNull('service_request_id')
            ->whereNotNull('departed_office_at')
            ->whereNull('returned_office_at')
            ->when($onlyIds, fn ($q) => $q->whereIn('vehicle_id', $onlyIds))
            ->when($exceptTripId, fn ($q) => $q->whereKeyNot($exceptTripId))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['conduction_request_id', 'vehicle_id']);

        foreach ($trips as $t) {
            $busy[$t->vehicle_id] ??= ['request_id' => null, 'trip_id' => $t->conduction_request_id];
        }

        return $busy;
    }
}
