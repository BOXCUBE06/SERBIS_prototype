# MDRRMO client feedback — codebase audit

Audit only. Every item below was checked against the current `update-admin-vue` tree (no code changed by this audit). "Done" means the behaviour described is already live; "Partial" means part of it exists but something concrete is missing; "Not implemented" means nothing exists; "Unclear" means the feedback note doesn't map to anything identifiable in the system.

---

## A. RBAC and request assignment

### A1. Multiple admin accounts can handle the same request.
- **Status:** Not implemented.
- **Location:** `tbl_service_request.processed_by` (`Backend/SERBIS-Backend/database/migrations/2026_06_05_071926_create_tbl_service_request_table.php:18`) is a single nullable FK to `tbl_user.admin_id` — one value, not a set. `ServiceRequest::processedBy()` (`app/Models/ServiceRequest.php:188`) reflects the same 1:1 shape.
- **Scope:** Schema change (a many-to-many pivot table, e.g. `tbl_service_request_admins`, would be needed to let more than one admin be attached to one request at a time) + API + UI.
- **Blockers:** What "handling" means concurrently — do multiple admins see and act on the same request independently, or is one primary handler plus observers? See open question.

### A2. One admin can assign another admin to a request they are handling.
- **Status:** Partial (backend primitive exists, unused for this purpose; no UI).
- **Location:** `ServiceRequestController::update()` accepts `processed_by` as a plain client-supplied field (`app/Http/Controllers/ServiceRequestController.php:1161`, `'processed_by' => 'nullable|integer|exists:tbl_user,admin_id'`) and writes it through `Arr::except($validated, self::BOOKING_FIELDS)` (`:1336`) with no check that it equals the caller — technically any admin could already set it to any other admin's id via a raw API call. Every actual write site sets it to `$request->user()->getKey()` (self-assignment only), e.g. the ambulance approve path at `:1538`. No admin-picker exists anywhere in the admin panel — the only "Assign" UI in `ServiceRequestQueue.vue` (`openAssignUnitModal`, line 719/1792) assigns a **vehicle**, never a person.
- **Scope:** UI (an admin picker on the request detail panel) + a small API change (stop overwriting `processed_by` with the caller's own id on every write, or add a dedicated assign endpoint).
- **Blockers:** Same RBAC gap as A1 — see below.

**Audit of admin roles/permissions:** `tbl_user.role` is a bare, unconstrained varchar. `User::isAdmin()` (`app/Models/User.php:57`) does a case-insensitive check against the literal string `'admin'` and nothing else — there is no second role value anywhere in the schema or seeders. `AdminController` hardcodes `'role' => 'Admin'` on every staff account it creates (`app/Http/Controllers/AdminController.php:66`), and `StaffView.vue`'s create/edit form (`Web/serbis-admin-vue/src/views/StaffView.vue:145-165`) has no role field at all. **Every admin account in this system is identical and unrestricted.** There is no concept of a dispatcher, supervisor, or any tier below full admin. This is the root fact behind A1, A2, and G1.

---

## B. Request form

### B1. "Others" option in the services list.
- **Status:** Not implemented.
- **Location:** Seeded services (`Backend/SERBIS-Backend/database/seeders/ServiceSeeder.php:48-54`): Ambulance/Medical Response, Relief Goods Distribution, Road Clearing, Power Line Repair, Debris Removal, Animal Rescue, Sandbagging — exactly seven, no "Other". The mobile services grid (`Mobile/lib/screens/services_screen.dart`) renders whatever `/services` returns; there is no catch-all tile. The pattern already exists one level down: two sub-forms have their own `'Other'` choice inside a fixed list (`Mobile/lib/models/service_forms.dart:354-367`, `kObstructionTypes` / `kAssistanceTypes`), which is the template B1 would follow.
- **Scope:** UI only if "Others" is just a free-text request with no dedicated `service_id` (reuse the existing `description` field the way a walk-in request already does); schema/API change only if it needs to be a real row in `tbl_services` that the admin panel can filter and report on separately.
- **Blockers:** See open question — what should an "Others" request actually create on the backend.

### B2. Vehicle selection shows only vehicles applicable to the request type.
- **Status:** Partial — enforced in the admin UI for ambulance vs. everything else, not enforced server-side, and not granular for the other six service types.
- **Location:** `ServiceRequestQueue.vue:1814-1816` — `availableVehicles` filters `v.type === 'Ambulance'` when the request is an ambulance request, else `v.type !== 'Ambulance'` (every non-ambulance vehicle, undifferentiated). **Confirmed:** `ConductionRequestController::store()` validates `'vehicle_id' => 'nullable|integer|exists:tbl_vehicles,vehicle_id'` (`app/Http/Controllers/ConductionRequestController.php:108`) — no type check at all. The same is true of `ServiceRequestController::update()`'s `vehicle_id` rule (`:1171`). A client that bypasses the UI (or a bug in it) can attach any vehicle to any request today. Empirically (local dev DB, confirmed this session): only Ambulance-type vehicles have ever had a conduction-request trip — Boat/Fire Truck/Rescue Vehicle all show zero, because conduction requests only ever originate from the one ambulance-dispatch service.
- **Scope:** API (server-side type validation, matched against the request's service) + UI (per-service vehicle type mapping instead of the current ambulance/non-ambulance binary).
- **Blockers:** MDRRMO needs to supply the actual service→vehicle-type mapping for the six non-ambulance services (e.g. does Road Clearing need a dump truck specifically, or any non-ambulance unit?).

### B3. Household size field needs placeholder / help text with a question-mark tooltip.
- **Status:** Done.
- **Location:** `Mobile/lib/models/service_forms.dart:427-436` — the Relief Goods form's `household_size` field already carries `hint: 'e.g. 5'` and `helpText: 'Count everyone who regularly eats and sleeps in this household, including yourself.'` (a close paraphrase of the requested copy). The tooltip mechanism itself — a tappable "?" icon next to the label — is a generic, already-built widget feature: `Mobile/lib/widgets/form_inputs.dart:37-40, 94-112` (`AppTextField.helpText`, rendered as `Icons.help_outline_rounded` inside a tap-triggered `Tooltip`).
- **Scope:** None — already shipped.
- **Blockers:** None.

### B4. Landmark textbox needs a label.
- **Status:** Done.
- **Location:** `Mobile/lib/screens/services_screen.dart:445-451` — the landmark `TextFormField` already has `labelText: 'Landmark (optional)'` and a hint (`'e.g. beside the chapel, near the covered court'`).
- **Scope:** None — already shipped.
- **Blockers:** None. (If this feedback was written against an older build, it's stale; worth confirming with MDRRMO which screen/version they saw.)

---

## C. Notifications

### C1. Approval notification must reach the device when the app is closed.
- **Status:** Partial — the mechanism is correct and standard, but wired to only one of seven services, and closed-app delivery has never been verified on a real device.
- **Location:** `Backend/SERBIS-Backend/app/Services/Fcm.php` sends a real FCM **notification message** (`'notification' => ['title'=>.., 'body'=>..]`, `Fcm.php:99-105`) via HTTP v1 — this is the shape Android's system tray displays automatically even when the app is fully closed/terminated, without any extra background-handler code needed. Device-token registration is real and works (`Mobile/lib/state/push_messaging.dart`, `DeviceTokenController.php`). **But** `notifyResidentDevices()` (`ServiceRequestController.php:1114-1123`) is called from exactly three places, and all three are ambulance-booking-only: `approve()` (`:1550`), `reschedule()` (`:1632`), and a rejection path explicitly gated on `$wasBookingRejection` (`:1327`, comment: "the panel's older, unscheduled Pending -> Disapproved flow is not 'a booking' and stays silent"). **The other six services — Relief Goods, Road Clearing, Power Line Repair, Debris Removal, Animal Rescue, Sandbagging — never trigger a push on any status change, including approval.** There is also no notifier icon badge (app-icon unread count) anywhere — no `flutter_local_notifications`, no badge package in `Mobile/pubspec.yaml`, no badge-setting code in the Flutter app at all.
- **Scope:** API (call `notifyResidentDevices()` from the general non-ambulance approval path in `update()`, not just the ambulance-specific methods) + a small mobile addition for the badge (a package like `flutter_app_badger` plus a server-computed or client-computed unread count).
- **Blockers:** None to start the fix (the path already exists); closed-app delivery should be verified on a real device once before calling this done — it has not been in this repo's history as far as memory/tests show.

---

## D. Equipment borrowing

### D1. "Others" option when borrowing equipment.
- **Status:** Done.
- **Location:** `tbl_equipment_borrowing.other_equipment_text` (migration `2026_09_0*_add_other_equipment_text*.php`), validated `required_without:equipment_id|...|prohibits:equipment_id` (`EquipmentBorrowingController.php:137-138`). Mobile has a dedicated `_OtherEquipmentCard` / "Request another item" flow (`Mobile/lib/screens/borrow_equipment_screen.dart:153,168,315,841`) that submits exactly this field. Admin panel shows it (`EquipmentBorrowingController.php:369`).
- **Scope:** None — already shipped, and already serves procurement input (the office sees the free-text item name in the borrowing record).
- **Blockers:** None.

### D2. Policy: borrow period limited to 1–7 days.
- **Status:** Partial — the 7-day maximum is enforced; the 1-day minimum is not (a same-day, 0-day loan is currently accepted).
- **Location:** `EquipmentBorrowingController.php:28` (`MAX_LOAN_DAYS = 7`), validation at `:323-327` — `due_date` must be `after_or_equal` today and `before_or_equal` today+7. The lower bound is "today," not "tomorrow," so nothing currently stops a due date of today.
- **Scope:** API only — tighten `after_or_equal` to tomorrow, or add an explicit `after:today` rule.
- **Blockers:** Confirm whether a same-day loan should be rejected outright, since the current lower bound was apparently chosen deliberately (comment at `:301-304` explains the Manila-timezone reasoning for the day boundary, but not why same-day itself is allowed).

### D3. Due-date countdown in a larger box, all request types, notify 1 day before, admin + mobile.
- **Status:** Partial — the countdown text and the 1-day-before reminder both already exist, but the countdown isn't a prominent "larger box" anywhere, the reminder is SMS-only (no push), and both are scoped to equipment borrowing, not "all request types."
- **Location:** Display — `EquipmentBorrowingView.vue:1171-1178` (`dueLabel()`, producing exactly "Due in N days" / "Due today" / "Due tomorrow" / "N days overdue") rendered as a plain table-row line (`:266-269`) and in the detail panel (`:645-649`), not a distinct large box. Mobile has the identical label rendered in `Mobile/lib/screens/borrow_equipment_screen.dart:435-442`. Notification — `Backend/SERBIS-Backend/app/Console/Commands/SendReturnDueReminders.php` already runs on a schedule, finds every `Released` borrowing due today or tomorrow (`:48-52`), and texts the resident via PhilSMS — this is the "1 day before" window the feedback asks for, just SMS rather than push, and not cross-wired to `Fcm::sendToDevice()` at all.
- **Scope:** UI (restyle the countdown into a prominent box on both platforms) + API (add a push send alongside — or instead of — the SMS in `SendReturnDueReminders`).
- **Blockers:** Confirm "all request types" — does the countdown/reminder need to extend to ambulance's `scheduled_at` and other non-borrowing dates, or is this D-group item scoped to equipment only (the numbering suggests the latter, but the literal wording says "all request types").

### D4. Required return condition comment, optional photo, used to gate future borrowing.
- **Status:** Partial — both fields exist and work; the comment is optional (not required as asked), and nothing uses it to restrict future borrowing.
- **Location:** `tbl_equipment_borrowing.return_condition_note` (varchar 500) and `.return_photo_path` both exist (migrations `2026_09_*`). Validation is explicitly optional: `'return_condition_note' => 'sometimes|nullable|string|max:500'` (`EquipmentBorrowingController.php:331`), with a comment stating this was a deliberate choice ("Optional, alongside the return photo"). No code anywhere (`canBorrow`, eligibility check, or similar — searched, none found) reads condition history to block or flag a resident's next borrow request.
- **Scope:** API (make the note required on the Returned transition; add an eligibility check somewhere in `EquipmentBorrowingController::store()`) + UI (surface the block/warning to the resident and to staff reviewing a new request).
- **Blockers:** What "may not borrow again" means operationally — an automatic hard block, a flag for staff to see and decide on, and after how many bad returns. This is a policy decision, not just a build task.

---

## E. Ambulance booking

### E1. Patient/borrower same as registered user → autofill.
- **Status:** Partial, and the gap is a deliberate design choice, not an oversight.
- **Location:** `Mobile/lib/models/service_forms.dart:146-149` already prefills `patientAddress` (from the account's barangay) and `patientContact` (from the account's phone number) — both stay editable. The patient **name** is explicitly left blank always, with a comment explaining why (`:142-145`): "The account holder is the likeliest patient, not the certain one — a head of the family files for the household — and a name already sitting in the field is a default nobody chose, submitted unchecked."
- **Scope:** UI only (a toggle/checkbox, defaulting off, that also fills the name field when the requester confirms the patient is themselves).
- **Blockers:** This would reverse an intentional prior decision. Confirm with MDRRMO whether the risk the current design avoids (a wrong name submitted unchecked) is acceptable given the toggle requires an explicit confirm click.

### E2. Remove the gender field.
- **Status:** Not implemented (the field exists and needs removing, not adding).
- **Location:** `patient_sex` — mobile dropdown `Mobile/lib/models/service_forms.dart:160-161` (`sexOptions = ['Not specified', 'Male', 'Female']`), sent as `patient_sex` (`:128`), stored via backend validation `'patient_sex' => 'sometimes|nullable|in:male,female'` (`ServiceRequestController.php:1192`), shown in the description composer and the mobile meta line (`:236`).
- **Scope:** UI (drop the dropdown and the meta line) + optionally schema (drop `patient_sex` from `tbl_service_request`, or just stop writing to it and leave historical data alone).
- **Blockers:** None — straightforward removal once confirmed.

### E3. Purok becomes a dropdown sourced from a location API, with a default.
- **Status:** Not implemented — no purok concept exists anywhere in the schema.
- **Location:** `patientAddress` is a plain free-text field, prefilled only with the barangay name; the code comment is explicit: "`tbl_residents` has a `barangay_id` and no street column, so the resident is expected to add the purok themselves" (`Mobile/lib/models/service_forms.dart:166-169`). There is no `tbl_purok` or equivalent table anywhere in `database/migrations/`; every "Purok" string in the codebase is sample data inside test fixtures (e.g. `'Purok 1'` in `WalkInAmbulanceIntakeTest.php`), not a real lookup.
- **Scope:** Schema (a new purok table/column, keyed under barangay) + API (a new lookup endpoint) + UI (mobile dropdown, and the admin side wherever addresses are shown/edited).
- **Blockers:** MDRRMO needs to supply the actual purok list per barangay — there is no existing "location API" in this system to source it from; one would be built from scratch, from data MDRRMO provides.

### E4. Companion/relative optional in app, required at hospital, max 1–2.
- **Status:** Partial — the optional-relatives mechanism exists and already enforces a cap, just not the right number.
- **Location:** Backend `MAX_RELATIVES = 20` (`ServiceRequestController.php:69`), validated as `'patient_relatives' => 'nullable|array|max:'.self::MAX_RELATIVES` at both `store()` (`:269`) and `adminStore()` (`:706`). Mobile's repeater (`AmbulanceFormData.relatives`, `service_forms.dart:191, 210-223`) has **no client-side cap at all** — `addRelative()` appends unconditionally.
- **Scope:** UI only (cap `addRelative()` at 1–2, disable the "Add relative" button past the limit) + a one-line API change (`MAX_RELATIVES` from 20 to 2).
- **Blockers:** Confirm whether "1–2 ... with the driver" means 1–2 total companions, or 1–2 in addition to some other implicit passenger — read literally, cap the relatives list at 2.

---

## F. Response wording (voice of the customer)

### F1. Remove prohibitive "not allowed" wording; use the softer template; add a confirmation step.
- **Status:** Not implemented, with one literal match to the exact phrase MDRRMO is complaining about.
- **Location — every canned/refusal string found:**
  - **`Mobile/lib/state/api_service.dart:340`** — `if (status == 403) return 'You are not allowed to do that.';` — the generic HTTP-403 error mapper, shown to a resident for any authorization failure. This is the literal "not allowed" wording.
  - **`Backend/.../ServiceRequestController.php:463` and `:869`** — `'No available vehicles at this time.'` (422, ambulance booking submission — blunt unavailability, no follow-up offered).
  - **`Backend/.../EquipmentBorrowingController.php:385`** — `'Not enough equipment available to release.'` (422).
  - **`Backend/.../ServiceRequestController.php:1528`** — `'That unit is no longer free for this window.'` (staff-facing only, admin re-approval flow — lower priority since residents never see it).
  - By contrast, `approvalPushBody()` / `rejectionPushBody()` / `reschedulePushBody()` (`ServiceRequestController.php:1131-1148`) are already neutral, factual, and not prohibitive — a good existing template to extend from.
  - None of the above offer a "we'll notify you when available" callback or a confirmation step asking whether the request is still needed — that mechanic doesn't exist anywhere in the codebase.
- **Scope:** UI/API string changes only for the wording itself; a small new workflow (a follow-up notification + a "still needed?" confirmation, presumably via the existing push/SMS channels) for the second half of F1.
- **Blockers:** Exact copy to use — the feedback gives a template ("We wish to comply but...", "We will come to you once...") that should be confirmed word-for-word with MDRRMO before it ships in a resident-facing string.

### F2. Pickup or delivery option selected at request time.
- **Status:** Done for equipment borrowing; not present for other service types.
- **Location:** `tbl_equipment_borrowing.fulfillment_method` (enum `Pickup`/`Delivery`, migration `2026_09_07_130000_add_fulfillment_to_tbl_equipment_borrowing.php`) plus `delivery_address`, both wired through `EquipmentBorrowingController::store()` (`:202,221`) and the mobile borrow form.
- **Scope:** None for equipment borrowing. If MDRRMO wants this on other service requests too (e.g. Relief Goods), that needs a new column on `tbl_service_request` — it doesn't have an equivalent field today.
- **Blockers:** Confirm whether F2 is asking for equipment borrowing specifically (already done) or every service type (new schema work).

---

## G. Text blast

### G1. Only two authorized personnel may send text blasts.
- **Status:** Not implemented.
- **Location:** `routes/api.php:149` — `Route::post('/sms/blast', ...)->middleware('throttle:sms-blast')` — rate-limited (3/hour per admin), but no role or identity restriction. Because of the flat-RBAC finding under A, **any** admin account can send a blast today.
- **Scope:** API (route middleware or a controller-level check) — but this is blocked on the same underlying gap as A1/A2/G1: the system has no way to distinguish "Operations Officer" from any other admin. Needs at minimum a role/flag on `tbl_user`, or a hardcoded allowlist of admin_ids as an interim measure.
- **Blockers:** The name of the second authorized person is unclear in MDRRMO's notes per the task description — flagging this explicitly rather than guessing.

### G2. Status tracking: successful / pending.
- **Status:** Done, with different vocabulary than "pending."
- **Location:** `SmsLog.status` is one of `Sent` / `Failed` / `Unconfirmed` (`SmsController::recordBlast()`, `:456-484`), shown in the admin SMS History tab (`:361-408`) and the resident-facing advisory feed (`:321-344`, which withholds only `Failed`). `Unconfirmed` — used when PhilSMS times out without confirming delivery (`:59-93`) — is the closest existing analog to "pending": it means the send probably went out but wasn't confirmed, not that it's queued.
- **Scope:** None if "Unconfirmed" satisfies the intent; a one-word label change if MDRRMO specifically wants to see "Pending" in the UI.
- **Blockers:** None — confirm the vocabulary works for MDRRMO, since there's no truly async/queued state in this system (a blast is sent synchronously).

### G3. Character limit check before sending.
- **Status:** Done, more thoroughly than asked.
- **Location:** `Web/serbis-admin-vue/src/views/SmsView.vue:134` (`v.length <= 160` client-side rule) plus a full GSM-7/UCS-2 segment-cost analyzer (`composables/smsSegments.ts`, surfaced at `:182-197`) that flags the exact character that would push a message out of the 160-character single-segment budget and into a costlier encoding.
- **Scope:** None — already shipped.
- **Blockers:** None.

### G4. Password re-entry required before sending.
- **Status:** Done.
- **Location:** Backend: `SmsController::assertCurrentPassword()` (`:251-291`), rate-limited at 5 attempts/15 minutes, checked before any send. Frontend: the confirm dialog collects a password field (`SmsView.vue:243-251`) and posts it with the send request (`:624`).
- **Scope:** None — already shipped.
- **Blockers:** None.

### G5. What "codes intended for admins or authorized personnel" refers to.
- **Status:** Unclear — nothing in the current system matches this description.
- **Location searched:** The only "code" concepts anywhere in the backend are `Totp.php`, `ResidentVerificationCode.php`, and the OTP flow in `AuthController.php` — all of these are **resident-facing** registration/login verification codes (SMS OTP with email fallback, per prior product decisions), not an admin authorization code of any kind. No invite code, access code, or admin-only code exists.
- **Scope:** N/A until clarified.
- **Blockers:** Flagging as unclear per the task instructions — ask MDRRMO directly what this note referred to; it may be a mix-up with the resident OTP feature.

---

## H. Offline safety materials

### H1. Content certification verified by an expert, shown to establish legitimacy.
- **Status:** Partial — the legitimacy signal is fully built and shown to users; the "verified by an expert" attribution is not.
- **Location:** `tbl_info_materials.verified` (boolean, default false, migration `2026_09_07_120000_add_verified_to_tbl_info_materials.php`, comment: "Whether MDRRMO has checked this material and stands behind it"). Admin panel has a toggle switch (`FilesView.vue:210-220`). Mobile shows a green "Verified" / "Beripikado" badge with a checkmark icon **only when true** — nothing is shown for unverified materials (`Mobile/lib/screens/library_screen.dart:405-461`). There is no distinction of *who* verified it beyond `uploader_id` (who uploaded, not who reviewed) — any admin can flip the switch, and because of the flat-RBAC gap under A, the system cannot currently represent "verified specifically by emergency personnel" as distinct from "verified by whoever happened to be logged in."
- **Scope:** Schema (add `verified_by` FK to `tbl_user`, and optionally a credential/title text field) + API + UI (show the verifier's name/role on the badge).
- **Blockers:** Same RBAC gap as A/G1 — capturing "an expert" as a fact requires the system to know which admins qualify as one.

---

## I. Account types and service filtering

### I1. Organizational accounts (a representative registering on behalf of an office/barangay/LGU/council).
- **Status:** Not implemented as a real account type; a related pattern exists one level down.
- **Location:** Only two account families exist today: `tbl_user` (admin/staff, single flat role) and `tbl_residents` (individual residents). The closest precedent is `tbl_equipment_borrowing.borrower_type` (`Resident`/`Organization`) plus `organization_name` (migration `2026_09_08_100000_add_borrower_type_to_tbl_equipment_borrowing.php`), but this is a **flag on a single borrow request**, filed by an ordinary resident account declaring "I'm borrowing on behalf of a group" — explicitly not a distinct login (comment: "the account behind the request is a person, and their name is not the group's," `EquipmentBorrowingController.php:162-164`). No organization ever registers its own account.
- **Scope:** Schema (a new account type, either a parallel table like `tbl_organizations` or a discriminator column on a shared accounts table) + API (registration, auth) + UI (both mobile registration and admin review/approval of organization accounts).
- **Blockers:** What proves an organization representative is legitimate (a document upload? an admin approval step? a list of pre-registered organizations MDRRMO maintains?), and which of ISU/other schools/PNP/BFP/barangay/LGU get their own account vs. share one "Organization" type.

### I2. Services filtered by audience — general public vs. organizational.
- **Status:** Not implemented.
- **Location:** `tbl_services` (migration `create_tbl_services_table.php`) has exactly `service_id`, `service_name`, `description`, timestamps — no audience, visibility, or scope column of any kind. Every resident sees all seven services identically.
- **Scope:** Schema (an `audience` column or a pivot table if a service can serve more than one audience) + API (filter `/services` by the caller's account type) + UI (both mobile service list and admin's Manage Services page).
- **Blockers:** Depends entirely on I1 shipping first — there's no organizational account to filter *for* yet. Also needs the actual audience mapping per service from MDRRMO.

### I3. Relief goods: household → barangay validates → barangay requests MDRRMO.
- **Status:** Not implemented — this is a new multi-tier workflow, not a variant of the existing one.
- **Location:** Today, Relief Goods Distribution is one entry in the flat services list, filed directly resident → MDRRMO with no intermediary (same `ServiceRequest`/`AnalyticsReport::scoped()` pipeline as every other service). `Barangay` (`app/Models/Barangay.php`) is a pure lookup table — `barangay_name` only, no login, no staff, no actor capability of any kind; it exists purely to tag a resident's address and to group analytics.
- **Scope:** This is the largest item in the whole list. It would touch: a new barangay-actor account type (schema + auth, likely overlapping with I1's organizational-account work since a barangay office is itself an organization); a new intermediate approval state between "resident submitted" and "MDRRMO received" (either a new status on `ServiceRequest` or a new linking table); new admin-panel views for barangay-level review; a new notification chain (resident → barangay, barangay → MDRRMO, and back); and reworking the existing Relief Goods request flow so it routes through this chain instead of straight to MDRRMO.
- **Blockers:** Needs MDRRMO to describe the actual paper process today (who currently validates a relief-goods request at the barangay level, and how, before any of this can be scoped) — this cannot be estimated further without that.

---

## Open questions for MDRRMO

The original nine-question list (`docs/mobile-improvement-tasks.md`, written 2026-08-06, commit `e0c6bc0c`) **no longer exists in the repository** — it was removed at some point after that commit and is not recoverable from any branch (`main`, `update-admin-vue`, `documentation`, or the others) at HEAD. The Obsidian vault that might hold a copy (`obsidian-vault/`, gitignored, synced via the separate `serbis-brain` repo) is not present on this machine to check. What could be reconstructed from session memory is merged in below (items 1–4); everything from item 5 on is new, from this audit.

1. **`sms_opt_in` is all-or-nothing.** A resident who opts out of SMS also stops receiving evacuation warnings, because the system has no notion of an emergency tier versus a routine one. Is that acceptable, or does an emergency-tier blast need to bypass opt-out?
2. **Should a "Keep me signed in on this device" option exist on login**, or is requiring sign-in every session the intended behaviour?
3. **The mobile app's Tagalog copy has never had a native-speaker review pass.** Should that review happen before the next release, and who signs off on it?
4. **Are the emergency hotline numbers hardcoded in the mobile app (M28) still the current, correct numbers?** They were never confirmed against an official source.
5. **A1/A2 — is real RBAC wanted** (named roles like dispatcher/supervisor with different permissions), or just an "assigned to" label with no permission difference between admins?
6. **B1 — what should an "Others" service request actually create on the backend?** A free-text note on an existing request (no schema change), or a real trackable service category?
7. **B2 — what is the actual vehicle-type requirement per non-ambulance service?** (e.g. does Road Clearing specifically need a truck, or can it use any non-ambulance unit?)
8. **C1 — should push notifications extend to all seven services, not just ambulance bookings?** And is an app-icon badge count wanted in addition to the tray notification?
9. **D2 — should a same-day (0-day) equipment loan be rejected outright,** or is same-day pickup-and-return a real use case worth keeping?
10. **D3 — does the due-date countdown/reminder need to cover every request type** (e.g. ambulance's scheduled time), or is it scoped to equipment borrowing only?
11. **D4 — what should happen to a resident with a bad-condition return on their record?** An automatic block on their next borrow request, a flag for staff to decide on manually, or something else — and after how many incidents?
12. **E1 — is the "same as registered user" autofill toggle wanted even though it reverses a deliberate prior decision** (never auto-filling the patient name, because the account holder files for the whole household)?
13. **E2 — should `patient_sex` be dropped from the database entirely, or just hidden from the form** (keeping historical data)?
14. **E3 — is there an official purok list per barangay MDRRMO can supply?** There is currently no "location API" with purok-level detail to build against — one would be built from scratch from data MDRRMO provides.
15. **F1 — confirm the exact resident-facing wording** for the "not available" and "we'll come to you" messages, and whether the "still needed?" confirmation should arrive by push, SMS, or an in-app prompt.
16. **F2 — is pickup/delivery selection wanted on service types beyond equipment borrowing** (where it already exists)?
17. **G1 — who is the second person (besides the Operations Officer) authorized to send text blasts?** The name is unclear in the source notes.
18. **G5 — what does "codes intended for admins or authorized personnel" refer to?** Nothing in the current system matches this description; the only code-based flow that exists is resident OTP registration/login, which is a different feature.
19. **H1 — who counts as a qualifying "expert" reviewer for a safety material,** and should the system capture and display their name/credential, or is a simple "MDRRMO verified" mark (today's behaviour) sufficient?
20. **I1 — how does MDRRMO want to verify an organization representative is legitimate** before granting an organizational account — a document upload, a pre-approved list MDRRMO maintains, or an admin approval step?
21. **I2 — which services should be organizational-only, which shared, and which public-only?** A mapping is needed before this can be scoped.
22. **I3 — what is the actual paper/manual process today for a barangay validating a relief-goods request before it reaches MDRRMO?** This is needed before the workflow can be designed, not just built.

---

## Schema impact summary

Every item that would require a database migration if built as literally requested (an item not listed here needs UI/API work only):

| Item | What would need to change | Notes |
|---|---|---|
| A1 | New pivot table (e.g. `tbl_service_request_admins`) | Only if true concurrent multi-admin handling is wanted; A2 alone needs no schema change (`processed_by` already exists) |
| E3 | New purok table/column, keyed under barangay | No purok-level location data exists anywhere today |
| F2 (if extended) | New `fulfillment_method`-equivalent column on `tbl_service_request` | Already exists on `tbl_equipment_borrowing`; only new if extended to other service types |
| G1 / A2 / H1 | Real role/permission column or table on `tbl_user` (replacing the flat, unconstrained varchar `role`) | One underlying gap behind three separate feedback items |
| H1 (attribution) | `verified_by` FK (+ optional credential text) on `tbl_info_materials` | The boolean `verified` flag already exists; this adds *who* |
| I1 | New account type — either a parallel table (`tbl_organizations`) or a discriminator column shared with an existing accounts table | Largest single addition on this list besides I3 |
| I2 | `audience` column (or pivot) on `tbl_services` | Depends on I1 shipping first |
| I3 | New barangay-actor account/auth, a new approval-chain state on `ServiceRequest` (or a parallel table), likely overlapping I1's organizational-account schema | The single largest item audited — a genuinely new workflow, not a variant of the existing one |
| D4 (optional) | A ban/flag column on `tbl_residents`, only if MDRRMO wants an explicit persisted restriction rather than a computed check | Depends on the answer to open question 11 |
| F1 (confirmation step, optional) | A `still_needed_confirmed_at`-style column on `tbl_service_request`, only if the confirmation step needs to persist state | Depends on the answer to open question 15 |
