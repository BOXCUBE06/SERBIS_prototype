<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\ServiceRequest;
use App\Services\AmbulanceAvailability;
use App\Services\PhilSms;
use App\Traits\ResolvesUploadDisks;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceRequestController extends Controller
{
    use ResolvesUploadDisks;

    public function __construct(private readonly AmbulanceAvailability $availability)
    {
    }

    /**
     * The wall clock a resident's `scheduled_at` is typed against. Duplicated
     * from ConductionRequestController::OFFICE_TIMEZONE and
     * AmbulanceAvailabilityController::OFFICE_TIMEZONE rather than shared —
     * all three are the same fixed IANA name, not a business rule that could
     * drift.
     */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    /** How soon a resident may book. Anything closer is an emergency, not a schedule. */
    private const MINIMUM_LEAD_TIME_HOURS = 1;

    /** The window an availability check uses for a booking, until approval sets a real scheduled_end. */
    private const DEFAULT_BOOKING_HOURS = 2;

    /** How close to scheduled_at a resident may still back out on their own. */
    private const CANCEL_CUTOFF_HOURS = 2;

    /**
     * The status column's whole vocabulary. Seeders, the admin panel's tabs and
     * the mobile ReqStatus enum all already agree on these five; the column was
     * simply never constrained to them, so a typo in a client wrote a status no
     * screen could render and no filter could find.
     */
    private const STATUSES = ['Pending', 'Booked', 'Responding', 'Resolved', 'Cancelled', 'Disapproved'];

    /**
     * Statuses that end the request. A unit held by one of these is not coming
     * back on its own — nothing else in the system ever returns it to the fleet,
     * so every vehicle dispatched was leaving Available permanently and the
     * picker emptied out after one dispatch per vehicle.
     *
     * Public: App\Services\AmbulanceAvailability reads this list rather than
     * keeping its own copy, so the two cannot drift apart.
     */
    public const TERMINAL_STATUSES = ['Resolved', 'Cancelled', 'Disapproved'];

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

        if ($user instanceof \App\Models\User && $user->isAdmin()) {
            // Added 'resident.barangay'
            $serviceRequests = ServiceRequest::with(['resident.barangay', 'service', 'admin'])->get();
        } else {
            $residentId = $user->getKey();
            // Added 'resident.barangay'
            //
            // internal_notes is the operator-only scratch pad (see its migration) —
            // hidden here rather than on the model, since adminIndex() and this
            // same method's admin branch above both need it visible.
            $serviceRequests = ServiceRequest::with(['resident.barangay', 'service', 'admin'])
                ->where('resident_id', $residentId)
                ->get()
                ->makeHidden('internal_notes');
        }

        return response()->json($serviceRequests);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:tbl_services,service_id',
            'description' => 'required|string|max:5000',
            'valid_id' => 'required|file|mimes:jpg,jpeg,png|max:2048',
            // Optional second upload: a photo of the site, for the road-clearing
            // form. Not required, because most requests are filed in conditions
            // where stopping to photograph anything is the wrong advice.
            'site_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:4096',
            'required_vehicle_type' => 'nullable|string|exists:tbl_vehicles,type',
            // Absent means "as soon as you can" — the request behaves exactly as
            // it always has. Present means a scheduled ambulance booking; see
            // the checks right below, which run before any file touches disk.
            'scheduled_at' => 'nullable|date',
        ]);

        $scheduledAt = $this->resolveScheduledAt($validated['scheduled_at'] ?? null);

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
            $serviceRequest = DB::transaction(function () use ($request, $validated, $filePath, $sitePhotoPath, $scheduledAt) {
                $vehicle = null;
                $vehicleId = null;

                // A scheduled booking never claims a unit here, even if a caller
                // somehow also sent required_vehicle_type: which ambulance goes
                // out is a staffing decision made at approval (phase 5), not
                // something this endpoint locks in before anyone on duty has
                // seen the booking. required_vehicle_type's own immediate-claim
                // path below is therefore for the unscheduled, "as soon as you
                // can" case only — unchanged from before this feature existed.
                if (!$scheduledAt && !empty($validated['required_vehicle_type'])) {
                    $vehicle = Vehicle::where('type', $validated['required_vehicle_type'])
                                      ->where('status', 'Available')
                                      ->lockForUpdate()
                                      ->first();

                    if (!$vehicle) {
                        return false;
                    }
                    $vehicleId = $vehicle->vehicle_id;
                }

                if ($scheduledAt) {
                    // Locks every Ambulance unit before the availability check
                    // runs, and before it — not just around it — so the check's
                    // own plain read is guaranteed to see any booking a
                    // concurrent request just committed, rather than a snapshot
                    // from before this transaction started. Two residents racing
                    // for the same slot are serialised here: the second blocks
                    // on this lock until the first commits, then re-checks
                    // against what the first actually booked.
                    Vehicle::where('type', 'Ambulance')->orderBy('vehicle_id')->lockForUpdate()->get();

                    $freeUnits = $this->availability->availableAmbulances(
                        $scheduledAt,
                        $scheduledAt->copy()->addHours(self::DEFAULT_BOOKING_HOURS)
                    );

                    if ($freeUnits->isEmpty()) {
                        throw ValidationException::withMessages([
                            'scheduled_at' => 'No ambulance is available for that time. Try a different slot.',
                        ]);
                    }
                }

                $newServiceRequest = ServiceRequest::create([
                    'resident_id' => $request->user()->getKey(),
                    'service_id' => $validated['service_id'],
                    'description' => $validated['description'],
                    'valid_id' => $filePath,
                    'site_photo' => $sitePhotoPath,
                    // A scheduled booking is approved capacity, not a request
                    // waiting on staff triage — 'Pending' would queue it next to
                    // a report nobody has looked at yet. scheduled_end and
                    // vehicle_id both stay null regardless of the check above
                    // finding a free unit: which one actually goes out, and the
                    // real end of its booking, are set at approval.
                    'status' => $scheduledAt ? 'Booked' : 'Pending',
                    'processed_by' => null,
                    'vehicle_id' => $vehicleId,
                    'scheduled_at' => $scheduledAt,
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

    /**
     * Shared by store() and adminStore(): a resident's own submission and a
     * staff-filed walk-in must reject the same malformed slot the same way,
     * or the two paths drift the way the naive/offset checkpoint parsing did.
     */
    private function resolveScheduledAt(?string $raw): ?Carbon
    {
        if (empty($raw)) {
            return null;
        }

        $scheduledAt = Carbon::parse($raw, self::OFFICE_TIMEZONE)->utc();

        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Scheduled time must be in the future.',
            ]);
        }

        if ($scheduledAt->lt(now()->addHours(self::MINIMUM_LEAD_TIME_HOURS))) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Scheduled bookings need at least '
                    . self::MINIMUM_LEAD_TIME_HOURS
                    . ' hour of lead time. Anything sooner is an emergency — call it in instead.',
            ]);
        }

        return $scheduledAt;
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

    /**
     * Decides who may read a private file, scoping the query in place for a
     * resident. Returns null to proceed, or the response to send instead.
     *
     * The routes that stream a government ID scan or a site photo sit OUTSIDE
     * the `is.admin` group on purpose — staff read any resident's file while a
     * resident reads only their own, and a middleware that refuses non-admins
     * outright cannot express that. The cost is that the two checks `is.admin`
     * performs do not run, so they have to run here instead:
     *
     *  - `isAdmin()`, because `tbl_user.role` is an unconstrained varchar and
     *    an `instanceof User` test alone would let a row with any other role
     *    read every ID scan in the system.
     *  - `isDeactivated()`, because deactivating an account through the panel
     *    revokes its tokens but a direct database edit does not — the exact
     *    case IsAdmin's own comment names. Without this the closed account
     *    keeps reading ID scans for the rest of its token's 8-hour life.
     */
    private function guardPrivateFile(Request $request, $query): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if ($user instanceof \App\Models\Resident) {
            $query->where('resident_id', $user->getKey());

            return null;
        }

        // 404, not 403: to anything that is not a recognised staff account this
        // must look the same as a request that does not exist, matching the
        // non-owner answer below.
        if (! $user instanceof \App\Models\User || ! $user->isAdmin()) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        // Named rather than folded into the line above, and worded exactly as
        // IsAdmin words it: the holder of this token was staff, and telling
        // them the account is closed is not a disclosure — they already knew
        // these records exist.
        if ($user->isDeactivated()) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        return null;
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
            'description' => 'required|string|max:5000',
            'valid_id' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'site_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:4096',
            'required_vehicle_type' => 'nullable|string|exists:tbl_vehicles,type',
            // Same "absent means as soon as possible" contract as store() — a
            // walk-in ambulance request can be booked for a future slot too.
            'scheduled_at' => 'nullable|date',
        ]);

        $scheduledAt = $this->resolveScheduledAt($validated['scheduled_at'] ?? null);

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
            $serviceRequest = DB::transaction(function () use ($validated, $residentId, $walkInName, $walkInContact, $filePath, $sitePhotoPath, $scheduledAt) {
                $vehicle = null;
                $vehicleId = null;

                // Same split as store(): an immediate walk-in may claim a unit
                // here, but a booking's unit is a staffing decision made at
                // approval, not something this counter form locks in.
                if (!$scheduledAt && !empty($validated['required_vehicle_type'])) {
                    $vehicle = Vehicle::where('type', $validated['required_vehicle_type'])
                                      ->where('status', 'Available')
                                      ->lockForUpdate()
                                      ->first();

                    if (!$vehicle) {
                        return false;
                    }
                    $vehicleId = $vehicle->vehicle_id;
                }

                if ($scheduledAt) {
                    Vehicle::where('type', 'Ambulance')->orderBy('vehicle_id')->lockForUpdate()->get();

                    $freeUnits = $this->availability->availableAmbulances(
                        $scheduledAt,
                        $scheduledAt->copy()->addHours(self::DEFAULT_BOOKING_HOURS)
                    );

                    if ($freeUnits->isEmpty()) {
                        throw ValidationException::withMessages([
                            'scheduled_at' => 'No ambulance is available for that time. Try a different slot.',
                        ]);
                    }
                }

                $newServiceRequest = ServiceRequest::create([
                    'resident_id' => $residentId,
                    'walk_in_name' => $walkInName,
                    'walk_in_contact_number' => $walkInContact,
                    'service_id' => $validated['service_id'],
                    'description' => $validated['description'],
                    'valid_id' => $filePath,
                    'site_photo' => $sitePhotoPath,
                    'status' => $scheduledAt ? 'Booked' : 'Pending',
                    'processed_by' => null,
                    'vehicle_id' => $vehicleId,
                    'scheduled_at' => $scheduledAt,
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

        // Same reasoning as index()'s resident branch: internal_notes is for
        // staff only, and this route serves the same model to both audiences.
        if ($user instanceof \App\Models\Resident) {
            $serviceRequest->makeHidden('internal_notes');
        }

        return response()->json($serviceRequest);
    }

    // Shared by validId() and sitePhoto() below — same ownership guard, same
    // 404-instead-of-403 so a non-owner's request cannot even be confirmed to
    // exist, same existence check against the disk. $column is always a
    // hardcoded literal at the two call sites, never request input, so the
    // dynamic property access introduces no injection surface.
    private function servePrivateColumn(Request $request, $id, string $column, string $label)
    {
        $query = ServiceRequest::query();

        if ($refusal = $this->guardPrivateFile($request, $query)) {
            return $refusal;
        }

        $serviceRequest = $query->find($id);

        // 404 rather than 403 for a non-owner, so the response does not disclose
        // that the request exists.
        if (!$serviceRequest || !$serviceRequest->{$column}) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if (!Storage::disk(self::privateDisk())->exists($serviceRequest->{$column})) {
            return response()->json(['message' => "{$label} file not found"], 404);
        }

        return Storage::disk(self::privateDisk())->response($serviceRequest->{$column});
    }

    public function validId(Request $request, $id)
    {
        return $this->servePrivateColumn($request, $id, 'valid_id', 'Valid ID');
    }

    // Same ownership rules as validId(). A site photo is less sensitive than a
    // government ID, but it still shows a named resident's street, and serving
    // it by public URL would be the mistake audit #8 already cost us once.
    public function sitePhoto(Request $request, $id)
    {
        return $this->servePrivateColumn($request, $id, 'site_photo', 'Site photo');
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
        // not a resident one — the crew is already moving. Booked joins Pending
        // here: a booking that has not yet been approved into a live dispatch is
        // still purely the resident's own plan to withdraw.
        if (!in_array($serviceRequest->status, ['Pending', 'Booked'], true)) {
            return response()->json([
                'message' => 'Only a pending or booked request can be cancelled.',
            ], 422);
        }

        // A booking too close to its own start is no longer just "the resident
        // changed their mind" — the office may already be staging for it. Only
        // Booked requests carry a scheduled_at, so Pending is never touched by
        // this check.
        if ($serviceRequest->scheduled_at
            && now()->gte($serviceRequest->scheduled_at->copy()->subHours(self::CANCEL_CUTOFF_HOURS))
        ) {
            return response()->json([
                'message' => 'This booking is too close to its scheduled time to cancel. Call the office instead.',
            ], 422);
        }

        // departed_office_at is the trip log's own first checkpoint — once it is
        // set the crew has physically left, and the booking behind it is no
        // longer the resident's to withdraw regardless of what tbl_service_request
        // itself still says.
        if ($serviceRequest->conductionRequests()->whereNotNull('departed_office_at')->exists()) {
            return response()->json([
                'message' => 'This trip has already been dispatched and cannot be cancelled here.',
            ], 422);
        }

        DB::transaction(function () use ($serviceRequest) {
            // store() can attach and dispatch a vehicle while the request is still
            // Pending, so cancelling has to hand the unit back or it leaks out of
            // the fleet with no request pointing at it.
            $this->releaseVehicle($serviceRequest->vehicle_id);

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

    /**
     * Texts a resident that their booking's status changed. Follows the OTP
     * call pattern at AuthController::sendVerificationCode(): PhilSms is the
     * only channel — there is no Laravel Notifications setup, and
     * MAIL_MAILER is 'log' in production (render.yaml), so an email
     * "fallback" here would not actually reach anyone, unlike the OTP flow
     * where email is a real second channel. Walk-in bookings carry no
     * resident_id and are silently skipped; there is no one to text.
     *
     * Deliberately best-effort: this always runs after the transaction that
     * made the change has already committed (called from outside every
     * DB::transaction() block below), so a delivery failure can only ever
     * fail to inform, never undo a booking that already landed. Never
     * throws — every failure path is caught and logged instead. The
     * 45-second poll and cold-launch fetch this is compensating for
     * (main.dart:320, :390) still catch a resident up if the text never
     * arrives.
     */
    private function notifyResident(ServiceRequest $serviceRequest, string $message): void
    {
        if ($serviceRequest->resident_id === null) {
            return;
        }

        $resident = $serviceRequest->resident ?? $serviceRequest->resident()->first();

        if (!$resident || !PhilSms::configured()) {
            return;
        }

        $number = PhilSms::normalize((string) $resident->phone_number);

        if ($number === '') {
            return;
        }

        try {
            $response = app(PhilSms::class)->send([$number], $message);

            if (!PhilSms::accepted($response)) {
                Log::warning('Booking status-change SMS not accepted', [
                    'request_id' => $serviceRequest->request_id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Booking status-change SMS failed', [
                'request_id' => $serviceRequest->request_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Manila wall clock, the same shape a staffer reads on the paper form and the panel. */
    private function forResident(Carbon $instant): string
    {
        return $instant->copy()->timezone(self::OFFICE_TIMEZONE)->format('M j, Y g:i A');
    }

    private function approvalMessage(ServiceRequest $serviceRequest): string
    {
        $unit = $serviceRequest->vehicle?->unit_identifier ?? 'a unit';

        return 'SERBIS: Your ambulance booking for '.$this->forResident($serviceRequest->scheduled_at)
            .' has been approved. Unit: '.$unit.'. — MDRRMO Echague';
    }

    /** One billed PhilSMS segment. Past this the vendor charges for a second. */
    private const SMS_SEGMENT_LIMIT = 160;

    /**
     * Assembles a message whose middle is staff-typed, trimming that middle —
     * and only that middle — until the whole body fits one segment.
     *
     * The `max:160` on `remarks` bounds what a human types, but it cannot
     * bound the *assembled* body: these templates add roughly eighty
     * characters of their own, so a remark at the cap would still bill two
     * segments. Trimming here rather than raising the validation cap keeps the
     * limit the admin is shown (160) the same as the limit on the field they
     * are typing into, and keeps the suffix — which says who sent the text —
     * from being what gets cut.
     */
    private function withReason(string $prefix, string $reason, string $suffix): string
    {
        $budget = self::SMS_SEGMENT_LIMIT - mb_strlen($prefix) - mb_strlen($suffix);

        if (mb_strlen($reason) > $budget) {
            // Ellipsis included in the budget, so the result lands on the limit
            // rather than one character past it.
            $reason = mb_substr($reason, 0, max(0, $budget - 1)).'…';
        }

        return $prefix.$reason.$suffix;
    }

    private function rejectionMessage(string $reason): string
    {
        return $this->withReason(
            'SERBIS: Your ambulance booking request was not approved. Reason: ',
            $reason,
            ' — MDRRMO Echague',
        );
    }

    private function rescheduleMessage(ServiceRequest $serviceRequest, string $reason): string
    {
        return $this->withReason(
            'SERBIS: Your ambulance booking has been moved to '
                .$this->forResident($serviceRequest->scheduled_at).'. Reason: ',
            $reason,
            ' — MDRRMO Echague',
        );
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
            'description' => 'nullable|string|max:5000',
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
            // Required the moment this call is the one rejecting the request —
            // a resident reading "Disapproved" with no reason is the complaint
            // this column exists to prevent. Not required for any other status,
            // reject is the only transition this endpoint makes without a human
            // having already typed something into the request beforehand.
            //
            // Capped for the same reason SmsController::sendBlast caps its
            // message at 160: on a rejection this string is pasted into a
            // PhilSMS body, and PhilSMS bills per segment with no sandbox. The
            // column is a TEXT and took anything, so a long remark was a
            // multi-segment billed message nobody priced. 160 is the cap on
            // what a human types; notifyResident's own builder is what
            // guarantees the assembled body still fits one segment.
            'remarks' => 'nullable|string|max:160|required_if:status,Disapproved',
            // Staff-only, never sent to PhilSMS and never returned to a resident
            // (see index()/show()) — so it carries no per-segment SMS cap.
            'internal_notes' => 'nullable|string|max:1000',
        ]);

        // Captured before update() overwrites status: rejecting a booking is
        // the case this endpoint notifies for (the panel's older, unscheduled
        // Pending -> Disapproved flow is not "a booking" and stays silent).
        $wasBookingRejection = $serviceRequest->scheduled_at !== null
            && ($validated['status'] ?? null) === 'Disapproved';

        DB::transaction(function () use ($serviceRequest, $validated) {
            $this->syncFleet($serviceRequest, $validated);

            $serviceRequest->update($validated);
        });

        if ($wasBookingRejection) {
            $this->notifyResident($serviceRequest, $this->rejectionMessage((string) $validated['remarks']));
        }

        return response()->json($serviceRequest->fresh(['vehicle']));
    }

    /**
     * Assigns a unit to a Booked request. Status stays 'Booked' — approval is
     * not dispatch, it is the office committing capacity to a window that is
     * usually still days away. That is exactly why this does NOT mark the
     * vehicle 'Dispatched' the way store()'s immediate-claim path and
     * syncFleet() both do for a live request: that convention means "out
     * right now", and flipping it for a booking that has not happened yet
     * would wrongly clear the unit off every OTHER day's availability. A unit
     * only becomes 'Dispatched' when it actually leaves — that is dispatch,
     * a later step this endpoint does not perform.
     */
    public function approve(Request $request, $id)
    {
        $serviceRequest = ServiceRequest::find($id);

        if (!$serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if ($serviceRequest->status !== 'Booked') {
            return response()->json([
                'message' => 'Only a booked request can be approved.',
            ], 422);
        }

        if (!$serviceRequest->scheduled_at) {
            return response()->json([
                'message' => 'This request has no scheduled time to approve against.',
            ], 422);
        }

        $validated = $request->validate([
            'vehicle_id' => 'required|integer|exists:tbl_vehicles,vehicle_id',
            // Staff-adjustable; defaults to +2h below when absent. Same Manila
            // parse as everywhere else a human types a time into this system.
            'scheduled_end' => 'nullable|date',
        ]);

        $scheduledEnd = !empty($validated['scheduled_end'])
            ? Carbon::parse($validated['scheduled_end'], self::OFFICE_TIMEZONE)->utc()
            : $serviceRequest->scheduled_at->copy()->addHours(self::DEFAULT_BOOKING_HOURS);

        if ($scheduledEnd->lte($serviceRequest->scheduled_at)) {
            throw ValidationException::withMessages([
                'scheduled_end' => 'scheduled_end must be after scheduled_at.',
            ]);
        }

        DB::transaction(function () use ($request, $serviceRequest, $validated, $scheduledEnd) {
            // Same serialising lock as store(): whoever gets here first
            // decides who the window's last free unit goes to.
            $units = Vehicle::where('type', 'Ambulance')->orderBy('vehicle_id')->lockForUpdate()->get();

            $vehicle = $units->firstWhere('vehicle_id', (int) $validated['vehicle_id']);

            if (!$vehicle) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'That unit is not an Ambulance.',
                ]);
            }

            if ($vehicle->status === 'Maintenance') {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'That unit is under Maintenance and cannot be assigned.',
                ]);
            }

            // Re-checked, not trusted from submission time: the window may
            // have filled with other approvals since this request was filed.
            $freeIds = $this->availability
                ->availableAmbulances($serviceRequest->scheduled_at, $scheduledEnd, $serviceRequest->request_id)
                ->pluck('vehicle_id');

            if (!$freeIds->contains($vehicle->vehicle_id)) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'That unit is no longer free for this window.',
                ]);
            }

            if ($serviceRequest->vehicle_id && $serviceRequest->vehicle_id !== $vehicle->vehicle_id) {
                $this->releaseVehicle($serviceRequest->vehicle_id);
            }

            $serviceRequest->update([
                'vehicle_id' => $vehicle->vehicle_id,
                'scheduled_end' => $scheduledEnd,
                'approved_at' => now(),
                'processed_by' => $request->user()->getKey(),
            ]);
        });

        $fresh = $serviceRequest->fresh(['vehicle']);
        $this->notifyResident($fresh, $this->approvalMessage($fresh));

        return response()->json($fresh);
    }

    /**
     * Moves an existing Booked request to a new window. Does not touch
     * vehicle_id — a request not yet approved has none to move, and one
     * already approved keeps its unit as long as that unit is still free for
     * the new time; see the self-exclusion note on AmbulanceAvailability.
     */
    public function reschedule(Request $request, $id)
    {
        $serviceRequest = ServiceRequest::find($id);

        if (!$serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if ($serviceRequest->status !== 'Booked') {
            return response()->json([
                'message' => 'Only a booked request can be rescheduled.',
            ], 422);
        }

        $validated = $request->validate([
            'scheduled_at' => 'required|date',
            'scheduled_end' => 'required|date',
            // TracksHistory logs the scheduled_at/scheduled_end change on its
            // own, but the *reason* only reaches that log because remarks moves
            // in the same update — so it is not optional here, unlike update().
            // Capped like update()'s copy: this one always reaches PhilSMS.
            'remarks' => 'required|string|max:160',
        ]);

        $scheduledAt = Carbon::parse($validated['scheduled_at'], self::OFFICE_TIMEZONE)->utc();
        $scheduledEnd = Carbon::parse($validated['scheduled_end'], self::OFFICE_TIMEZONE)->utc();

        if ($scheduledEnd->lte($scheduledAt)) {
            throw ValidationException::withMessages([
                'scheduled_end' => 'scheduled_end must be after scheduled_at.',
            ]);
        }

        DB::transaction(function () use ($serviceRequest, $scheduledAt, $scheduledEnd, $validated) {
            Vehicle::where('type', 'Ambulance')->orderBy('vehicle_id')->lockForUpdate()->get();

            $freeIds = $this->availability
                ->availableAmbulances($scheduledAt, $scheduledEnd, $serviceRequest->request_id)
                ->pluck('vehicle_id');

            if ($serviceRequest->vehicle_id) {
                // Already approved: the unit it already holds must specifically
                // still be free for the new time, not just some other unit.
                if (!$freeIds->contains($serviceRequest->vehicle_id)) {
                    throw ValidationException::withMessages([
                        'scheduled_at' => 'The assigned unit is not free for that time.',
                    ]);
                }
            } elseif ($freeIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'No ambulance is available for that time.',
                ]);
            }

            $serviceRequest->update([
                'scheduled_at' => $scheduledAt,
                'scheduled_end' => $scheduledEnd,
                'remarks' => $validated['remarks'],
            ]);
        });

        $fresh = $serviceRequest->fresh(['vehicle']);
        $this->notifyResident($fresh, $this->rescheduleMessage($fresh, (string) $validated['remarks']));

        return response()->json($fresh);
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