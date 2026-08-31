<?php

namespace App\Http\Controllers;

use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use Illuminate\Http\Request;
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
            'vehicle_id' => 'nullable|integer|exists:tbl_vehicles,vehicle_id',
            'patient_name' => 'required|string|max:255',
            'patient_age' => 'nullable|integer|min:0|max:150',
            'patient_address' => 'required|string|max:255',
            'patient_sex' => 'nullable|in:male,female',
            'patient_contact_number' => 'required|string|max:32',
            'vehicle' => 'nullable|string|max:255',
            'medical_diagnosis' => 'required|string|max:5000',
            'plate_no' => 'nullable|string|max:32',
            'origin' => 'required|string|max:255',
            'destination' => 'required|string|max:255',

            // The form shows 2 slots per role but must scale past that, so
            // these are arrays rather than fixed driver_1/driver_2 inputs.
            'drivers' => 'nullable|array',
            'drivers.*' => 'nullable|string|max:255',
            'authorized_passengers' => 'nullable|array',
            'authorized_passengers.*' => 'nullable|string|max:255',
            'patient_relatives' => 'nullable|array',
            'patient_relatives.*' => 'nullable|string|max:255',
        ]);

        $conductionRequest = DB::transaction(function () use ($validated) {
            $conductionRequest = ConductionRequest::create($validated);

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

            return $conductionRequest;
        });

        return response()->json($conductionRequest->fresh('people'), 201);
    }

    public function show($id)
    {
        $conductionRequest = ConductionRequest::with(['people', 'serviceRequest'])->find($id);

        if (!$conductionRequest) {
            return response()->json(['message' => 'Conduction request not found'], 404);
        }

        return response()->json($conductionRequest);
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

        if (!$conductionRequest) {
            return response()->json(['message' => 'Conduction request not found'], 404);
        }

        $validated = $request->validate([
            'departed_office_at' => 'sometimes|nullable|date',
            'arrived_destination_at' => 'sometimes|nullable|date',
            'departed_destination_at' => 'sometimes|nullable|date',
            'returned_office_at' => 'sometimes|nullable|date',
            'odometer_start' => 'sometimes|nullable|integer|min:0',
            'odometer_end' => 'sometimes|nullable|integer|min:0',
            'others' => 'sometimes|nullable|string|max:5000',
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

                if (!self::carriesExplicitOffset($raw)) {
                    Log::info('Conduction checkpoint received with no UTC offset — read as Asia/Manila.', [
                        'conduction_request_id' => $conductionRequest->conduction_request_id,
                        'field' => $field,
                    ]);
                }

                $validated[$field] = \Carbon\Carbon::parse(
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

        // A later checkpoint filled in while an earlier one is still blank
        // leaves a record the panel cannot describe: `trip_status` reads
        // 'Completed' off `returned_office_at` while the detail view still
        // offers to start the trip off a null `departed_office_at`. The
        // chronological check below only compares checkpoints that are
        // filled, so it cannot catch a gap on its own.
        $previousField = null;
        $previousLabel = null;
        foreach (self::TRIP_SEQUENCE as $field => $label) {
            if ($effective($field) !== null && $previousField !== null && $effective($previousField) === null) {
                throw ValidationException::withMessages([
                    $field => "{$label} cannot be recorded while {$previousLabel} is still blank.",
                ]);
            }

            $previousField = $field;
            $previousLabel = $label;
        }

        $checkpoints = [];
        foreach (self::TRIP_SEQUENCE as $field => $label) {
            $value = $effective($field);
            if ($value !== null) {
                $checkpoints[] = ['field' => $field, 'label' => $label, 'at' => \Carbon\Carbon::parse($value)];
            }
        }

        for ($i = 1; $i < count($checkpoints); $i++) {
            if ($checkpoints[$i]['at']->lt($checkpoints[$i - 1]['at'])) {
                throw ValidationException::withMessages([
                    $checkpoints[$i]['field'] => "{$checkpoints[$i]['label']} cannot be earlier than {$checkpoints[$i - 1]['label']}.",
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
