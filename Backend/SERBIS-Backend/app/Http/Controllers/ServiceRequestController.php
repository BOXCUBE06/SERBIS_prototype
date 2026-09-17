<?php

namespace App\Http\Controllers;

use App\Models\AmbulanceBooking;
use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRelative;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AmbulanceAvailability;
use App\Services\Fcm;
use App\Traits\ResolvesUploadDisks;
use App\Traits\ScopesToOwner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceRequestController extends Controller
{
    use ResolvesUploadDisks;
    use ScopesToOwner;

    public function __construct(
        private readonly AmbulanceAvailability $availability,
        private readonly Fcm $fcm,
    ) {}

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

    /**
     * How far ahead a booking may be made. The other end of
     * MINIMUM_LEAD_TIME_HOURS, and it was missing entirely — `nullable|date`
     * accepted the year 3000, and AmbulanceAvailability would have carried
     * that unit as booked for every window in between, forever.
     *
     * A year is far past anything the office schedules (a dialysis run is
     * booked days out, not seasons) while still being a date a person could
     * plausibly mean. Expressed as a strtotime expression because that is what
     * Laravel's date-comparison rules take.
     */
    private const BOOKING_HORIZON = '+1 year';

    /**
     * Names one intake list may carry. Mirrors
     * ConductionRequestController::MAX_PEOPLE_PER_ROLE, and for the same
     * reason: `tbl_service_request_relatives.position` is an
     * `unsignedTinyInteger`, and copyRelativesToTrip() carries these names
     * onto the trip's own tinyint-backed table as well.
     */
    private const MAX_RELATIVES = 20;

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
    public const TERMINAL_STATUSES = ServiceRequest::TERMINAL_STATUSES;

    /**
     * update()'s whole state machine: for each target status, the statuses a
     * request may move FROM to reach it. Anything not listed here as a source
     * — including any of the three terminal statuses above, which is why none
     * of them appear on the right of any entry — is refused. Before this
     * existed update() only checked the target against STATUSES, so all 30
     * from/to pairs were reachable: a Resolved request could be reopened, a
     * Cancelled one dispatched, a Disapproved one resolved, and a Booked
     * request could reach Responding without ever going through approve() —
     * skipping its availability re-check and leaving approved_at NULL.
     *
     * 'Cancelled' is deliberately absent as a key: cancel() is the only route
     * that ever writes it, and it does so on the model directly rather than
     * through this method, so update() has nothing to allow it into.
     *
     * Setting a request to the status it is already at is not a transition —
     * see the same-status short-circuit in update() below, checked before
     * this map — so a resend of the current status (a vehicle swap on an
     * already-Responding request, say) is never looked up here at all.
     */
    private const ALLOWED_TRANSITIONS = [
        'Booked' => ['Pending'],
        'Responding' => ['Pending', 'Booked'],
        'Resolved' => ['Responding'],
        'Disapproved' => ['Pending', 'Booked'],
    ];

    /**
     * Same literal the Vue panel keys off (ServiceRequestQueue.vue's
     * AMBULANCE_SERVICE_CODE) — duplicated rather than shared for the same
     * reason as OFFICE_TIMEZONE above: a fixed service identifier, not
     * config that could drift.
     */
    private const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response';

    /**
     * The columns update() must route to AmbulanceBooking rather than write
     * onto this row. update() does not currently validate scheduled_at,
     * scheduled_end or approved_at as input — reschedule()/approve() are the
     * conflict-checked paths for those — but the split is listed here too,
     * defensively: if $validated ever carries one, Arr::except below must
     * still strip it before it reaches this row.
     */
    private const BOOKING_FIELDS = [
        'patient_name', 'patient_age', 'patient_sex', 'patient_address',
        'patient_contact_number', 'pickup_location', 'destination', 'condition_notes',
        'scheduled_at', 'scheduled_end', 'approved_at',
    ];

    /** Null if the service was seeded without a code column somehow, or does not exist. */
    private function ambulanceServiceId(): ?int
    {
        return Service::where('code', self::AMBULANCE_SERVICE_CODE)->value('service_id');
    }

    /**
     * Full list, unpaginated — intentional, not an oversight. See the
     * PaginatesLists trait's own comment for the general reasoning; the P1
     * rate-limit/request-count audit (2026-09-15) walked this endpoint
     * specifically and confirmed adding paginate() alone would break rather
     * than fix it: ServiceRequestQueue.vue's status-tab counts, search,
     * Pending-first sort, bulk-disapprove-by-selected-id and CSV export all
     * run over the *whole* array client-side, and the endpoint currently
     * returns both boards (resident requests and ambulance dispatch) mixed,
     * split by service code only after the full fetch. A correct paginated
     * version needs, together, not separately: server-side status/search
     * filtering, a status-count endpoint or payload, a scope param to split
     * the two boards before paginating, a bulk action that targets a filter
     * rather than a loaded id list, and an export path that ignores the page
     * size. That is a feature, not a follow-up patch — revisit if this
     * endpoint's payload size or query time becomes a real problem as the
     * table grows, not before.
     */
    public function adminIndex()
    {
        // Added 'resident.barangay'
        // conductionRequests.people: C5's bridge — the Bookings queue's
        // Responding row needs its linked trip record (and who is driving
        // it) without a second round trip per row.
        $requests = ServiceRequest::with(['resident.barangay', 'service', 'admin', 'vehicle', 'conductionRequests.people'])
            ->latest()
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user instanceof User && $user->isAdmin()) {
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
        $ambulanceServiceId = $this->ambulanceServiceId();

        // Interpolated straight into required_if/required_unless below — a
        // null here casts to '' in the rule string, which required_unless
        // never matches. That degrades silently: description becomes
        // required for every request (ambulance included) and
        // patient_name/destination stop being required for none. Fail loudly
        // instead — this is a seeding/config problem, not a validation one.
        if ($ambulanceServiceId === null) {
            throw new \RuntimeException(
                'No service found with code "'.self::AMBULANCE_SERVICE_CODE.'" — '
                .'cannot build ambulance validation rules. Check tbl_services.code for the ambulance row.'
            );
        }

        $validated = $request->validate([
            'service_id' => 'required|exists:tbl_services,service_id',
            // Ambulance is exempt because the server composes it below from the
            // structured fields, exactly as adminStore() does — whatever a
            // client sends under this key for an ambulance request is ignored
            // rather than trusted. Every other service still types it by hand.
            'description' => "required_unless:service_id,{$ambulanceServiceId}|nullable|string|max:5000",
            'valid_id' => 'required|file|mimes:jpg,jpeg,png|max:2048',
            // Optional second upload: a photo of the site, for the road-clearing
            // form. Not required, because most requests are filed in conditions
            // where stopping to photograph anything is the wrong advice.
            'site_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:4096',
            // Free-text companion to site_photo — a landmark the resident can
            // type faster than they can stop to photograph one.
            'landmark' => 'nullable|string|max:255',
            'required_vehicle_type' => 'nullable|string|exists:tbl_vehicles,type',
            // Absent means "as soon as you can" — the request behaves exactly as
            // it always has. Present means a scheduled ambulance booking; see
            // the checks right below, which run before any file touches disk.
            'scheduled_at' => 'nullable|date|before_or_equal:'.self::BOOKING_HORIZON,
            // Structured ambulance intake, mirroring adminStore()'s columns —
            // but deliberately looser about what is required. The counter form
            // demands five, because a staffer has the requester in front of
            // them and can ask. A resident filing on a phone often cannot: the
            // address and the diagnosis are what admin verification confirms
            // by phone afterwards. Only the two facts that make the request
            // actionable at all are required here — who is going, and where.
            'patient_name' => "required_if:service_id,{$ambulanceServiceId}|nullable|string|max:255",
            'destination' => "required_if:service_id,{$ambulanceServiceId}|nullable|string|max:255",
            // min:0 stays — a neonate transport is a real ambulance case and 0
            // is the honest reading. 150 was not defensible: the oldest
            // verified human lived to 122. Mirrored in adminStore() and in
            // ConductionRequestController::store().
            'patient_age' => 'nullable|integer|min:0|max:120',
            'patient_sex' => 'nullable|in:male,female',
            'patient_address' => 'nullable|string|max:255',
            // The number for this patient, when it is not the account holder's.
            // Left null when they are the same person; the trip record falls
            // back to the account number, as it always did.
            'patient_contact_number' => 'nullable|string|max:32',
            // Blank is expected, not exceptional — it defaults to the
            // resident's registered barangay below.
            'pickup_location' => 'nullable|string|max:255',
            'condition_notes' => 'nullable|string|max:5000',
            // Who is coming with the patient, named at intake rather than at
            // dispatch. Optional on every service: nobody is required to bring
            // anyone, and a non-ambulance request simply never sends them.
            'patient_relatives' => 'nullable|array|max:'.self::MAX_RELATIVES,
            'patient_relatives.*' => 'nullable|string|max:255',
        ]);

        // Disabling, not deleting, is how a service goes away (the intake
        // form logic is hardcoded against tbl_services.code, so a delete
        // would silently break it — see the is_active migration). A
        // filing-time gate only: update()/approve()/etc. never re-check
        // this, so a request already filed against a service that gets
        // disabled afterward is untouched.
        $service = Service::find($validated['service_id']);
        if ($service && ! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => 'This service is no longer accepting new requests.',
            ]);
        }

        $scheduledAt = $this->resolveScheduledAt($validated['scheduled_at'] ?? null);

        $isAmbulance = $ambulanceServiceId !== null
            && (int) $validated['service_id'] === $ambulanceServiceId;

        $resident = $request->user();

        // A closed account cannot file. Checked before the uploads further down
        // so a refused request leaves nothing on disk to clean up.
        //
        // Mostly a backstop rather than the path the app takes: deactivating
        // from the panel revokes the resident's tokens (ResidentController::
        // update), so the phone gets a 401 and signs out before it can reach
        // this. What it does cover is a status changed straight on the column
        // by a database edit, which leaves live tokens untouched.
        if ($resident instanceof Resident && $resident->isDeactivated()) {
            return response()->json([
                'message' => 'This account has been deactivated and cannot file new requests. Please visit the MDRRMO office.',
                'code' => 'account_deactivated',
            ], 403);
        }

        if ($isAmbulance) {
            // Where the ambulance is going *to* is required; where it starts
            // from is not, because for a resident filing from home the answer
            // is almost always the address already on their account. Filled
            // before the description is composed so both agree.
            //
            // The account's "registered address" is the barangay and nothing
            // finer — tbl_residents carries barangay_id and no street or purok
            // column — so this is a starting point a dispatcher still has to
            // narrow by phone, not a doorstep. It is better than the
            // 'Address not specified' placeholder it replaces, and worse than
            // what the resident could have typed.
            if (trim((string) ($validated['pickup_location'] ?? '')) === '') {
                $validated['pickup_location'] = $this->registeredAddress($resident);
            }

            $description = self::composeAmbulanceDescription(
                $validated,
                $validated['patient_contact_number'] ?? ($resident->phone_number ?? '')
            );
        } else {
            $description = $validated['description'] ?? null;
        }

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
            $serviceRequest = DB::transaction(function () use ($request, $validated, $filePath, $sitePhotoPath, $scheduledAt, $description, $isAmbulance) {
                $vehicle = null;
                $vehicleId = null;

                // A scheduled booking never claims a unit here, even if a caller
                // somehow also sent required_vehicle_type: which ambulance goes
                // out is a staffing decision made at approval (phase 5), not
                // something this endpoint locks in before anyone on duty has
                // seen the booking. required_vehicle_type's own immediate-claim
                // path below is therefore for the unscheduled, "as soon as you
                // can" case only — unchanged from before this feature existed.
                if (! $scheduledAt && ! empty($validated['required_vehicle_type'])) {
                    $vehicle = Vehicle::where('type', $validated['required_vehicle_type'])
                        ->where('status', 'Available')
                        ->lockForUpdate()
                        ->first();

                    if (! $vehicle) {
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
                    'description' => $description,
                    'valid_id' => $filePath,
                    'site_photo' => $sitePhotoPath,
                    'landmark' => $validated['landmark'] ?? null,
                    // A scheduled booking is approved capacity, not a request
                    // waiting on staff triage — 'Pending' would queue it next to
                    // a report nobody has looked at yet. scheduled_end and
                    // vehicle_id both stay null regardless of the check above
                    // finding a free unit: which one actually goes out, and the
                    // real end of its booking, are set at approval.
                    'status' => $scheduledAt ? 'Booked' : 'Pending',
                    'processed_by' => null,
                    'vehicle_id' => $vehicleId,
                ]);

                // Ambulance-only, same as adminStore()'s row: nothing reads
                // these off a non-ambulance request, and writing them there
                // would put a patient's details on a road-clearing report.
                if ($isAmbulance) {
                    AmbulanceBooking::create([
                        'request_id' => $newServiceRequest->request_id,
                        'patient_name' => $validated['patient_name'] ?? null,
                        'patient_age' => $validated['patient_age'] ?? null,
                        'patient_sex' => $validated['patient_sex'] ?? null,
                        'patient_address' => $validated['patient_address'] ?? null,
                        'patient_contact_number' => $validated['patient_contact_number'] ?? null,
                        'pickup_location' => $validated['pickup_location'] ?? null,
                        'destination' => $validated['destination'] ?? null,
                        'condition_notes' => $validated['condition_notes'] ?? null,
                        'scheduled_at' => $scheduledAt,
                    ]);
                }

                $this->storeRelatives($newServiceRequest, $validated['patient_relatives'] ?? []);

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

        return response()->json($serviceRequest->load(['relatives', 'ambulanceBooking']), 201);
    }

    /**
     * The one composer for an ambulance request's `description`, shared by
     * store() and adminStore().
     *
     * Same shape AmbulanceFormData.metaLines() writes on the mobile side
     * (service_forms.dart) — Patient:/pickup → destination/Condition:/Contact:,
     * one per line — kept for the request detail panel's own "Description"
     * display and the CSV export. The admin panel's detail view prefers the
     * structured columns and only falls back to this text when patient_name is
     * absent (ServiceRequestQueue.vue), and nothing reads it back apart: the
     * one parser that ever existed was the 2026_08_31 backfill, which has run.
     *
     * Extracted rather than duplicated. Both intake paths have to produce
     * byte-identical text or the same request reads differently depending on
     * whether it was filed at the counter or on a phone, and a second copy of
     * this is how that starts.
     *
     * @param  array<string, mixed>  $validated
     */
    private static function composeAmbulanceDescription(array $validated, ?string $contactNumber): string
    {
        $contactNumber = trim((string) $contactNumber);

        return implode("\n", [
            'Patient: '.($validated['patient_name'] ?? 'Not specified'),
            ($validated['pickup_location'] ?? 'Address not specified')
                .' → '.($validated['destination'] ?? 'destination not specified'),
            'Condition: '.($validated['condition_notes'] ?? 'Not described'),
            'Contact: '.($contactNumber !== '' ? $contactNumber : 'See resident profile'),
        ]);
    }

    /**
     * The address a resident is registered at, for defaulting a blank pickup.
     *
     * This is the barangay name and nothing finer: tbl_residents has a
     * barangay_id and no street, purok or house-number column, so this is the
     * most precise "registered address" the schema can answer with. Returns
     * null rather than a placeholder when even that is missing, so the caller's
     * own 'Address not specified' stays the single place that decides what an
     * unknown address reads as.
     */
    private function registeredAddress(?Resident $resident): ?string
    {
        $barangay = $resident?->barangay?->barangay_name;

        return trim((string) $barangay) !== '' ? trim($barangay) : null;
    }

    /**
     * Writes the intake relative list, shared by store() and adminStore().
     *
     * Blank slots are filtered here rather than by a validation rule, the
     * same way ConductionRequestController does it: a form that renders two
     * name fields and has one filled must not 422 over the empty one.
     */
    private function storeRelatives(ServiceRequest $serviceRequest, array $names): void
    {
        $position = 0;

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            ServiceRequestRelative::create([
                'service_request_id' => $serviceRequest->request_id,
                'name' => $name,
                'position' => $position++,
            ]);
        }
    }

    /**
     * Copies a request's intake relatives onto a freshly created trip record
     * as role='relative' rows.
     *
     * The one place this is written, deliberately. There are two paths that
     * create a trip against a booking — createConductionStub() below for the
     * automatic Booked→Responding flip, and ConductionRequestController::
     * store() for a manually filed one — and docs/dispatch-audit.md already
     * names that split as the drift hazard that left the two halves of the
     * dispatch bridge disagreeing. A second copy of this loop is exactly how
     * relatives would end up reaching one path and not the other.
     *
     * Appends rather than replaces: a manually filed trip may already carry
     * relatives someone typed into the create dialog, and those are a later,
     * better-informed statement than the intake list. Positions continue past
     * whatever is already there so the trip form renders one ordered list.
     *
     * Intentionally does NOT delete the intake rows. They are what the
     * requester said at intake; the people table is what the crew logged.
     * Both are worth keeping, and only the first survives if the trip record
     * is ever deleted.
     */
    public static function copyRelativesToTrip(ServiceRequest $serviceRequest, ConductionRequest $trip): void
    {
        $relatives = $serviceRequest->relatives()->orderBy('position')->get();

        if ($relatives->isEmpty()) {
            return;
        }

        $position = (int) $trip->people()->where('role', 'relative')->max('position');
        $hasExisting = $trip->people()->where('role', 'relative')->exists();

        foreach ($relatives as $relative) {
            ConductionRequestPerson::create([
                'conduction_request_id' => $trip->conduction_request_id,
                'role' => 'relative',
                'name' => $relative->name,
                'position' => $hasExisting ? ++$position : $position++,
            ]);
        }
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

        $this->rejectIfPast($scheduledAt);

        if ($scheduledAt->lt(now()->addHours(self::MINIMUM_LEAD_TIME_HOURS))) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Scheduled bookings need at least '
                    .self::MINIMUM_LEAD_TIME_HOURS
                    .' hour of lead time. Anything sooner is an emergency — call it in instead.',
            ]);
        }

        return $scheduledAt;
    }

    /**
     * Shared by resolveScheduledAt() and reschedule(): a past scheduled_at is
     * nonsensical on every path, unlike MINIMUM_LEAD_TIME_HOURS below, which
     * only resolveScheduledAt()'s callers (a resident's own submission, a
     * staff-filed walk-in) apply — reschedule() is staff moving a booking
     * they already own, not someone deciding whether now is "an emergency,
     * not a schedule", so it deliberately does not layer that gate on top.
     */
    private function rejectIfPast(Carbon $scheduledAt): void
    {
        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Scheduled time must be in the future.',
            ]);
        }
    }

    // Deleting the upload is best-effort on purpose: the caller is already on a
    // failure path, and a storage error here would replace the real reason for
    // the failure with a misleading one.
    private function discardUpload(?string $filePath): void
    {
        if (! $filePath) {
            return;
        }

        try {
            Storage::disk(self::privateDisk())->delete($filePath);
        } catch (\Throwable) {
            // Leaving the file behind is the lesser failure.
        }
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
        // Resolved before validate() so it can be interpolated into
        // required_if/required_unless below — Laravel's own rules take a
        // literal, not a query, and the ambulance service's id is not a
        // fixed one across environments the way its code is.
        $ambulanceServiceId = $this->ambulanceServiceId();

        // Same failure mode as store(): a null here silently degrades the
        // rules below instead of erroring. Fail loudly.
        if ($ambulanceServiceId === null) {
            throw new \RuntimeException(
                'No service found with code "'.self::AMBULANCE_SERVICE_CODE.'" — '
                .'cannot build ambulance validation rules. Check tbl_services.code for the ambulance row.'
            );
        }

        $validated = $request->validate([
            'resident_id' => 'nullable|integer|exists:tbl_residents,resident_id',
            // Required only when there is no account to pull them from.
            'walk_in_name' => 'required_without:resident_id|nullable|string|max:255',
            'walk_in_contact_number' => 'required_without:resident_id|nullable|string|max:32',
            'service_id' => 'required|exists:tbl_services,service_id',
            // Every other service still types this by hand. For ambulance it
            // is composed server-side below from the structured fields, so
            // whatever the client sends here is ignored rather than trusted.
            'description' => "required_unless:service_id,{$ambulanceServiceId}|nullable|string|max:5000",
            // Structured intake, ambulance only. patient_age/patient_sex stay
            // optional even for ambulance — the paper form allows either to
            // be unknown at intake and ConductionRequestController's own
            // columns are nullable for the same reason.
            'patient_name' => "required_if:service_id,{$ambulanceServiceId}|nullable|string|max:255",
            // Same ceiling as store() — see the note there.
            'patient_age' => 'nullable|integer|min:0|max:120',
            'patient_sex' => 'nullable|in:male,female',
            'patient_address' => "required_if:service_id,{$ambulanceServiceId}|nullable|string|max:255",
            // Optional on both paths: null means the patient is reachable on
            // the number that filed the request, which is the common case.
            'patient_contact_number' => 'nullable|string|max:32',
            'pickup_location' => "required_if:service_id,{$ambulanceServiceId}|nullable|string|max:255",
            'destination' => "required_if:service_id,{$ambulanceServiceId}|nullable|string|max:255",
            'condition_notes' => "required_if:service_id,{$ambulanceServiceId}|nullable|string|max:5000",
            'valid_id' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'site_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:4096',
            'required_vehicle_type' => 'nullable|string|exists:tbl_vehicles,type',
            // Same "absent means as soon as possible" contract as store() — a
            // walk-in ambulance request can be booked for a future slot too.
            'scheduled_at' => 'nullable|date|before_or_equal:'.self::BOOKING_HORIZON,
            // Same optional intake list as store(). Collected at the counter
            // now rather than waited for until dispatch, when the trip record
            // that used to be their only home is finally created.
            'patient_relatives' => 'nullable|array|max:'.self::MAX_RELATIVES,
            'patient_relatives.*' => 'nullable|string|max:255',
        ]);

        // Disabling, not deleting, is how a service goes away (the intake
        // form logic is hardcoded against tbl_services.code, so a delete
        // would silently break it — see the is_active migration). A
        // filing-time gate only: update()/approve()/etc. never re-check
        // this, so a request already filed against a service that gets
        // disabled afterward is untouched.
        $service = Service::find($validated['service_id']);
        if ($service && ! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => 'This service is no longer accepting new requests.',
            ]);
        }

        $scheduledAt = $this->resolveScheduledAt($validated['scheduled_at'] ?? null);

        $residentId = $validated['resident_id'] ?? null;

        // The same rule at the counter, but a 422 on the field rather than a
        // 403: the caller here is a staff member whose own account is fine, and
        // the problem is the resident they picked. Walk-ins are unaffected —
        // they carry no resident_id — so a deactivated resident standing at the
        // counter can still be served by filing under walk_in_name. That is the
        // deliberate escape hatch, not an oversight.
        if ($residentId) {
            $selected = Resident::find($residentId);

            if ($selected && $selected->isDeactivated()) {
                throw ValidationException::withMessages([
                    'resident_id' => 'This resident account has been deactivated. File as a walk-in, or reactivate the account first.',
                ]);
            }
        }
        // Walk-in fields are dropped rather than merely left unvalidated when a
        // resident is picked — a mistyped name left over from switching the
        // form's mode must not sit next to a linked account pretending to be
        // a fact about it.
        $walkInName = $residentId ? null : ($validated['walk_in_name'] ?? null);
        $walkInContact = $residentId ? null : ($validated['walk_in_contact_number'] ?? null);
        $ownerSegment = $residentId ?: 'walk-in';

        $isAmbulance = $ambulanceServiceId !== null && (int) $validated['service_id'] === $ambulanceServiceId;
        $description = $validated['description'] ?? null;
        if ($isAmbulance) {
            // Same precedence store() uses: the patient's own number when one
            // was given, otherwise whoever filed the request.
            $description = self::composeAmbulanceDescription(
                $validated,
                $validated['patient_contact_number']
                    ?? ($residentId
                        ? (Resident::find($residentId)->phone_number ?? '')
                        : ($walkInContact ?? ''))
            );
        }

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
            $serviceRequest = DB::transaction(function () use ($validated, $residentId, $walkInName, $walkInContact, $filePath, $sitePhotoPath, $scheduledAt, $description, $isAmbulance) {
                $vehicle = null;
                $vehicleId = null;

                // Same split as store(): an immediate walk-in may claim a unit
                // here, but a booking's unit is a staffing decision made at
                // approval, not something this counter form locks in.
                if (! $scheduledAt && ! empty($validated['required_vehicle_type'])) {
                    $vehicle = Vehicle::where('type', $validated['required_vehicle_type'])
                        ->where('status', 'Available')
                        ->lockForUpdate()
                        ->first();

                    if (! $vehicle) {
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
                    'description' => $description,
                    'valid_id' => $filePath,
                    'site_photo' => $sitePhotoPath,
                    'status' => $scheduledAt ? 'Booked' : 'Pending',
                    'processed_by' => null,
                    'vehicle_id' => $vehicleId,
                ]);

                // Ambulance-only. Null for every other service, same as an
                // app submission's row until the mobile app is on this too —
                // nothing reads these off a non-ambulance row.
                if ($isAmbulance) {
                    AmbulanceBooking::create([
                        'request_id' => $newServiceRequest->request_id,
                        'patient_name' => $validated['patient_name'] ?? null,
                        'patient_age' => $validated['patient_age'] ?? null,
                        'patient_sex' => $validated['patient_sex'] ?? null,
                        'patient_address' => $validated['patient_address'] ?? null,
                        'patient_contact_number' => $validated['patient_contact_number'] ?? null,
                        'pickup_location' => $validated['pickup_location'] ?? null,
                        'destination' => $validated['destination'] ?? null,
                        'condition_notes' => $validated['condition_notes'] ?? null,
                        'scheduled_at' => $scheduledAt,
                    ]);
                }

                $this->storeRelatives($newServiceRequest, $validated['patient_relatives'] ?? []);

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

        return response()->json($serviceRequest->load(['resident.barangay', 'service', 'relatives', 'ambulanceBooking']), 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        // Added 'resident.barangay'
        $query = ServiceRequest::with(['resident.barangay', 'service', 'admin']);

        // Scopes to the caller for a resident, and refuses anything that is not
        // active staff. This used to be a bare `instanceof Resident` check with
        // no else, so a token that was neither — a deactivated admin, or a
        // tbl_user row with some other role — read every resident's request,
        // internal_notes and patient details included.
        if ($refusal = $this->scopeToOwner($request, $query, 'Service request not found')) {
            return $refusal;
        }

        $serviceRequest = $query->find($id);

        if (! $serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        // Same reasoning as index()'s resident branch: internal_notes is for
        // staff only, and this route serves the same model to both audiences.
        if ($user instanceof Resident) {
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

        if ($refusal = $this->scopeToOwner($request, $query, 'Service request not found')) {
            return $refusal;
        }

        $serviceRequest = $query->find($id);

        // 404 rather than 403 for a non-owner, so the response does not disclose
        // that the request exists.
        if (! $serviceRequest || ! $serviceRequest->{$column}) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if (! Storage::disk(self::privateDisk())->exists($serviceRequest->{$column})) {
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
        $query = ServiceRequest::query();

        // Same guard as show(), and this one is a write: without the staff
        // branch, any token that was not a resident's could cancel any
        // resident's Pending or Booked request and release its unit.
        if ($refusal = $this->scopeToOwner($request, $query, 'Service request not found')) {
            return $refusal;
        }

        $serviceRequest = $query->find($id);

        // 404 rather than 403 for a non-owner, matching show() and validId(): the
        // response must not disclose that the request exists.
        if (! $serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        // Once a unit is Responding the cancellation is an operational decision,
        // not a resident one — the crew is already moving. Booked joins Pending
        // here: a booking that has not yet been approved into a live dispatch is
        // still purely the resident's own plan to withdraw.
        if (! in_array($serviceRequest->status, ['Pending', 'Booked'], true)) {
            return response()->json([
                'message' => 'Only a pending or booked request can be cancelled.',
            ], 422);
        }

        // A booking too close to its own start is no longer just "the resident
        // changed their mind" — the office may already be staging for it. Only
        // Booked requests carry a scheduled_at, so Pending is never touched by
        // this check.
        //
        // Scoped to isFuture(): a scheduled_at already in the past is not "too
        // near" to cancel, it has already happened. Without the guard, gte()
        // stays true forever once the cutoff window passes, so a booking left
        // unresolved past its own schedule could never be cancelled again.
        $scheduledAt = $serviceRequest->ambulanceBooking?->scheduled_at;

        if ($scheduledAt
            && $scheduledAt->isFuture()
            && now()->gte($scheduledAt->copy()->subHours(self::CANCEL_CUTOFF_HOURS))
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
            // request stays as it is — every PUT re-sends the current vehicle_id
            // (see the docblock above), so this is the common case, not an edge
            // one, and must stay a silent no-op. A unit under Maintenance no
            // longer reaches here at all: update() rejects it before the
            // transaction opens (ServiceRequestVehicleGuardTest).
            if ($vehicle && $vehicle->status === 'Available') {
                $vehicle->update(['status' => 'Dispatched']);
            } elseif ($vehicle && $vehicle->status === 'Dispatched' && $incomingVehicleId !== $currentVehicleId) {
                // A genuinely new claim (a fresh assignment or a swap) on a unit
                // that lost the Available race to another admin since the
                // picker last fetched it. Abort loudly rather than writing a
                // vehicle_id the fleet never actually promoted: the surrounding
                // DB::transaction (update(), above) rolls the whole request
                // update back with it, so nothing partial lands.
                throw ValidationException::withMessages([
                    'vehicle_id' => $vehicle->unit_identifier.' is no longer available — pick another unit.',
                ]);
            }
        }
    }

    /** Returns a dispatched unit to the fleet. Ignores one already Available. */
    private function releaseVehicle(?int $vehicleId): void
    {
        if (! $vehicleId) {
            return;
        }

        $vehicle = Vehicle::where('vehicle_id', $vehicleId)
            ->lockForUpdate()
            ->first();

        if ($vehicle && $vehicle->status === 'Dispatched') {
            $vehicle->update(['status' => 'Available']);
        }
    }

    /** Shown as the notification's title on every push this controller sends — see Fcm::notifyResident(). */
    private const PUSH_TITLE = 'SERBIS';

    /** Manila wall clock, the same shape a staffer reads on the paper form and the panel. */
    private function forResident(Carbon $instant): string
    {
        return $instant->copy()->timezone(self::OFFICE_TIMEZONE)->format('M j, Y g:i A');
    }

    private function approvalPushBody(ServiceRequest $serviceRequest): string
    {
        $unit = $serviceRequest->vehicle?->unit_identifier ?? 'a unit';

        return 'Your ambulance booking for '.$this->forResident($serviceRequest->ambulanceBooking?->scheduled_at)
            .' has been approved. Unit: '.$unit.'. — MDRRMO Echague';
    }

    private function rejectionPushBody(string $reason): string
    {
        return 'Your ambulance booking request was not approved. Reason: '.$reason.' — MDRRMO Echague';
    }

    private function reschedulePushBody(ServiceRequest $serviceRequest, string $reason): string
    {
        return 'Your ambulance booking has been moved to '
            .$this->forResident($serviceRequest->ambulanceBooking?->scheduled_at).'. Reason: '.$reason.' — MDRRMO Echague';
    }

    public function update(Request $request, $id)
    {
        $serviceRequest = ServiceRequest::find($id);

        if (! $serviceRequest) {
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
            // 160 is a leftover cap from when this string was pasted into a
            // billed PhilSMS body; the SMS is gone but the column is still a
            // TEXT that took anything before this existed, so the cap stays.
            'remarks' => 'nullable|string|max:160|required_if:status,Disapproved',
            // Staff-only, never sent to PhilSMS and never returned to a resident
            // (see index()/show()) — so it carries no per-segment SMS cap.
            'internal_notes' => 'nullable|string|max:1000',
            // Same types as store()/adminStore(). Routed to AmbulanceBooking
            // below rather than written here — this table no longer carries
            // them as of writing, and correcting a typo in a patient's name
            // after intake should not require a specialised endpoint.
            'patient_name' => 'sometimes|nullable|string|max:255',
            'patient_age' => 'sometimes|nullable|integer|min:0|max:120',
            'patient_sex' => 'sometimes|nullable|in:male,female',
            'patient_address' => 'sometimes|nullable|string|max:255',
            'patient_contact_number' => 'sometimes|nullable|string|max:32',
            'pickup_location' => 'sometimes|nullable|string|max:255',
            'destination' => 'sometimes|nullable|string|max:255',
            'condition_notes' => 'sometimes|nullable|string|max:5000',
        ]);

        $ambulanceServiceId = $this->ambulanceServiceId();
        $isAmbulanceRequest = $ambulanceServiceId !== null && $serviceRequest->service_id === $ambulanceServiceId;

        // The transition matrix. A resend of the current status (every PUT
        // this panel makes carries one, whether or not the operator actually
        // changed it — see updateStatus() and saveInternalNote() on the Vue
        // side) is a no-op, not a transition, so it is exempted before the
        // map is even consulted: that is what keeps a vehicle swap on an
        // already-Responding request, or a second identical Disapprove,
        // working exactly as before. An actual change of status is checked
        // against ALLOWED_TRANSITIONS; nothing about it being the value
        // already stored allows a status change any table below would allow.
        if (array_key_exists('status', $validated) && $validated['status'] !== $serviceRequest->status) {
            $allowedFrom = self::ALLOWED_TRANSITIONS[$validated['status']] ?? [];

            if (! in_array($serviceRequest->status, $allowedFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot move from {$serviceRequest->status} to {$validated['status']}.",
                ]);
            }
        }

        // Second-order guard, ambulance only: Booked -> Responding is legal
        // by the matrix above — the non-ambulance instant-approval path and
        // the manual "Dispatch" button both need it — but for an ambulance
        // booking specifically it must still have gone through approve()
        // first. approve() re-checks unit availability under a lock this
        // method never takes, stamps approved_at, and sends the approval
        // SMS; reaching Responding straight from Booked skipped all three.
        if ($isAmbulanceRequest
            && $serviceRequest->status === 'Booked'
            && ($validated['status'] ?? null) === 'Responding'
            && ! $serviceRequest->ambulanceBooking?->approved_at
        ) {
            throw ValidationException::withMessages([
                'status' => 'This booking must be approved before it can be dispatched.',
            ]);
        }

        // The bridge's other half (docs/dispatch-audit.md finding 1): the
        // instant path could always reach Resolved with zero rows in
        // tbl_conduction_requests. Checked before the transaction below so a
        // rejection is a clean 422, not a status flip followed by an error.
        if ($isAmbulanceRequest && ($validated['status'] ?? null) === 'Resolved') {
            $trip = $serviceRequest->conductionRequests()->latest('conduction_request_id')->first();
            $missing = [];

            if (! $trip) {
                $missing[] = 'a trip record — approve the dispatch again to create one';
            } else {
                // Either satisfies the arrival requirement: a real arrival, or
                // a stated reason the trip never got there (patient already
                // left, crew recalled mid-route, transport refused). The
                // driver requirement below is unconditional either way — a
                // crew went out regardless of how the trip ended.
                if (! $trip->arrived_destination_at && ! $trip->no_arrival_reason) {
                    $missing[] = 'arrival time (or a reason it never arrived)';
                }
                // Odometer readings are deliberately NOT required here. They
                // are often not to hand when the trip is closed out, and
                // holding a finished trip open for them meant the status said
                // 'Responding' for a crew already back at the office.
                // ConductionRequestController still enforces
                // odometer_end >= odometer_start whenever both are entered.
                if (! $trip->drivers()->exists()) {
                    $missing[] = 'a driver';
                }
            }

            if ($missing) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot resolve — missing '.implode(', ', $missing).'.',
                ]);
            }
        }

        // The picker's two rules, which until now lived only in the panel
        // (ServiceRequestQueue.vue's availableVehicles): a unit must be
        // Available, and its type must match the board — an ambulance request
        // takes an Ambulance and nothing else, every other request takes
        // anything but an Ambulance. These rules validated nothing beyond
        // exists:tbl_vehicles, so a direct API call could put a Fire Truck on
        // an ambulance booking or a unit under Maintenance on any request.
        //
        // Two exemptions, both of them the common case rather than an edge:
        // the unit already attached to this request (every PUT re-sends the
        // current vehicle_id — see syncFleet's docblock — and that unit is
        // Dispatched, not Available), and a move to a terminal status, where
        // the vehicle is being handed back rather than claimed.
        $incomingVehicleId = isset($validated['vehicle_id']) ? (int) $validated['vehicle_id'] : null;

        $movingToTerminal = in_array(
            $validated['status'] ?? $serviceRequest->status,
            self::TERMINAL_STATUSES,
            true,
        );

        if ($incomingVehicleId
            && $incomingVehicleId !== $serviceRequest->vehicle_id
            && ! $movingToTerminal
        ) {
            $incomingVehicle = Vehicle::where('vehicle_id', $incomingVehicleId)->first();

            if ($isAmbulanceRequest !== ($incomingVehicle->type === 'Ambulance')) {
                throw ValidationException::withMessages([
                    'vehicle_id' => $isAmbulanceRequest
                        ? 'That unit is not an Ambulance.'
                        : 'An Ambulance is only assigned to an ambulance request.',
                ]);
            }

            if ($incomingVehicle->status === 'Maintenance') {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'That unit is under Maintenance and cannot be assigned.',
                ]);
            }

            if ($incomingVehicle->status !== 'Available') {
                throw ValidationException::withMessages([
                    'vehicle_id' => $incomingVehicle->unit_identifier.' is no longer available — pick another unit.',
                ]);
            }
        }

        // Captured before update() overwrites status: rejecting a booking is
        // the case this endpoint notifies for (the panel's older, unscheduled
        // Pending -> Disapproved flow is not "a booking" and stays silent).
        $wasBookingRejection = $serviceRequest->ambulanceBooking?->scheduled_at !== null
            && $serviceRequest->status !== 'Disapproved'
            && ($validated['status'] ?? null) === 'Disapproved';

        DB::transaction(function () use ($serviceRequest, $validated, $isAmbulanceRequest) {
            $this->syncFleet($serviceRequest, $validated);

            $bookingFields = Arr::only($validated, self::BOOKING_FIELDS);

            $serviceRequest->update(Arr::except($validated, self::BOOKING_FIELDS));

            // Same invariant as store()/adminStore(): only an ambulance
            // request ever gets a booking row. A non-ambulance request
            // sending one of these fields has it silently dropped, same as
            // it always was.
            if ($isAmbulanceRequest && $bookingFields) {
                AmbulanceBooking::updateOrCreate(['request_id' => $serviceRequest->request_id], $bookingFields);
            }

            // The bridge itself: this is the one place the instant path ever
            // transitions to Responding, so it is the one place that can
            // guarantee a linked trip record exists from here on. Guarded on
            // conductionRequests()->exists() so re-approving (a vehicle swap,
            // say) never creates a second one next to a trip already in
            // progress.
            if ($isAmbulanceRequest
                && ($validated['status'] ?? null) === 'Responding'
                && ! $serviceRequest->conductionRequests()->exists()
            ) {
                $this->createConductionStub($serviceRequest);
            }
        });

        if ($wasBookingRejection) {
            $this->fcm->notifyResident($serviceRequest->resident_id, self::PUSH_TITLE, $this->rejectionPushBody((string) $validated['remarks']));
        }

        return response()->json($serviceRequest->fresh(['vehicle', 'conductionRequests.people']));
    }

    /**
     * The stub C5 creates the moment an ambulance request goes Responding.
     * Filled from whatever the request already has — C3's structured columns
     * when the walk-in form supplied them, the account's own contact — and
     * the same honest placeholders AmbulanceFormData already writes into
     * `description` when a mobile submission leaves a field blank, so a
     * gap here reads the same way a gap already did. patient_name,
     * patient_address, patient_contact_number, medical_diagnosis, origin and
     * destination are the six columns NOT NULL at the database level
     * (docs/dispatch-audit.md finding 7). `departed_office_at` is stamped
     * with dispatch time below — without it, trip_status derives to 'Not
     * dispatched' and the Trip Logs tab reads "Booked" while the Bookings
     * tab already reads "Responding" for the same request. Every other trip
     * field is still filled in later, by hand, while the crew is actually
     * out.
     *
     * patient_age/patient_sex and the free-text vehicle snapshot are
     * nullable, so they were silently left off this stub even though the
     * request already had the first two and the fleet record already had the
     * last one — the trip's own detail view then showed N/A for all three on
     * every auto-dispatched trip. `vehicle` mirrors onSelectFleetVehicle in
     * ConductionRequestView.vue exactly, so a stub reads the same as a
     * manually-created trip for the same unit.
     *
     * plate_no is NOT filled here. tbl_vehicles carries no plate column any
     * more, so the trip's own plate_no is free text again — typed on the trip
     * form when someone knows it, left null when nobody does.
     */
    private function createConductionStub(ServiceRequest $serviceRequest): void
    {
        $serviceRequest->loadMissing(['resident', 'vehicle']);

        $booking = $serviceRequest->ambulanceBooking;

        $patientName = $booking?->patient_name
            ?: ($serviceRequest->resident
                ? trim("{$serviceRequest->resident->first_name} {$serviceRequest->resident->last_name}")
                : $serviceRequest->walk_in_name)
            ?: 'Not specified';

        // The patient's own number wins when intake captured one — a head of
        // the family files for whoever in the household is actually
        // travelling, so the account number is the fallback, not the answer.
        // The derivation below is unchanged and still covers every row filed
        // before this column existed.
        $contactNumber = $booking?->patient_contact_number
            ?: $serviceRequest->resident?->phone_number
            ?: $serviceRequest->walk_in_contact_number
            ?: 'See resident profile';

        $vehicle = $serviceRequest->vehicle;

        $trip = ConductionRequest::create([
            'service_request_id' => $serviceRequest->request_id,
            'vehicle_id' => $serviceRequest->vehicle_id,
            'departed_office_at' => now(),
            'patient_name' => $patientName,
            'patient_age' => $booking?->patient_age,
            'patient_sex' => $booking?->patient_sex,
            'patient_address' => $booking?->patient_address ?: null,
            'patient_contact_number' => $contactNumber,
            'medical_diagnosis' => $booking?->condition_notes ?: null,
            'origin' => $booking?->pickup_location ?: null,
            'destination' => $booking?->destination ?: null,
            'vehicle' => $vehicle
                ? $vehicle->unit_identifier.($vehicle->specification ? " ({$vehicle->specification})" : '')
                : null,
        ]);

        // Whoever the requester named at intake becomes the trip's starting
        // relative list. A stub is created with no people at all otherwise,
        // and until relatives were collected at intake there was nowhere for
        // them to have come from.
        self::copyRelativesToTrip($serviceRequest, $trip);
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

        if (! $serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if ($serviceRequest->status !== 'Booked') {
            return response()->json([
                'message' => 'Only a booked request can be approved.',
            ], 422);
        }

        $scheduledAt = $serviceRequest->ambulanceBooking?->scheduled_at;

        if (! $scheduledAt) {
            return response()->json([
                'message' => 'This request has no scheduled time to approve against.',
            ], 422);
        }

        $validated = $request->validate([
            'vehicle_id' => 'required|integer|exists:tbl_vehicles,vehicle_id',
            // Staff-adjustable; defaults to +2h below when absent. Same Manila
            // parse as everywhere else a human types a time into this system,
            // and the same horizon store() puts on scheduled_at — this is the
            // column that decides how long the unit is held.
            'scheduled_end' => 'nullable|date|before_or_equal:'.self::BOOKING_HORIZON,
        ]);

        $scheduledEnd = ! empty($validated['scheduled_end'])
            ? Carbon::parse($validated['scheduled_end'], self::OFFICE_TIMEZONE)->utc()
            : $scheduledAt->copy()->addHours(self::DEFAULT_BOOKING_HOURS);

        if ($scheduledEnd->lte($scheduledAt)) {
            throw ValidationException::withMessages([
                'scheduled_end' => 'scheduled_end must be after scheduled_at.',
            ]);
        }

        // Captured before the transaction overwrites it: a first approval and
        // a re-approval that only swaps the assigned unit both reach this
        // point, and only the first one is worth pushing to the resident — a
        // re-approval was re-sending the identical "approved" push every time.
        $wasAlreadyApproved = $serviceRequest->ambulanceBooking?->approved_at !== null;

        DB::transaction(function () use ($request, $serviceRequest, $validated, $scheduledAt, $scheduledEnd) {
            // Same serialising lock as store(): whoever gets here first
            // decides who the window's last free unit goes to.
            $units = Vehicle::where('type', 'Ambulance')->orderBy('vehicle_id')->lockForUpdate()->get();

            $vehicle = $units->firstWhere('vehicle_id', (int) $validated['vehicle_id']);

            if (! $vehicle) {
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
                ->availableAmbulances($scheduledAt, $scheduledEnd, $serviceRequest->request_id)
                ->pluck('vehicle_id');

            if (! $freeIds->contains($vehicle->vehicle_id)) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'That unit is no longer free for this window.',
                ]);
            }

            if ($serviceRequest->vehicle_id && $serviceRequest->vehicle_id !== $vehicle->vehicle_id) {
                $this->releaseVehicle($serviceRequest->vehicle_id);
            }

            $serviceRequest->update([
                'vehicle_id' => $vehicle->vehicle_id,
                'processed_by' => $request->user()->getKey(),
            ]);

            AmbulanceBooking::updateOrCreate(['request_id' => $serviceRequest->request_id], [
                'scheduled_end' => $scheduledEnd,
                'approved_at' => $serviceRequest->ambulanceBooking?->approved_at ?? now(),
            ]);
        });

        $fresh = $serviceRequest->fresh(['vehicle']);

        if (! $wasAlreadyApproved) {
            $this->fcm->notifyResident($fresh->resident_id, self::PUSH_TITLE, $this->approvalPushBody($fresh));
        }

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

        if (! $serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        if ($serviceRequest->status !== 'Booked') {
            return response()->json([
                'message' => 'Only a booked request can be rescheduled.',
            ], 422);
        }

        $validated = $request->validate([
            // Bounded here as well as in store(): this method takes its own
            // dates and never passes through resolveScheduledAt(), so a
            // booking moved to the year 3000 would otherwise be accepted by
            // the one path that exists to move bookings.
            'scheduled_at' => 'required|date|before_or_equal:'.self::BOOKING_HORIZON,
            'scheduled_end' => 'required|date|before_or_equal:'.self::BOOKING_HORIZON,
            // TracksHistory logs the scheduled_at/scheduled_end change on its
            // own, but the *reason* only reaches that log because remarks moves
            // in the same update — so it is not optional here, unlike update().
            // Capped like update()'s copy: this one always reaches the push.
            'remarks' => 'required|string|max:160',
        ]);

        $scheduledAt = Carbon::parse($validated['scheduled_at'], self::OFFICE_TIMEZONE)->utc();
        $scheduledEnd = Carbon::parse($validated['scheduled_end'], self::OFFICE_TIMEZONE)->utc();

        $this->rejectIfPast($scheduledAt);

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
                if (! $freeIds->contains($serviceRequest->vehicle_id)) {
                    throw ValidationException::withMessages([
                        'scheduled_at' => 'The assigned unit is not free for that time.',
                    ]);
                }
            } elseif ($freeIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'No ambulance is available for that time.',
                ]);
            }

            $serviceRequest->update(['remarks' => $validated['remarks']]);

            AmbulanceBooking::updateOrCreate(['request_id' => $serviceRequest->request_id], [
                'scheduled_at' => $scheduledAt,
                'scheduled_end' => $scheduledEnd,
            ]);
        });

        $fresh = $serviceRequest->fresh(['vehicle']);

        $this->fcm->notifyResident($fresh->resident_id, self::PUSH_TITLE, $this->reschedulePushBody($fresh, (string) $validated['remarks']));

        return response()->json($fresh);
    }

    public function destroy($id)
    {
        $serviceRequest = ServiceRequest::find($id);

        if (! $serviceRequest) {
            return response()->json(['message' => 'Service request not found'], 404);
        }

        $serviceRequest->delete();

        return response()->json(['message' => 'Service request successfully deleted']);
    }
}
