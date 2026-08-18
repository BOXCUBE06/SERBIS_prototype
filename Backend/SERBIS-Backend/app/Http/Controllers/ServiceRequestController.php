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
    /**
     * The status column's whole vocabulary. Seeders, the admin panel's tabs and
     * the mobile ReqStatus enum all already agree on these five; the column was
     * simply never constrained to them, so a typo in a client wrote a status no
     * screen could render and no filter could find.
     */
    private const STATUSES = ['Pending', 'Responding', 'Resolved', 'Cancelled', 'Disapproved'];

    /**
     * Statuses that end the request. A unit held by one of these is not coming
     * back on its own — nothing else in the system ever returns it to the fleet,
     * so every vehicle dispatched was leaving Available permanently and the
     * picker emptied out after one dispatch per vehicle.
     */
    private const TERMINAL_STATUSES = ['Resolved', 'Cancelled', 'Disapproved'];

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
            // Optional second upload: a photo of the site, for the road-clearing
            // form. Not required, because most requests are filed in conditions
            // where stopping to photograph anything is the wrong advice.
            'site_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:4096',
            'required_vehicle_type' => 'nullable|string|exists:tbl_vehicles,type',
        ]);

        $filePath = null;
        if ($request->hasFile('valid_id')) {
            $file = $request->file('valid_id');

            // Private disk: government ID photos must never be reachable by URL.
            $filePath = $file->storeAs(
                'valid-ids/'.$request->user()->getKey(),
                (string) Str::uuid().'.'.$file->extension(),
                self::privateDisk()
            );
        }

        $sitePhotoPath = null;
        if ($request->hasFile('site_photo')) {
            $photo = $request->file('site_photo');

            $sitePhotoPath = $photo->storeAs(
                'site-photos/'.$request->user()->getKey(),
                (string) Str::uuid().'.'.$photo->extension(),
                self::privateDisk()
            );
        }

        // The upload has to happen before the transaction — it is a filesystem
        // write, so a rollback does not undo it. Every path out of here that does
        // not create a row must therefore delete the file by hand, or a failed
        // submit leaves a government ID photo on disk that nothing points at and
        // nothing ever cleans up. The no-vehicle path below is not an edge case:
        // it fires whenever the fleet is busy, which is exactly when people file.
        try {
            $serviceRequest = DB::transaction(function () use ($request, $validated, $filePath, $sitePhotoPath) {
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
                    'site_photo' => $sitePhotoPath,
                    'status' => 'Pending',
                    'processed_by' => null,
                    'vehicle_id' => $vehicleId,
                ]);

                if ($vehicle) {
                    $vehicle->update(['status' => 'Dispatched']);
                }

                return $newServiceRequest;
            });
        } catch (\Throwable $e) {
            $this->discardUpload($filePath);
            $this->discardUpload($sitePhotoPath);

            throw $e;
        }

        if ($serviceRequest === false) {
            $this->discardUpload($filePath);
            $this->discardUpload($sitePhotoPath);

            return response()->json(['message' => 'No available vehicles at this time.'], 422);
        }

        return response()->json($serviceRequest, 201);
    }

    // Deleting the upload is best-effort on purpose: the caller is already on a
    // failure path, and a storage error here would replace the real reason for
    // the failure with a misleading one.
    private function discardUpload(?string $filePath): void
    {
        if (!$filePath) {
            return;
        }

        try {
            Storage::disk(self::privateDisk())->delete($filePath);
        } catch (\Throwable) {
            // Leaving the file behind is the lesser failure.
        }
    }

    // Government ID scans live wherever the deployment says. On a host with an
    // ephemeral filesystem this must be object storage, or every scan is lost
    // at the next deploy while the request rows that reference them survive.
    private static function privateDisk(): string
    {
        return config('filesystems.uploads.private');
    }

    /**
     * Staff-filed requests for a walk-in — someone at the office counter
     * rather than the mobile app. Kept separate from store() rather than
     * branching that method on caller type: store() stays exactly what a
     * resident's own submission looks like, and this is exactly what a
     * staffer's looks like. The two differ in more than who resident_id
     * belongs to — valid_id is optional here because the staffer already
     * checked the ID in person, which store() must never assume.
     */
    public function adminStore(Request $request)
    {
        $validated = $request->validate([
            'resident_id' => 'nullable|integer|exists:tbl_residents,resident_id',
            // Required only when there is no account to pull them from.
            'walk_in_name' => 'required_without:resident_id|nullable|string|max:255',
            'walk_in_contact_number' => 'required_without:resident_id|nullable|string|max:32',
            'service_id' => 'required|exists:tbl_services,service_id',
            'description' => 'required|string',
            'valid_id' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'site_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:4096',
            'required_vehicle_type' => 'nullable|string|exists:tbl_vehicles,type',
        ]);

        $residentId = $validated['resident_id'] ?? null;
        // Walk-in fields are dropped rather than merely left unvalidated when a
        // resident is picked — a mistyped name left over from switching the
        // form's mode must not sit next to a linked account pretending to be
        // a fact about it.
        $walkInName = $residentId ? null : ($validated['walk_in_name'] ?? null);
        $walkInContact = $residentId ? null : ($validated['walk_in_contact_number'] ?? null);
        $ownerSegment = $residentId ?: 'walk-in';

        $filePath = null;
        if ($request->hasFile('valid_id')) {
            $file = $request->file('valid_id');
            $filePath = $file->storeAs(
                'valid-ids/'.$ownerSegment,
                (string) Str::uuid().'.'.$file->extension(),
                self::privateDisk()
            );
        }

        $sitePhotoPath = null;
        if ($request->hasFile('site_photo')) {
            $photo = $request->file('site_photo');
            $sitePhotoPath = $photo->storeAs(
                'site-photos/'.$ownerSegment,
                (string) Str::uuid().'.'.$photo->extension(),
                self::privateDisk()
            );
        }

        try {
            $serviceRequest = DB::transaction(function () use ($validated, $residentId, $walkInName, $walkInContact, $filePath, $sitePhotoPath) {
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
                    'resident_id' => $residentId,
                    'walk_in_name' => $walkInName,
                    'walk_in_contact_number' => $walkInContact,
                    'service_id' => $validated['service_id'],
                    'description' => $validated['description'],
                    'valid_id' => $filePath,
                    'site_photo' => $sitePhotoPath,
                    'status' => 'Pending',
                    'processed_by' => null,
                    'vehicle_id' => $vehicleId,
                ]);

                if ($vehicle) {
                    $vehicle->update(['status' => 'Dispatched']);
                }

                return $newServiceRequest;
            });
        } catch (\Throwable $e) {
            $this->discardUpload($filePath);
            $this->discardUpload($sitePhotoPath);

            throw $e;
        }

        if ($serviceRequest === false) {
            $this->discardUpload($filePath);
            $this->discardUpload($sitePhotoPath);

            return response()->json(['message' => 'No available vehicles at this time.'], 422);
        }

        return response()->json($serviceRequest->load(['resident.barangay', 'service']), 201);
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

        if (!Storage::disk(self::privateDisk())->exists($serviceRequest->valid_id)) {
            return response()->json(['message' => 'Valid ID file not found'], 404);
        }

        return Storage::disk(self::privateDisk())->response($serviceRequest->valid_id);
    }

    // Same ownership rules as validId(). A site photo is less sensitive than a
    // government ID, but it still shows a named resident's street, and serving
    // it by public URL would be the mistake audit #8 already cost us once.
    public function sitePhoto(Request $request, $id)
    {
        $user = $request->user();

        $query = ServiceRequest::query();

        if ($user instanceof \App\Models\Resident) {
            $query->where('resident_id', $user->getKey());
        }

        $serviceRequest = $query->find($id);

        // 404 rather than 403 for a non-owner, so the response does not disclose
        // that the request exists.
        if (!$serviceRequest || !$serviceRequest->site_photo) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if (!Storage::disk(self::privateDisk())->exists($serviceRequest->site_photo)) {
            return response()->json(['message' => 'Site photo file not found'], 404);
        }

        return Storage::disk(self::privateDisk())->response($serviceRequest->site_photo);
    }

    // Resident-facing cancel, kept separate from update() on purpose: update() is
    // admin-only and accepts resident_id, processed_by and an arbitrary status, so
    // opening it to residents would let one rewrite another resident's request.
    // This route writes exactly one value.
    public function cancel(Request $request, $id)
    {
        $user = $request->user();

        $query = ServiceRequest::query();

        if ($user instanceof \App\Models\Resident) {
            $query->where('resident_id', $user->getKey());
        }

        $serviceRequest = $query->find($id);

        // 404 rather than 403 for a non-owner, matching show() and validId(): the
        // response must not disclose that the request exists.
        if (!$serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        // Once a unit is Responding the cancellation is an operational decision,
        // not a resident one — the crew is already moving.
        if ($serviceRequest->status !== 'Pending') {
            return response()->json([
                'message' => 'Only a pending request can be cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($serviceRequest) {
            // store() can attach and dispatch a vehicle while the request is still
            // Pending, so cancelling has to hand the unit back or it leaks out of
            // the fleet with no request pointing at it.
            if ($serviceRequest->vehicle_id) {
                $vehicle = Vehicle::where('vehicle_id', $serviceRequest->vehicle_id)
                    ->lockForUpdate()
                    ->first();

                if ($vehicle && $vehicle->status === 'Dispatched') {
                    $vehicle->update(['status' => 'Available']);
                }
            }

            $serviceRequest->update(['status' => 'Cancelled']);
        });

        return response()->json($serviceRequest);
    }

    /**
     * Keeps tbl_vehicles in step with the request being updated.
     *
     * Runs inside update()'s transaction and takes the same lockForUpdate() that
     * store() and cancel() take, so two admins dispatching at once cannot both
     * claim the same unit.
     *
     * Three cases, in this order:
     *   1. the request moves to a terminal status  — hand the unit back
     *   2. the attached unit is being swapped      — hand the old one back
     *   3. a unit is attached and the request is live — mark it Dispatched
     *
     * Order matters: a terminal update that also carries a vehicle_id (the panel
     * sends the current one on every PUT, including Disapprove) must release,
     * not re-dispatch.
     */
    private function syncFleet(ServiceRequest $serviceRequest, array $validated): void
    {
        $currentVehicleId = $serviceRequest->vehicle_id;
        $incomingVehicleId = array_key_exists('vehicle_id', $validated)
            ? $validated['vehicle_id']
            : $currentVehicleId;

        $status = $validated['status'] ?? $serviceRequest->status;
        $isTerminal = in_array($status, self::TERMINAL_STATUSES, true);

        if ($isTerminal) {
            $this->releaseVehicle($currentVehicleId);
            $this->releaseVehicle($incomingVehicleId);

            return;
        }

        if ($currentVehicleId && $currentVehicleId !== $incomingVehicleId) {
            $this->releaseVehicle($currentVehicleId);
        }

        if ($incomingVehicleId) {
            $vehicle = Vehicle::where('vehicle_id', $incomingVehicleId)
                ->lockForUpdate()
                ->first();

            // Only Available is promoted. A unit already Dispatched to this same
            // request stays as it is, and one under Maintenance is not quietly
            // pressed into service by a status change.
            if ($vehicle && $vehicle->status === 'Available') {
                $vehicle->update(['status' => 'Dispatched']);
            }
        }
    }

    /** Returns a dispatched unit to the fleet. Ignores one already Available. */
    private function releaseVehicle(?int $vehicleId): void
    {
        if (!$vehicleId) {
            return;
        }

        $vehicle = Vehicle::where('vehicle_id', $vehicleId)
            ->lockForUpdate()
            ->first();

        if ($vehicle && $vehicle->status === 'Dispatched') {
            $vehicle->update(['status' => 'Available']);
        }
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
            //
            // The panel has always sent vehicle_id with every dispatch, but it was
            // absent from these rules, so validate() dropped it and the request was
            // never attached to the unit that answered it. The detail panel then read
            // back "Vehicle Unknown".
            'vehicle_id' => 'nullable|integer|exists:tbl_vehicles,vehicle_id',
            'status' => 'sometimes|required|in:'.implode(',', self::STATUSES),
            'remarks' => 'nullable|string',
        ]);

        DB::transaction(function () use ($serviceRequest, $validated) {
            $this->syncFleet($serviceRequest, $validated);

            $serviceRequest->update($validated);
        });

        return response()->json($serviceRequest->fresh(['vehicle']));
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