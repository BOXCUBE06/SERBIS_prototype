# SERBIS ERD — companion notes

Read alongside `erd.md`. Everything here was verified against the migrations,
the models, and the live `serbis_test_db` schema. Nothing in this document is
inferred from what a system of this kind usually has.

---

## 0. Decisions taken without a ruling — overrule if wrong

Four questions raised before the diagram was drawn were not answered. The
diagram was produced under the decisions below; each is reversible.

| # | Decision | Why |
|---|---|---|
| 1 | **`tbl_services` was added.** It was absent from the brief's entity list. | `tbl_service_request.service_id` is a `NOT NULL` foreign key to it. Without it, that entity carries a mandatory key pointing at nothing. |
| 2 | **`tbl_sms_logs` was added.** Also absent from the brief's list. | `tbl_recipients.sms_log_id` is a `NOT NULL` foreign key to it, and `tbl_sms_logs` is the **only** table in the schema that references `tbl_disaster`. Omitting it would strand `tbl_recipients` and leave `tbl_disaster` falsely isolated. |
| 3 | **`tbl_disaster`, `tbl_sms_logs` and `tbl_recipients` are shown**, though unimplemented (§4). | The brief named two of the three as required entities. They are real tables with real constraints. |
| 4 | **`users` is shown as an isolated entity** (§5). | The brief asked for it. It has no relationship to anything, and the brief's own rule is to draw such a table isolated rather than connect it to something plausible. |
| 5 | **`personal_access_tokens` is drawn with two dashed relationships**; `tbl_system_logs.auditable_*` is drawn as plain columns with none. | Both are polymorphic and unenforced (§2). Tokens are drawn because the brief includes the table as part of the authentication design, and disconnected it would say nothing. The audit columns point at six different models; six lines would imply six constraints that do not exist. |

The table is **`tbl_vehicles`**, not `vehicles`. The migration *file* is named
`create_vehicles_table.php`, but its body creates `tbl_vehicles`, the model sets
`protected $table = 'tbl_vehicles'`, and the live table is `tbl_vehicles`. The
brief asked for a note about `vehicles` lacking the `tbl_` prefix; that
inconsistency **does not exist** and no such note is recorded. See §6 for the
naming inconsistencies that are real.

---

## 1. Relationships in the diagram not enforced by a database constraint

Only two, and both are polymorphic — a foreign key constraint cannot express
"points at one of several tables".

### `personal_access_tokens` → `tbl_user` *or* `tbl_residents`

Laravel Sanctum's `tokenable_type` / `tokenable_id` pair. Both `App\Models\User`
(mapped to `tbl_user`) and `App\Models\Resident` (mapped to `tbl_residents`) use
the `HasApiTokens` trait, so a row's owner is whichever class name is stored in
`tokenable_type`.

- **Enforced by:** Sanctum itself, at the framework level. Nothing in this
  codebase declares a `morphTo` for it.
- **Consequence:** deleting a resident or an admin leaves their token rows
  behind. Nothing cleans them up.

### `tbl_system_logs.auditable_type` / `auditable_id`

Written by `App\Traits\TracksHistory` (`app/Traits/TracksHistory.php`) through a
raw `DB::table('tbl_system_logs')->insert()`. The referenced model is whichever
class used the trait: `Resident`, `Service`, `ServiceRequest`, `InfoMaterial`,
`Vehicle`, `EquipmentBorrowing`.

- **Enforced by:** nothing. Not a foreign key, and `SystemLog` declares no
  `morphTo` relationship — the columns are only ever written, never resolved
  back to a model.
- **Consequence:** an audit row can outlive, or never have matched, the record
  it claims to describe.

The `admin_id` and `resident_id` columns on the same table *are* real foreign
keys and are drawn as such. Both are nullable, because an action can be taken by
an admin, by a resident, or by neither — the trait writes `NULL` to both when
there is no authenticated user, which is what happens during seeding.

---

## 2. Foreign keys in the database with no Eloquent relationship

The reverse of the usual Laravel problem: the constraints exist, the model code
does not.

| Foreign key | Situation |
|---|---|
| `tbl_info_materials.uploader_id` → `tbl_user.admin_id` | `App\Models\InfoMaterial` exists but declares **no** `uploader()` relation. The column is fillable and is set by the controller; it is never traversed. |
| `tbl_sms_logs.sender_id`, `.target_area_id`, `.disaster_id` | **No `SmsLog` model exists.** |
| `tbl_recipients.sms_log_id`, `.resident_id` | **No `Recipient` model exists.** |

Every relationship that *is* declared in `app/Models/` has a matching database
constraint. Checked individually: `Barangay::residents`, `Resident::barangay`,
`ServiceRequest::{resident, service, admin, vehicle}`, `Vehicle::serviceRequests`,
`SystemLog::{admin, resident}`, `EquipmentBorrowing::{resident, equipment}`.

---

## 3. One-to-one relationships

**There are none.** A true one-to-one requires a foreign key column that also
carries a unique constraint, and no foreign key in this schema does.

There is not even a near miss any more. `tbl_service_translations` used to hold
the only unique index touching a foreign key — `(service_id, locale)`, the key
*plus* a non-key column, which is what made that relationship one-to-many rather
than one-to-one — and the table was dropped when service translations moved into
the mobile app. No unique index in the schema now covers a foreign key at all.

---

## 4. Entities that exist in the schema but not in the running system

`tbl_disaster`, `tbl_sms_logs` and `tbl_recipients` are **empty and unreferenced**:
zero rows, no model, no controller, no route, no seeder, no factory. No code in
the repository reads or writes any of them.

This matters because `SmsController::sendBlast` — the feature these tables were
designed for — persists nothing. It selects phone numbers from `tbl_residents`,
posts them to the PhilSMS bulk endpoint, and returns a count. There is no SMS log
row, no recipient row, and no link to a disaster.

They are shown in the diagram because they are real tables with real constraints
and because two of them were named in the brief. Treat them as the designed
shape of a feature whose write path has not been built.

---

## 5. `users` is orphaned

Created by Laravel's default `0001_01_01_000000_create_users_table` migration and
never used. It holds zero rows.

`config/auth.php` defines a `users` provider, but points it at
`App\Models\User` — which sets `protected $table = 'tbl_user'`. Authentication
runs entirely on `tbl_user` (admins) and `tbl_residents` (residents), both through
Sanctum tokens.

`sessions.user_id` and `password_reset_tokens.email` nominally belong to `users`,
but neither carries a foreign key and neither is used: this is a token-based API,
not a session-cookie application.

It is drawn isolated because it is isolated.

---

## 6. Naming inconsistencies, as they stand

- **`tbl_user` is singular; every other domain table is plural** —
  `tbl_residents`, `tbl_services`, `tbl_equipments`, `tbl_vehicles`. Its primary
  key follows suit as `admin_id`, so the table name and its key disagree with each
  other as well: a table called "user" whose rows are admins.
- **`tbl_service_request` is singular** where `tbl_info_materials`,
  `tbl_system_logs`, `tbl_sms_logs` and `tbl_recipients` are plural.
- **`tbl_equipments`** is a plural of a mass noun.
- **Primary keys do not follow one convention**: `files_id` for
  `tbl_info_materials`, `log_id` for `tbl_system_logs`, `borrow_id` for
  `tbl_equipment_borrowing` — none is simply `<table>_id`.
- **`tbl_service_request.processed_by`** is the only foreign key not named
  `<entity>_id`; it points at `tbl_user.admin_id`.
- The `tbl_` prefix is carried consistently by every domain table, including
  `tbl_vehicles`. The only place `vehicles` appears without it is the *filename*
  of its migration.

None of this was changed. Renaming any of it is a migration plus a sweep of every
model, controller and query, and is out of scope here.

---

## 7. Column value sets

| Column | Value set | Enforcement |
|---|---|---|
| `tbl_residents.status` | `Active`, `Deactivated`, `Inactive` | None — unconstrained `varchar(255)`, validated only as `required\|string` |
| `tbl_user.role` | `admin` | None — `varchar(255)`, functionally single-valued |
| `tbl_service_request.status` | `Pending`, `Responding`, `Resolved`, `Disapproved`, `Cancelled` | None — `varchar(255)`, indexed; `update()` validates only `string\|max:50` |
| `tbl_vehicles.status` | `Available`, `Dispatched`, `Maintenance` | Validation only — `in:Available,Dispatched,Maintenance` in `VehicleController`; the column itself is `varchar(255)` defaulting to `Available` |
| `tbl_equipments.status` | `Available`, `Unavailable` | Database `enum` |
| `tbl_equipment_borrowing.status` | `Pending`, `Approved`, `Released`, `Returned`, `Denied` | Database `enum` |
| `tbl_sms_logs.status` | Not defined | Unpopulated (§4) |
| `tbl_recipients.status` | Not defined | Unpopulated (§4) |
| `tbl_services.code` | One slug per service — `flood-evacuation`, `ambulance-medical-response`, … | Uniqueness by database index; everything else in the application (below) |

### `tbl_services.code` — unique in the database, immutable only in the app

`code` is the field a client keys behaviour on: `service_id` is positional and
`service_name` is display text an admin can rewrite. The database enforces that
it is `NOT NULL` and unique, and nothing more.

Immutability is application-side and rests on two independent omissions. `code`
is absent from `#[Fillable]` on `App\Models\Service`, so no mass assignment can
carry it; and it is absent from `ServiceController`'s validation rules, so a
request that sends one has it discarded before that. A `creating` hook — never
`saving` — slugifies `service_name` when no code is set, which is why a rename
cannot move the code.

A direct `UPDATE` against the table would still change it. Nothing in the
schema prevents that.

### `tbl_residents.status` — `Deactivated` and `Inactive` are synonyms

Two code paths write two different words for the same state:

- Mobile self-registration writes `'Inactive'` (`AuthController.php:44`).
- The admin panel's activate/deactivate toggle writes `'Deactivated'`
  (`Web/serbis-admin-vue/src/views/UsersView.vue:563`), and its create/edit form
  offers only `Active` / `Deactivated`.

Both mean "not active" to every consumer, because every consumer tests for
`'Active'` and treats anything else as its opposite — `SmsController` blasts only
`where('status', 'Active')`, and the status pill in `ResidentDetailPanel.vue:18`
renders anything `!== 'Active'` as grey.

The one place it does not hold is the Users list filter, which matches exactly
(`UsersView.vue:430`) against a dropdown offering only `All / Active /
Deactivated`. A self-registered resident sitting at `'Inactive'` therefore appears
under neither filter — visible only under "All", which is exactly the account an
admin needs to find in order to activate it.

**Confirmed: there is no `Suspended` and no `Banned` state.** Deactivation also
does not block login or request submission; `residentLogin` never inspects
`status`. Flagged for cleanup outside this task. **No schema change was made.**

Sources for the two unenforced vocabularies above, so neither is taken on trust:
`tbl_service_request.status` is written as `Pending` by `store()`
(`ServiceRequestController.php:84`) and `Cancelled` by `cancel()` (`:192`), with
`Responding`, `Resolved` and `Disapproved` set by the admin panel through
`update()` (`ManageRequestView.vue:184-192`, tab list at `:268`).
`tbl_vehicles.status` moves `Available` → `Dispatched` when a unit is assigned
(`ServiceRequestController.php:90`) and back on cancellation (`:188`);
`Maintenance` is only ever set by an admin through `VehicleController`.

### `tbl_sms_logs.api_job_id`

Intended to hold the PhilSMS job handle returned by the bulk-send endpoint.
Unpopulated — nothing writes it (§4).

### `tbl_equipments.status` vs `available_quantity`

Two columns, two different questions, and they are not redundant:

- **`available_quantity`** is authoritative for stock. It is adjusted by the
  borrowing flow.
- **`status`** reflects serviceability and is set manually by an admin — an item
  can be in stock and still unfit to lend.

**Borrowability is `available_quantity > 0` AND `status = 'Available'`.**

**Nothing currently enforces this rule.** It is not a database constraint, not a
model accessor, and not a validation rule; it holds only where a caller happens
to check both. There is also no constraint tying `available_quantity` to
`total_quantity`, so nothing prevents the available count exceeding the total or
going negative.

---

## 8. Email comparison is case-insensitive, and that does not survive PostgreSQL

Every table is `utf8mb4` / `utf8mb4_unicode_ci`; the database default collation is
`utf8mb4_general_ci`. Both are **case-insensitive**.

So MariaDB currently treats `Admin@serbis.com` and `admin@serbis.com` as the same
value, both for the `UNIQUE` indexes on `tbl_user.email_address` and
`tbl_residents.email_address` and for the `where('email_address', ...)` lookups in
`AuthController::adminLogin` and `::residentLogin`.

PostgreSQL does not. Under a naive migration:

- A resident who registered as `Juan@example.com` and later types
  `juan@example.com` is silently refused, and it presents as "random users cannot
  log in".
- Two accounts differing only in case become insertable, past a `UNIQUE` index
  that looks like it should have stopped them.

The fix is to normalise to lowercase at both write and lookup — or adopt
`citext` — as an explicit migration step, not something hoped for.

Recorded in full as item 2 of
`Backend/SERBIS-Backend/docs/cloud-migration-plan.md` (note: that file lives under
the backend, not the repository-root `docs/`).

---

## 9. Planned database rename

`serbis_test_db` → **`serbis_db`**, after alpha testing.

The name appears in `Backend/SERBIS-Backend/.env` (`DB_DATABASE`) rather than in
any migration, so the rename is an environment change plus a data move, not a
schema change. This document and `erd.md` name the database as it stands today.
