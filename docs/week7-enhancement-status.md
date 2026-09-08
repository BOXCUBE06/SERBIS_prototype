# Week 7 enhancement status

All sixteen Form 1 items, audited against the tree at `e33bd94` on branch
`update-admin-vue`, 2026-09-08.

**Source of the item list.** `docs-paper/WEEK 7-1.docx` is **not in this repository** —
not in the working tree, not in any branch, and not among the 17,638 objects in the git
object database. `docs-paper/` holds Weeks 1, 2, 3, 4 and 5 plus `Manuscript_improved.docx`,
and none of those six contains any Form 1 phrasing. The wording below was supplied
directly and has **not** been checked against the document. Anywhere the exact wording
decides the verdict, that is flagged in the row.

**How to read a verdict.**

- **Done** — every layer that needs it has it, and the wording is satisfied.
- **Partial** — it works somewhere but not everywhere, or it works but not as worded.
- **Absent** — nothing in the tree implements it.
- **N/A** — that layer genuinely has no part to play, with the reason given.

Layers are `Backend/SERBIS-Backend`, `Web/serbis-admin-vue`, `Mobile/lib`.

---

## Summary

| # | Item | Backend | Admin | Mobile | Verdict |
| --- | --- | --- | --- | --- | --- |
| 1 | Pickup or delivery option | Done | Done | Done | **Done** |
| 2 | Display available quantity per item | Done | Done | Done | **Done** |
| 3 | Notify borrower one day before due date | Absent | Absent | Absent | **Absent** |
| 4 | Photos before release and on return | Done | Done | Absent | **Partial** |
| 5 | Multi-role: admin, staff, resident, barangay | Partial | Partial | N/A | **Partial** |
| 6 | Notifications while the app is closed | Absent | N/A | Absent | **Absent** |
| 7 | Password before a text blast | Done | Done | N/A | **Done** |
| 8 | Organization-level general services | Absent | Absent | Absent | **Absent** |
| 9 | Individual and organization borrower categories | Partial | Partial | Partial | **Partial** |
| 10 | Request an item not on the list | Done | Done | Done | **Partial** |
| 11 | Location dropdown backed by a places API | Absent | Absent | Absent | **Absent** |
| 12 | Authorized passengers capped at two | Done | Done | N/A | **Done** |
| 13 | In-app safety information verified | Partial | Done | Partial | **Partial** |
| 14 | Show only applicable vehicles | Absent | Done | N/A | **Partial** |
| 15 | Placeholder text on all input fields | N/A | Partial | Done | **Partial** |
| 16 | Gender-neutral wording | Done | Done | Done | **Done** |

Four Done, seven Partial, five Absent.

---

## 1 — Add pickup or delivery option for equipment requests · **Done**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Done | `app/Http/Controllers/EquipmentBorrowingController.php:128` validates `fulfillment_method` as `sometimes\|in:Pickup,Delivery`; `:133` makes `delivery_address` `required_if`. Columns from `database/migrations/2026_09_07_130000_add_fulfillment_to_tbl_equipment_borrowing.php`. |
| Admin | Done | `src/views/EquipmentBorrowingView.vue` renders the method and address on the record. |
| Mobile | Done | `lib/screens/borrow_equipment_screen.dart` — Pickup/Delivery toggle plus a conditional address field, shipped in `4e8d156`. |

The address is dropped rather than stored when the method is Pickup, on both the server
(`EquipmentBorrowingController.php:197`) and the client, so a value typed and then
switched away from cannot survive as a delivery instruction.

## 2 — Display available quantity per equipment item · **Done**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Done | `available_quantity` is served by `GET /equipments` (`routes/api.php:60`) and maintained by `app/Http/Controllers/EquipmentController.php:87-99`. |
| Admin | Done | `src/views/EquipmentInventoryView.vue:95-98` — the figure with a colour ramp by stock state. |
| Mobile | Done | `lib/screens/borrow_equipment_screen.dart:287` — "N available" or "None available right now"; `:163` withholds the Borrow button at zero. |

## 3 — Notify borrower one day before the return due date · **Absent**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Absent | No command reads `due_date`. `app/Console/Commands/` holds only `PurgeRetiredServices`, `ReportDuplicateConductionRequests` and `ReportEquipmentStockDiscrepancies`. `routes/console.php` schedules exactly one job, `sanctum:prune-expired`. |
| Admin | Absent | Overdue state is rendered (`isBookingOverdue`), but nothing is sent. |
| Mobile | Absent | Nothing receives such a message. |

**Blocked beyond the code.** Even if the command existed, `CLAUDE.md` records that nothing
scheduled runs on the Render deployment — no worker, no cron — so a daily reminder needs a
deployment change, not only a class. This is the item furthest from done.

## 4 — Capture equipment photos before release and on return · **Partial**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Done | `EquipmentBorrowingController::uploadPhoto()` (`:457`) and `::photo()` (`:530`); columns from `2026_09_08_120000_add_handover_photos_to_tbl_equipment_borrowing.php`; shipped in `a2c89d5`. |
| Admin | Done | `src/views/EquipmentBorrowingView.vue` uploads and displays both stages. |
| Mobile | **Absent** | No reference to `release_photo`, `return_photo` or any handover photo anywhere under `Mobile/lib`. |

**Two gaps, both worth naming.**

The mobile gap is not cosmetic. `EquipmentBorrowingController::photo()` scopes to owner
specifically so the borrower can read the photo back — its docblock says "evidence only
one side can see is not evidence" — and the app never asks for it. The server-side half
of that intent is built and unreachable.

**"Before release" is not what the code allows.** `PHOTO_STAGES` at
`EquipmentBorrowingController.php:37` permits a release photo only when the status is
already `Released` or `Returned`, and deliberately refuses `Pending` and `Approved` —
the constant's docblock explains that a photo filed before the item changed hands would
be "evidence of a handover that has not happened". So the photo records the item **at or
after** release, never before it. That is a defensible design, and it is not the Form 1
wording. Whichever is meant to give way, they currently disagree.

## 5 — Multi-role account management: admin, staff, resident, barangay · **Partial**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Partial | Two audiences, not four roles. `app/Models/User.php:57` — `isAdmin()` is `strtolower($this->role) === 'admin'`, and `app/Http/Middleware/IsAdmin.php:22` is a binary admin-or-403 gate. Residents are a separate model and table entirely (`app/Models/Resident.php`). |
| Admin | Partial | `src/views/StaffView.vue` manages `tbl_user` accounts, but the role column carries no second value the system branches on. |
| Mobile | N/A | The app authenticates residents only; no role selection exists or would mean anything there. |

**No `staff` role and no `barangay` role exist.** `AdminController.php:66` writes the
literal `'Admin'`, and nothing anywhere reads any other value. The system has exactly two
kinds of account — an admin `User` and a resident `Resident` — so two of the four
categories in the item are present and two have not been started.

## 6 — Deliver notifications even when the mobile app is closed · **Absent**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Absent | No push service, no device-token table, no FCM credentials. `Illuminate\Notifications\Notifiable` on `User` is Laravel scaffolding with no channel configured. |
| Admin | N/A | A desktop panel is not the delivery target of this item. |
| Mobile | Absent | `pubspec.yaml` declares no `firebase_messaging`, no `flutter_local_notifications`, no push dependency of any kind. |

The notifications sheet reached from `profile_screen.dart:242` is an in-app list rendered
while the app is open. It is not this item.

Out-of-band delivery today is SMS, via PhilSMS. That reaches a closed app in the sense
that it reaches the handset, and it is worth deciding whether it satisfies the intent
before building push — it would be the cheaper answer, and the SMS path already exists.

## 7 — Require password authorization before sending a text blast · **Done**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Done | `app/Http/Controllers/SmsController.php:39` calls `assertCurrentPassword()` before anything is resolved or sent; the check itself is at `:251`, rate-limited per account at 5 attempts per 15 minutes. |
| Admin | Done | `src/views/SmsView.vue:240` — the confirm dialog's password field. |
| Mobile | N/A | Residents cannot send a blast; the route is behind `is.admin`. |

The limiter is checked **before** the hash comparison (`:270`), so once the window is
spent even the correct password is refused. Separately, `66a952d` restored the route-level
send throttle at 3/hour that a demo had removed.

## 8 — Route organization-level general services through the requesting organization · **Absent**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Absent | No organization concept touches service requests. `organization` appears nowhere in `ServiceRequestController.php` or in the `tbl_service_request` schema. |
| Admin | Absent | Nothing distinguishes an organizational service request. |
| Mobile | Absent | Residents request every service directly, which is precisely what the item says must stop. |

Do not mistake item 9's work for this one. `borrower_type` and `organization_name` exist
on `tbl_equipment_borrowing` only — they describe who is borrowing equipment, not who
requests a general service, and they carry no routing behaviour. The restriction "residents
cannot request them directly" has no implementation anywhere.

## 9 — Add individual and organization as borrower categories · **Partial — wording mismatch**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Partial | `EquipmentBorrowingController.php:137` validates `borrower_type` as `sometimes\|in:Resident,Organization`. |
| Admin | Partial | `src/views/EquipmentBorrowingView.vue` shows the type and the organization name. |
| Mobile | Partial | Myself / An organization toggle in `4e8d156`. |

**Two values ship, not three, and `Individual` is refused on purpose.** `5f7dffc`'s message
gives the reason: `resident_id` is a required non-nullable FK, so every borrower is already
an account holder and an `Individual` row would be indistinguishable in the data from a
`Resident` one. Representing a genuine non-resident walk-in means making `resident_id`
nullable, which is a larger change nobody has asked for.
`tests/Feature/EquipmentBorrowingBorrowerTypeTest.php` pins that `Individual` is rejected,
so it cannot drift back in through validation.

The feature works. It does not match the Form 1 wording, which names individual **and**
organization as the additions. **Not marked Done.** Either the form is describing
`Resident` under a different name — in which case the wording should be reconciled — or a
third category is genuinely wanted, and that is the schema change `5f7dffc` declined to
make unprompted. This needs a decision, not code.

## 10 — Allow requesting equipment not on the list, recorded as MDRRMC procurement reference · **Partial**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Done | `other_equipment_text` with `required_without`/`prohibits` rules at `EquipmentBorrowingController.php:113-114`, a CHECK constraint from `2026_09_08_110000_add_other_equipment_to_tbl_equipment_borrowing.php`, and `update()`'s refusal to release an uncatalogued row (`:328`). |
| Admin | Done | `src/views/EquipmentBorrowingView.vue` shows the typed item where the catalogue name would be. |
| Mobile | Done | The free-text entry point shipped in `4e8d156`. |

**Every layer has the field, and no layer has the procurement reference.** Grepping
`procurement` and `MDRRMC` across `Backend/SERBIS-Backend`, `Web/serbis-admin-vue/src` and
`Mobile/lib` returns nothing at all.

What exists is a free-text item name on a borrowing. What the item asks for is a
*procurement record*: something with a reference the office can quote, a state of its own,
and a way to report on it. There is no reference number, no procurement status, no link to
a purchase, and no export or view that gathers these rows for MDRRMC. The closest thing to
a procurement workflow is `update()` refusing to release until staff attach a real
`Equipment` row, which is a stock guard, not a reference.

Marked Partial rather than Done for that reason: the request path is finished, the
recording half named in the wording has not been started.

## 11 — Replace free-text location entry with a dropdown backed by a places API · **Absent**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Absent | `app/Http/Controllers/ConductionRequestController.php:125-126` — `origin` and `destination` are `required\|string\|max:255`. Free text, unvalidated against any gazetteer. |
| Admin | Absent | No places, geocoding or autocomplete-against-an-API integration anywhere in `src/`. The `v-autocomplete` at `ServiceRequestQueue.vue:1073` filters a local list of residents, not places. |
| Mobile | Absent | Same free-text fields. |

`DashboardView.vue` does carry real PSA barangay boundaries for its map, so barangay-level
place data exists in the tree — but it is map geometry, not an entry control, and nothing
in the intake path consults it.

## 12 — Limit authorized passengers to a maximum of two · **Done**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Done | `ConductionRequestController.php:80` — `private const MAX_AUTHORIZED_PASSENGERS = 2`, enforced on create at `:134` and on update at `:322`. |
| Admin | Done | `src/views/ConductionRequestView.vue:650` — `max: 2` on the passenger role. |
| Mobile | N/A | Conduction requests are the staff-filled trip log. Residents neither create nor edit them. |

The cap is enforced on both write paths, not only the create one, so an update cannot walk
a record past two. Note the contrast with `MAX_RELATIVES = 20` on service requests
(`ServiceRequestController.php:64`) — a different role with a different limit, deliberately.

## 13 — Have in-app safety information verified by MDRRMC experts · **Partial**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Partial | `verified` on `tbl_info_materials` (`2026_09_07_120000_add_verified_to_tbl_info_materials.php`) and `InfoMaterialController::verify()` at `:95`. Covers uploaded files only. |
| Admin | Done | `src/views/FilesView.vue:213` — a per-material toggle, both directions. |
| Mobile | Partial | `lib/models/info_material.dart` reads the flag and `library_screen.dart` draws the badge, shipped in `e33bd94`. Covers uploaded files only. |

**The half the wording actually names is the half that is missing.** "In-app safety
information" is `Mobile/lib/data/safety_files.dart` — the CPR, burns, wound-care and
earthquake articles bundled into the binary and rendered by `_LibItem`
(`library_screen.dart:75-88`). Those articles carry no verified concept at all: grepping
`verified` in `safety_files.dart` returns nothing, and there is no mechanism by which an
MDRRMC expert could mark one.

What shipped is verification of `tbl_info_materials`, the files MDRRMO uploads through the
panel — real and useful, and a different body of content. The bundled articles ship inside
the app, so verifying them is a release-time editorial process rather than a toggle, which
may be why it was read as the same thing. It is not.

## 14 — Show only applicable vehicles when assigning a vehicle to a request · **Partial**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | **Absent** | `ServiceRequestController.php:1419` validates `vehicle_id` as `required\|integer\|exists:tbl_vehicles,vehicle_id` — existence only. Neither the type nor the availability of the unit is checked on the admin assignment path. |
| Admin | Done | `src/components/ServiceRequestQueue.vue:1764-1765` filters to `status === 'Available'` and to `type === 'Ambulance'` or `type !== 'Ambulance'` by board. |
| Mobile | N/A | Residents do not assign vehicles. |

The picker is correct and the server does not back it. A request that assigns a
`Maintenance` unit, or a non-ambulance to an ambulance booking, is accepted — the guard is
entirely client-side, and this codebase has already paid once for trusting a client-side
bound (see the `/sms/blast` throttle, `routes/api.php:112`). The auto-assignment paths at
`:355` and `:759` *do* filter `type = 'Ambulance'` server-side; it is the manual admin
assignment that does not.

## 15 — Add placeholder text to all input fields · **Partial**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | N/A | No user-facing input fields. |
| Admin | Partial | 50 `placeholder` attributes against 75 `v-text-field`/`v-textarea` instances across `src/views`. `ConductionRequestView.vue` has 17 placeholders for 22 fields; `StaffView.vue` 5 for 10; `UsersView.vue` 6 for 9. |
| Mobile | Done | Structurally guaranteed: `lib/widgets/form_inputs.dart:40` makes `hint` a **required** parameter of `AppTextField`, and `shared_widgets.dart:538` does the same for `AuthTextField`. A field without placeholder text does not compile. |

Mobile is the model here — the requirement is enforced by the type system rather than by
review. The admin panel has roughly two thirds coverage and no mechanism preventing the
next field from shipping without one. `docs/mobile-strings-audit.md` records the mobile
pass that produced this.

## 16 — Use gender-neutral wording across all user-facing text · **Done**

| Layer | Status | Proof |
| --- | --- | --- |
| Backend | Done | No gendered pronoun in any message string; the resident-facing SMS copy in `ServiceRequestController.php:1107-1160` addresses the recipient directly. |
| Admin | Done | No `he`/`she`/`his`/`her`, `he/she` or `his/her` in any `.vue` file under `src/`. |
| Mobile | Done | `docs/mobile-strings-audit.md:408` records the finding explicitly: **zero** gendered instances across 17 audited files, having checked for "Chairman", "he/she", "his/her", "him/her", "Mr./Ms./Mrs.", "Sir/Madam" and gendered role nouns. |

This is the only item with a written audit behind it rather than a spot check, and the one
whose evidence would survive someone disputing it. Note that the project's own house term,
"Head of the Family", is itself gender-neutral and is used consistently in admin copy per
`PRODUCT.md`.

---

## What to look at first

1. **#3 is blocked on deployment, not code.** Nothing scheduled runs on Render. Decide
   that before writing the command, or the command will sit unrun.
2. **#14's server-side gap is a real hole**, not a tidiness point. The panel filters and
   the API does not.
3. **#9 and #4 need a decision about the wording, not a commit.** Both ship working
   behaviour that contradicts the form. Someone has to say which text is authoritative.
4. **#10 and #13 are each half-built** in a way that reads as finished from the outside —
   the field exists without the procurement record, the badge exists without covering the
   bundled articles.
5. **#8 has not been started at all** and is the largest of the untouched items, since it
   implies an organization concept the data model does not have.
