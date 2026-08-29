<?php

namespace App\Http\Controllers;

use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /** The trip log's four checkpoints, in the order they actually happen. */
    private const TRIP_SEQUENCE = [
        'departed_office_at' => 'Departed office',
        'arrived_destination_at' => 'Arrived at destination',
        'departed_destination_at' => 'Departed destination',
        'returned_office_at' => 'Returned to office',
    ];

    public function index()
    {
        $requests = ConductionRequest::with('people')->latest()->get();

        return response()->json($requests);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_name' => 'required|string|max:255',
            'patient_age' => 'nullable|integer|min:0|max:150',
            'patient_address' => 'required|string|max:255',
            'patient_sex' => 'nullable|in:male,female',
            'patient_contact_number' => 'required|string|max:32',
            'vehicle' => 'nullable|string|max:255',
            'medical_diagnosis' => 'required|string',
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
        $conductionRequest = ConductionRequest::with('people')->find($id);

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
            'others' => 'sometimes|nullable|string',
        ]);

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
}
