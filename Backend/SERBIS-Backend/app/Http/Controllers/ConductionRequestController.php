<?php

namespace App\Http\Controllers;

use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConductionRequestController extends Controller
{
    /**
     * Personnel roles the paper form has a section for, in form order. Keyed
     * by the request field the create form sends an array of names under.
     */
    private const PEOPLE_FIELDS = [
        'drivers' => 'driver',
        'authorized_passengers' => 'passenger',
        'patient_relatives' => 'relative',
    ];

    /**
     * The wall clock the trip log is written against. The four checkpoints are
     * typed into <input type="datetime-local">, which sends a naive string with
     * no offset, and the MDRRMO office runs on Manila time and enters nothing
     * else. Named here rather than read from config('app.timezone'): that one is
     * UTC and governs how the application stores instants, which is the opposite
     * end of this conversion.
     */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    /**
     * Ceiling on an odometer reading, in kilometres.
     *
     * The column is `unsignedInteger`, so without this the only limit was
     * 4,294,967,295 — an extra digit on a six-figure reading stored silently
     * and the end->=-start check below still passed, because both readings
     * were wrong together.
     *
     * A six-digit odometer physically cannot display past 999,999, and a
     * municipal rescue vehicle reaching a million kilometres would be roughly
     * 25 years at 40,000 km a year. So this rejects nothing a staffer could
     * read off a dashboard, and catches the fat-fingered extra digit.
     */
    private const MAX_ODOMETER = 1000000;

    /**
     * How many names one role may carry on a single trip.
     *
     * `tbl_conduction_request_people.position` is an `unsignedTinyInteger`, so
     * the 256th name in a role writes 256 into a column that stops at 255 and
     * takes the whole transaction down with a 500. The arrays were bounded per
     * element (`max:255` on each name) and not in length.
     *
     * Twenty is an order of magnitude above any real trip — the paper form
     * prints two slots per role. It also has to leave room for
     * ServiceRequestController::copyRelativesToTrip(), which APPENDS a
     * booking's intake relatives onto a trip that may already carry names
     * typed into the create dialog: the worst case is two full lists on one
     * trip, 40 rows, still nowhere near the column's limit.
     */
    private const MAX_PEOPLE_PER_ROLE = 20;

    /**
     * Authorized passengers are capped harder than the other two roles: the
     * ambulance carries at most two people riding under that role, which is
     * the operational rule, not a column limit.
     *
     * Only this role is narrowed. Drivers keep MAX_PEOPLE_PER_ROLE, and
     * relatives must keep it because copyRelativesToTrip() appends a booking's
     * intake relatives onto a trip that may already hold typed names — that
     * append writes rows directly and never passes through this validation, so
     * narrowing the relative limit here would not bound it anyway.
     */
    private const MAX_AUTHORIZED_PASSENGERS = 2;

    /** The trip log's four checkpoints, in the order they actually happen. */
    private const TRIP_SEQUENCE = [
        'departed_office_at' => 'Departed office',
        'arrived_destination_at' => 'Arrived at destination',
        'departed_destination_at' => 'Departed destination',
        'returned_office_at' => 'Returned to office',
    ];

    public function index()
    {
        // serviceRequest eager-loaded so the panel can show the booking a trip
        // log fulfils without a second round trip per row.
        $requests = ConductionRequest::with(['people', 'serviceRequest'])->latest()->get();

        return response()->json($requests);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Links this trip log back to the booking it fulfils, and the unit
            // running it. Both nullable: a walk-in trip with no prior booking is
            // still the common case, and nothing before this wrote either — the
            // model has carried them in $fillable since the columns were added,
            // but store() never actually accepted them as input until now.
            'service_request_id' => 'nullable|integer|exists:tbl_service_request,request_id',
            // A trip log is always an ambulance dispatch — this controller has
            // no other kind of request to log — so the unit attached must be
            // one, the same rule ServiceRequestController::update() enforces
            // on its own manual vehicle assignment (MDRRMO feedback,
            // 2026-09-17). Previously exists-checked only, so a raw API call
            // could put a Boat or Fire Truck on a trip record.
            'vehicle_id' => 'nullable|integer|exists:tbl_vehicles,vehicle_id,type,Ambulance',
            'patient_name' => 'required|string|max:255',
            // min:0 stays — a neonate transport is a real ambulance case and 0
            // is the honest reading. 150 was not defensible: the oldest
            // verified human lived to 122.
            'patient_age' => 'nullable|integer|min:0|max:120',
            'patient_address' => 'required|string|max:255',
            'patient_sex' => 'nullable|in:male,female',
            'patient_contact_number' => 'required|string|max:32',
            // Fallback only, for a unit outside the fleet table entirely
            // (mutual aid from a neighbouring LGU) — the panel now sources
            // this from tbl_vehicles via vehicle_id whenever the unit is one
            // of ours. Kept independent of vehicle_id: a row can carry a
            // real vehicle_id and this can still be blank, or vice versa.
            'vehicle' => 'nullable|string|max:255',
            'medical_diagnosis' => 'required|string|max:5000',
            'plate_no' => 'nullable|string|max:32',
            'origin' => 'required|string|max:255',
            'destination' => 'required|string|max:255',

            // The form shows 2 slots per role but must scale past that, so
            // these are arrays rather than fixed driver_1/driver_2 inputs.
            // Bounded in length as well as per element — see
            // MAX_PEOPLE_PER_ROLE for what the 256th name does to `position`.
            'drivers' => 'nullable|array|max:'.self::MAX_PEOPLE_PER_ROLE,
            'drivers.*' => 'nullable|string|max:255',
            'authorized_passengers' => 'nullable|array|max:'.self::MAX_AUTHORIZED_PASSENGERS,
            'authorized_passengers.*' => 'nullable|string|max:255',
            'patient_relatives' => 'nullable|array|max:'.self::MAX_PEOPLE_PER_ROLE,
            'patient_relatives.*' => 'nullable|string|max:255',

            // Only meaningful, and only ever stored, when filing over an
            // actual conflict below — see the double-booking guard.
            'override_reason' => 'nullable|string|max:500',
        ], [
            'vehicle_id.exists' => 'That unit is not an Ambulance.',
        ]);

        // A hard block, unlike the vehicle conflict below: a booking maps to
        // at most one trip, full stop, so there is no override_reason for
        // this one — the ServiceRequestQueue "Dispatch" button used to stay
        // clickable after the trip it dispatched was already filed, and a
        // second click filed a second trip against the same booking with
        // nothing to stop it. The Booked→Responding flip in this same method
        // now closes the button-side hole; this closes it at the one place
        // every caller (button, and the create dialog's own booking search)
        // actually goes through.
        if (! empty($validated['service_request_id'])) {
            $existingTrip = ConductionRequest::where('service_request_id', $validated['service_request_id'])->first();

            if ($existingTrip) {
                return response()->json([
                    'message' => 'This booking already has a trip record filed against it.',
                    'conflict' => [
                        'conduction_request_id' => $existingTrip->conduction_request_id,
                        'destination' => $existingTrip->destination,
                    ],
                ], 409);
            }
        }

        // A soft block, not a hard one: a unit already out on a trip is
        // exactly the kind of thing a genuine emergency sometimes has to
        // reassign anyway (see the plan's own reasoning — a system that
        // makes that impossible gets worked around outside the system).
        // "Open" matches ConductionRequest::getTripStatusAttribute()'s own
        // 'In transit' definition, not a new one: departed, not yet back.
        $conflict = ! empty($validated['vehicle_id'])
            ? ConductionRequest::where('vehicle_id', $validated['vehicle_id'])
                ->whereNotNull('departed_office_at')
                ->whereNull('returned_office_at')
                ->first()
            : null;

        if ($conflict && empty($validated['override_reason'])) {
            return response()->json([
                'message' => 'This unit is already on a trip.',
                'conflict' => [
                    'conduction_request_id' => $conflict->conduction_request_id,
                    'destination' => $conflict->destination,
                ],
            ], 409);
        }

        // Recorded only when it was actually filed over a conflict — an
        // override_reason sent with no conflict present (or none sent at
        // all) leaves this null rather than storing noise.
        $overrideReason = $conflict ? $validated['override_reason'] : null;

        $conductionRequest = DB::transaction(function () use ($validated, $overrideReason) {
            $conductionRequest = ConductionRequest::create([
                ...Arr::except($validated, ['override_reason']),
                'vehicle_override_reason' => $overrideReason,
            ]);

            foreach (self::PEOPLE_FIELDS as $field => $role) {
                $position = 0;
                foreach ($validated[$field] ?? [] as $name) {
                    // An empty slot on the 2-per-role form is not a person —
                    // filtered here rather than in the rule above, so a blank
                    // second driver field does not fail validation.
                    $name = trim((string) $name);
                    if ($name === '') {
                        continue;
                    }

                    ConductionRequestPerson::create([
                        'conduction_request_id' => $conductionRequest->conduction_request_id,
                        'role' => $role,
                        'name' => $name,
                        'position' => $position++,
                    ]);
                }
            }

            // Mirrors the guard ServiceRequestController::update() uses
            // around createConductionStub for the instant path: only ever
            // Booked → Responding, never any other status. That is the one
            // state the create dialog can ever hand this a linked booking
            // in — linkableBookings on the Vue side (and the duplicate
            // guard above) already ensure nothing reaches here twice, so
            // this is belt-and-suspenders, not the only thing stopping a
            // double transition. Before this, the manual "Dispatch" path
            // filed the trip but left the booking sitting at Booked
            // forever — which is also why "Mark as Resolved" (gated on
            // status === 'Responding') was unreachable for a manually
            // dispatched booking.
            if (! empty($validated['service_request_id'])) {
                $serviceRequest = ServiceRequest::find($validated['service_request_id']);

                if ($serviceRequest) {
                    // The other half of the dispatch bridge's relative copy.
                    // ServiceRequestController::createConductionStub() does
                    // this for the automatic Booked → Responding flip; this
                    // is the manually filed trip against the same booking,
                    // and it owns the same obligation. Shared method, not a
                    // second copy of the loop — the two-path split is what
                    // docs/dispatch-audit.md flagged as the drift hazard.
                    //
                    // Runs regardless of status, unlike the flip below: a
                    // trip filed against a booking already moved out of
                    // Booked still needs the relatives it was filed for.
                    ServiceRequestController::copyRelativesToTrip($serviceRequest, $conductionRequest);

                    if ($serviceRequest->status === 'Booked') {
                        $serviceRequest->update(['status' => 'Responding']);
                    }
                }
            }

            return $conductionRequest;
        });

        return response()->json($conductionRequest->fresh('people'), 201);
    }

    public function show($id)
    {
        $conductionRequest = ConductionRequest::with(['people', 'serviceRequest'])->find($id);

        if (! $conductionRequest) {
            return response()->json(['message' => 'Conduction request not found'], 404);
        }

        return response()->json($conductionRequest);
    }

    /**
     * Admin-only printable rendering of the paper form. Eager-loads what
     * conduction-request.blade.php expects (its own doc comment): people for
     * drivers/passengers/manually-typed relatives, serviceRequest.relatives
     * for a bridged trip's intake-named relatives. serviceRequest's own
     * ambulanceBooking comes along automatically — see ServiceRequest::$with.
     */
    public function print($id)
    {
        $conductionRequest = ConductionRequest::with(['people', 'serviceRequest.relatives'])->find($id);

        if (! $conductionRequest) {
            abort(404, 'Conduction request not found');
        }

        return view('conduction-request', ['trip' => $conductionRequest]);
    }

    /**
     * The trip log is filled in over several calls as the trip actually
     * happens — office staff cannot know the return odometer reading at
     * dispatch time — so every field is `sometimes`: a call touches only
     * the checkpoints it is reporting and leaves the rest exactly as they
     * were, rather than overwriting them back to null.
     */
    public function tripLog(Request $request, $id)
    {
        $conductionRequest = ConductionRequest::find($id);

        if (! $conductionRequest) {
            return response()->json(['message' => 'Conduction request not found'], 404);
        }

        $validated = $request->validate([
            'departed_office_at' => 'sometimes|nullable|date',
            'arrived_destination_at' => 'sometimes|nullable|date',
            'no_arrival_reason' => 'sometimes|nullable|string|max:500',
            'departed_destination_at' => 'sometimes|nullable|date',
            'returned_office_at' => 'sometimes|nullable|date',
            'odometer_start' => 'sometimes|nullable|integer|min:0|max:'.self::MAX_ODOMETER,
            'odometer_end' => 'sometimes|nullable|integer|min:0|max:'.self::MAX_ODOMETER,
            'others' => 'sometimes|nullable|string|max:5000',
            // All three PEOPLE_FIELDS roles, not just drivers — a stub the
            // dispatch bridge creates (createConductionStub) starts with
            // none of them, and until this form could add passengers and
            // relatives too, the only way to record either was to have
            // known them at the moment the trip was first filed, which the
            // bridge path never is.
            'drivers' => 'sometimes|array|max:'.self::MAX_PEOPLE_PER_ROLE,
            'drivers.*' => 'nullable|string|max:255',
            'authorized_passengers' => 'sometimes|array|max:'.self::MAX_AUTHORIZED_PASSENGERS,
            'authorized_passengers.*' => 'nullable|string|max:255',
            'patient_relatives' => 'sometimes|array|max:'.self::MAX_PEOPLE_PER_ROLE,
            'patient_relatives.*' => 'nullable|string|max:255',
        ]);

        // Naive checkpoint strings are office local, not UTC. Read under
        // app.timezone a typed 09:00 was taken to mean 09:00 UTC, so the column
        // held an instant eight hours off the trip it described — invisible only
        // because nothing ever compared a checkpoint against created_at or now().
        // Converted here, before every check below, so the comparisons and the
        // stored value are all real instants. A string that does carry an offset
        // is honoured as sent rather than re-read as Manila — accepting both
        // shapes on this one endpoint, indefinitely for now, is deliberate: the
        // admin panel's <input type="datetime-local"> can only ever send the
        // naive shape, so that is not a temporary skew to wait out. What this
        // does watch for is a caller that starts sending the offset-carrying
        // shape instead — the day this admin form (or any other client) is
        // changed to send one, the naive branch below should stop firing
        // entirely, and the log line is what makes that transition visible
        // rather than assumed.
        foreach (array_keys(self::TRIP_SEQUENCE) as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $raw = (string) $validated[$field];

                if (! self::carriesExplicitOffset($raw)) {
                    Log::info('Conduction checkpoint received with no UTC offset — read as Asia/Manila.', [
                        'conduction_request_id' => $conductionRequest->conduction_request_id,
                        'field' => $field,
                    ]);
                }

                $validated[$field] = Carbon::parse(
                    $raw,
                    self::OFFICE_TIMEZONE
                )->utc();
            }
        }

        // Checked against the *effective* record — this update's fields layered
        // over what is already stored — not just the fields sent in this one
        // call, or reporting "arrived" on its own could pass while landing
        // earlier than a "departed" saved in an earlier call.
        $effective = fn (string $field) => array_key_exists($field, $validated)
            ? $validated[$field]
            : $conductionRequest->getAttribute($field);

        $odometerStart = $effective('odometer_start');
        $odometerEnd = $effective('odometer_end');

        if ($odometerStart !== null && $odometerEnd !== null && $odometerEnd < $odometerStart) {
            throw ValidationException::withMessages([
                'odometer_end' => 'Odometer reading on return must be at or after the reading at departure.',
            ]);
        }

        // A trip either arrived or it did not. Both set is not a state anyone
        // can act on: the resolve gate in ServiceRequestController::update()
        // takes either as satisfying the arrival requirement, so a row with
        // both passes for the wrong reason, and the printed conduction form
        // shows an arrival time beside a reason it never arrived.
        $arrived = $effective('arrived_destination_at');
        $noArrival = $effective('no_arrival_reason');

        if ($arrived !== null && filled($noArrival)) {
            throw ValidationException::withMessages([
                'no_arrival_reason' => 'This trip has an arrival time recorded. A trip either arrived or it did not — clear the arrival time, or clear this reason.',
            ]);
        }

        // There is no departure from a destination the crew never reached.
        // no_arrival_reason exists for the cases where the vehicle turned back
        // — patient already left, crew recalled mid-route, transport refused —
        // and in every one of them this field describes something that did not
        // happen. It prints on the signed conduction form, where it reads as a
        // data-entry error rather than a turnaround.
        if (filled($noArrival) && $effective('departed_destination_at') !== null) {
            throw ValidationException::withMessages([
                'departed_destination_at' => 'This trip never arrived, so there is no departure from the destination to record. Clear the reason if it did arrive.',
            ]);
        }

        // The checkpoints this trip is actually expected to have.
        //
        // A trip that never arrived runs office -> back to office, and holding
        // it to the full four-step sequence is what made both remaining
        // checkpoints unreachable: with arrival blank, the adjacency check
        // below refused departed_destination_at, and refusing that refused
        // returned_office_at in turn. The crew came home and the log could not
        // say so. The two fields dropped from the sequence here are the two the
        // guards above have already refused outright, so nothing is skipped
        // that could still be present.
        $sequence = filled($noArrival)
            ? [
                'departed_office_at' => 'Departed office',
                'returned_office_at' => 'Returned to office',
            ]
            : self::TRIP_SEQUENCE;

        // A later checkpoint filled in while an earlier one is still blank
        // leaves a record the panel cannot describe: `trip_status` reads
        // 'Completed' off `returned_office_at` while the detail view still
        // offers to start the trip off a null `departed_office_at`. The
        // chronological check below only compares checkpoints that are
        // filled, so it cannot catch a gap on its own.
        $previousField = null;
        $previousLabel = null;
        foreach ($sequence as $field => $label) {
            if ($effective($field) !== null && $previousField !== null && $effective($previousField) === null) {
                throw ValidationException::withMessages([
                    $field => "{$label} cannot be recorded while {$previousLabel} is still blank.",
                ]);
            }

            $previousField = $field;
            $previousLabel = $label;
        }

        $checkpoints = [];
        foreach ($sequence as $field => $label) {
            $value = $effective($field);
            if ($value !== null) {
                $checkpoints[] = ['field' => $field, 'label' => $label, 'at' => Carbon::parse($value)];
            }
        }

        for ($i = 1; $i < count($checkpoints); $i++) {
            if ($checkpoints[$i]['at']->lt($checkpoints[$i - 1]['at'])) {
                throw ValidationException::withMessages([
                    $checkpoints[$i]['field'] => "{$checkpoints[$i]['label']} cannot be earlier than {$checkpoints[$i - 1]['label']}.",
                ]);
            }
        }

        // Replaces the role wholesale rather than diffing — same approach
        // store() takes for all three roles at creation. Each role is only
        // ever touched when its own field is actually sent, matching the
        // 'sometimes' semantics the rest of this endpoint uses: a call that
        // reports a checkpoint but says nothing about passengers must not
        // wipe passengers already on record.
        foreach (self::PEOPLE_FIELDS as $field => $role) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $conductionRequest->people()->where('role', $role)->delete();

            $position = 0;
            foreach ($validated[$field] as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }

                ConductionRequestPerson::create([
                    'conduction_request_id' => $conductionRequest->conduction_request_id,
                    'role' => $role,
                    'name' => $name,
                    'position' => $position++,
                ]);
            }
        }

        $conductionRequest->update($validated);

        return response()->json($conductionRequest->fresh('people'));
    }

    /**
     * Whether a datetime string names its own UTC offset — 'Z', or a numeric
     * '+08:00'/'+0800' suffix — rather than being naive wall clock. Only the
     * shape is checked, not the value itself; Carbon::parse() below still does
     * the real parsing and still wins if this is ever wrong about a format it
     * has not seen before.
     */
    private static function carriesExplicitOffset(string $value): bool
    {
        return (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value));
    }
}
