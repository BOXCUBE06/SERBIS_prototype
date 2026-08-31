# Ambulance Dispatch — Phase 0 Audit

Read-only. Nothing in this file has been applied. Produced against
`update-admin-vue` at commit `25d3440`.

---

## 1. State machine

Two creation paths write to `tbl_service_request`, both in
`ServiceRequestController.php` and both landing on the same status rule:

```php
'status' => $scheduledAt ? 'Booked' : 'Pending',
```

- `store()` (resident, via the Flutter app) — line 198.
- `walkIn()` (staff, "Log Walk-in Request" dialog) — line 419.

They are near-duplicates (both lock the Ambulance fleet, both run the same
`required_vehicle_type` auto-claim for an immediate non-ambulance request,
both set `vehicle_id`/`scheduled_at` the same way). **One divergence that
matters here:** `required_vehicle_type` is only ever sent by `scope !== 'ambulance'`
call sites in the Vue form (`ServiceRequestQueue.vue:873,896` both gate on
`scope !== 'ambulance'`), so the immediate-auto-claim branch
(`ServiceRequestController.php:151-161,384-394`) is dead code for ambulance
specifically — an ambulance's `vehicle_id` is always null at creation,
scheduled or not.

**Instant path** (no `scheduled_at`):

| From | To | Trigger | File |
|---|---|---|---|
| — | `Pending` | resident submits / staff logs walk-in | `ServiceRequestController.php:198,419` |
| `Pending` | `Responding` | "Approve & Dispatch" → `confirmReason()` → `updateStatus('Responding')` | `ServiceRequestQueue.vue:478,1196-1208` → `PATCH /service-requests/{id}` → `ServiceRequestController::update()` |
| `Pending` | `Disapproved` | "Disapprove" → `openReason('disapprove')` | `ServiceRequestQueue.vue:467` |
| `Pending` | `Cancelled` | resident, own app | `ServiceRequestController::cancel()` (~line 500-573) |
| `Responding` | `Resolved` | "Mark as Resolved" → `updateStatus('Resolved')` | `ServiceRequestQueue.vue:487` |

**No step in this path ever calls `ConductionRequestController::store()`.**
Confirms finding 1 directly: an instant ambulance dispatch can reach the
terminal status `Resolved` with zero rows ever written to
`tbl_conduction_requests`.

**Scheduled path** (`scheduled_at` set):

| From | To | Trigger | File |
|---|---|---|---|
| — | `Booked` | resident submits / staff logs walk-in with a time | same as above |
| `Booked` | `Booked` (vehicle assigned) | "Assign Unit" → "Approve" → `ServiceRequestController::approve()` | `ServiceRequestQueue.vue:540` → `approve()` line 825-909 |
| `Booked` | `Booked` (rescheduled) | "Reschedule" | `ServiceRequestQueue.vue:526` → `reschedule()` line 917-980 |
| `Booked` (assigned) | *(tab switch, no status change)* | "Dispatch" → `emit('dispatch-booking', ...)` | `ServiceRequestQueue.vue:555` |
| — | *(conduction request created)* | "File request" on the pre-filled trip form | `ConductionRequestController::store()` |
| `Booked` | `Disapproved` / `Cancelled` | Reject / resident cancel | same endpoints as instant path |

Note the scheduled path's "Dispatch" click does **not** itself change
`tbl_service_request.status` — `Booked` stays `Booked` right through to
whatever `ConductionRequestController::store()` and later trip-log calls do.
Nothing in the codebase today ever flips a scheduled ambulance booking to
`Responding` or `Resolved`. Confirmed by grep: `Responding`/`Resolved` are
only ever set via `ServiceRequestQueue.vue`'s `updateStatus()`, which the
`Booked` template branch (lines 491-559) never calls.

---

## 2. Dispatch button's guard condition

`Web/serbis-admin-vue/src/components/ServiceRequestQueue.vue:491,496,551`:

```vue
<template v-else-if="selectedRequest.status === 'Booked'">
  <template v-if="!selectedRequest.vehicle_id">
    <!-- Assign Unit / Approve -->
  </template>
  <template v-else>
    <v-btn ... @click="emit('dispatch-booking', selectedRequest)">Dispatch</v-btn>
  </template>
</template>
```

Full condition for the Dispatch button to render: `status === 'Booked'`
**and** `vehicle_id` is non-null. There is no equivalent branch anywhere in
the template for `status === 'Responding'` or `status === 'Pending'`.

---

## 3. The regex parser

`Web/serbis-admin-vue/src/views/ConductionRequestView.vue:534-558`,
`parseAmbulanceDescription(description)`.

Extracts, by line-prefix match on the booking's `description` text:

| Field | Pattern | Placeholder treated as empty |
|---|---|---|
| `patient_name` | `/^Patient:\s*(.*)$/` | `'Not specified'` |
| `medical_diagnosis` | `/^Condition:\s*(.*)$/` | `'Not described'` |
| `patient_contact_number` | `/^Contact:\s*(.*)$/` | `'See resident profile'` |
| `origin` / `destination` | line containing `→`, split on it | `'Address not specified'` / `'destination not specified'` |

**Failure behavior:** silent, per-field. A line that matches no pattern is
simply skipped (the `for` loop has no `else`). A field whose line is
missing entirely, or whose value equals the exact placeholder string, comes
back as `''` — the form then shows an empty required field, not an error.
Nothing surfaces "this description could not be parsed" anywhere; the
`AMBULANCE_PLACEHOLDERS` map (line 521-527) is the only defense, and it is a
literal string match, not a fuzzy one — the mobile app changing its
placeholder wording (`AmbulanceFormData`, `Mobile/lib/models/service_forms.dart:79-83`)
would silently break the empty-detection with no error anywhere.

---

## 4. `vehicle_id` assignment on `tbl_conduction_requests`

Claim confirmed. Grepped every write to `vehicle_id` on the conduction side:

- `ConductionRequestView.vue:502` — `emptyCreateForm()` initializes it `null`.
- `ConductionRequestView.vue:570` — `form.vehicle_id = booking.vehicle_id ?? null`, inside `openCreate(booking)`, **only reached when `booking` is non-null**, i.e. only via `handleDispatchBooking` (line 581-584), which is only ever called from the `dispatch-booking` emit found in item 2.
- No `v-select`/`v-autocomplete` bound to `vehicle_id` exists anywhere in `ConductionRequestView.vue`'s create dialog template (lines 169-263) — only free-text `vehicle` (line 218) and `plate_no` (line 221).
- Backend: `ConductionRequestController::store()` line 60 accepts `vehicle_id` as `nullable|integer|exists:tbl_vehicles,vehicle_id` with **no other constraint** — it will happily store any vehicle ID sent to it, including one already on an open trip. There is no `AmbulanceAvailability`-style check on this endpoint at all.

Confirmed: the only path that ever populates `vehicle_id` on a conduction
request is the pre-fill from a `Booked`+assigned service request. A
standalone dispatch (scenario 3) always has `vehicle_id = null` unless
staff types a matching vehicle name into the free-text `vehicle` field,
which the backend does not cross-reference against `tbl_vehicles` at all.

---

## 5. Remarks columns — this is a live data leak, not just mislabeled copy

Upgrading the original finding's severity based on what the code actually does.

- `tbl_service_request.remarks` is a single column, `#[Fillable]`-listed on
  `App\Models\ServiceRequest` (`ServiceRequest.php:16`).
- `ServiceRequestController::update()` (line 792) validates it as
  `nullable|string|max:160|required_if:status,Disapproved` — the same
  column, same validation rule, regardless of whether the caller is
  filling in an internal note or a resident-facing rejection reason.
- `ServiceRequestController::adminIndex()` (line 66-70) returns the full
  `ServiceRequest` model as JSON with no field-level filtering — no
  `Resource` class hides or renames `remarks`. Confirmed by grep: no
  `ServiceRequestResource` exists in `app/Http/Resources`.
- **The Flutter app reads it directly and unconditionally:**
  `Mobile/lib/models/request_models.dart:665`:
  ```dart
  final note = json['remarks'] as String?;
  ```
  This runs in `ServiceRequest.fromJson`, with no status gate — whatever is
  in `remarks` becomes the resident-visible `note` field for any request,
  in any status, the next time the app polls `GET /api/service-requests`.

**Conclusion: finding 4 is not a copy problem, it is a real data leak.**
Whatever an admin types into "Admin remarks" (`formData.remarks`,
`ServiceRequestQueue.vue:1073`) is pre-filled directly into the Approve &
Dispatch popup's "Note for the Head of the Family" field
(`ServiceRequestQueue.vue:1190`), and once saved via `PATCH .../{id}` with
`status: 'Responding'`, it reaches the resident's phone on the very next
poll — no SMS, no push notification needed for this to happen, just the
app's normal refresh. `notifyResident()` (line 660) is irrelevant to this
path entirely; it only fires on `approve()`, `reschedule()`, and
`update()`'s `Disapproved` branch. The instant-path `Responding` transition
never calls it, but doesn't need to — the leak happens through the regular
GET response, silently.

---

## 6. Blast radius

**Unable to verify from this environment — no database connection
available to the coding agent.** This machine cannot reach the Aiven
MySQL instance directly (see `serbis-environment` notes on running the
stack locally). Run this on the box that can reach the database, or
against the most recent dump:

```sql
SELECT DATE_FORMAT(sr.created_at, '%Y-%m') AS month, COUNT(*) AS orphaned
FROM tbl_service_request sr
JOIN tbl_services s ON s.service_id = sr.service_id
WHERE s.code = 'ambulance-medical-response'
  AND sr.status IN ('Resolved', 'Cancelled')
  AND NOT EXISTS (
    SELECT 1 FROM tbl_conduction_requests cr
    WHERE cr.service_request_id = sr.request_id
  )
GROUP BY month
ORDER BY month;
```

Also worth a second query, since `Cancelled` requests never had a trip and
shouldn't count as "lost" records — the number that actually matters is
`Resolved` only:

```sql
SELECT COUNT(*) FROM tbl_service_request sr
JOIN tbl_services s ON s.service_id = sr.service_id
WHERE s.code = 'ambulance-medical-response'
  AND sr.status = 'Resolved'
  AND NOT EXISTS (
    SELECT 1 FROM tbl_conduction_requests cr
    WHERE cr.service_request_id = sr.request_id
  );
```

Run both before C5; the second number is what C8's regression assertion
should be checked against going forward (it must stay 0 for anything
created after C5 ships).

---

## 7. Required columns on `tbl_conduction_requests`

From `2026_08_18_100000_create_tbl_conduction_requests_table.php`, cross-checked
against `ConductionRequestController::store()`'s validation rules — the two
agree exactly, so there is no column that's DB-required but form-optional
or vice versa:

**NOT NULL at the DB level** (and `required` in `store()`'s validation):
- `patient_name`
- `patient_address`
- `patient_contact_number`
- `medical_diagnosis`
- `origin`
- `destination`

**Nullable at the DB level** (all `nullable` in validation too):
- `patient_age`, `patient_sex`, `vehicle`, `plate_no`
- All four trip-log checkpoints: `departed_office_at`, `arrived_destination_at`, `departed_destination_at`, `returned_office_at`
- `odometer_start`, `odometer_end`, `others`
- `service_request_id`, `vehicle_id` (added later, `2026_08_29_140000`)

**This directly answers Open Decision 2 in the plan.** The six NOT NULL
patient/trip fields are exactly what C3's structured columns need to supply
at Approve & Dispatch time for C5's stub-creation to succeed without a
migration change — the stub can be created immediately since none of the
genuinely trip-time fields (checkpoints, odometer) are required at the DB
level. The minimal hard-block set for **resolution** (not creation)
suggested in the plan — arrival timestamp, odometer in/out, driver — is
achievable without any schema change, since those columns already exist
and are already nullable; C5 only needs an application-level check, not a
migration.

---

## Also found, not asked for, worth carrying into planning

- **`ServiceRequestController::cancel()` (~line 557) already checks
  `conductionRequests()->whereNotNull('departed_office_at')`** before
  letting a resident cancel — so a `conductionRequests()` relation already
  exists on the `ServiceRequest` model, and the backend already partially
  reasons about "has this actually been dispatched" independently of
  `tbl_service_request.status`. C5 and C6 should reuse this relation rather
  than re-deriving it.
- **`store()` and `walkIn()` are near-duplicate methods** (lines ~140-209
  and ~330-429) with one line of real difference (resident vs. walk-in
  fields). Not in scope for this plan, but adjacent — worth a note for
  whoever eventually does the `code-quality-metrics-standards.md`
  deduplication pass, since C3/C4/C5 will be editing both of these methods
  anyway and touching them twice a second time later is avoidable now.
- **The `required_vehicle_type` auto-claim path is unreachable for
  ambulance** (item 1). Not a bug to fix under this plan — just recorded so
  nobody assumes an ambulance walk-in can already auto-claim a vehicle at
  creation. It cannot, by construction of the Vue form.

---

## Stop here

Per the plan's own rule: no code changes have been made in this phase.
Report reviewed before C1 begins.
