# Unattended test run, 2026-10-17

Local only. No commits, no app code changed. CLAUDE.md text updated (step 3).

## Results

| Suite | Baseline | Final |
| --- | --- | --- |
| Backend (`php artisan test`) | 1568 tests, 1568 pass, 0 fail | 1688 tests, 1687 pass, 0 fail, 1 skipped |
| Mobile (`flutter test`) | 934 pass, 1 fail | 934 pass, 1 fail (same test) |

Backend +120 tests. The one skip is the "admin with the section" case for Staff Accounts: that section cannot be assigned, only a super admin opens it.

Files: `unit/baseline.xml`, `unit/backend-baseline.txt`, `unit/mobile-baseline.txt`, `unit/mobile-final.txt`, `final.xml`, `final-backend.txt`, `bug-log.csv`. The `integration/`, `rbac/`, `mfa/` folders are empty: the tests live in `tests/`, and only the two full runs were logged.

## New and extended test files (Backend/SERBIS-Backend/tests)

New, Unit: `PhoneNumberTest`, `RecipientStatusMappingTest`, `SmsMessagePolicyTest`, `SmsResultTest`, `AdminSectionsTest`, `AmbulanceAvailabilityOverlapTest`.
New, Feature: `RoutePermissionMatrixTest`, `ResidentCannotReachAdminRoutesTest`, `AdminLoginMfaDisabledTest`.
Extended: `ResidentPhoneSignupTest` (data provider, 17 invalid cases plus a valid organization), `AdminSectionAccessTest` (provider: 401 / 403 / section 2xx / super admin 2xx over 7 routes), `AdminSmsMfaTest` (+2: code valid at 4 min, expired challenge stays dead after wrong tries).

Step 6 check: `AdminSmsMfaTest` already covered phone_missing, expiry (6 min), the 5-attempt lock (429 `too_many_attempts`) and wrong code 422 `invalid_code`.

## Bug log

- BUG-001 Low: Mobile `ConfirmationSheet` Booked test fails in baseline and final. Probably a stale test after the 2026-10-06 redesign.
- BUG-002 Low: resident gets 404 instead of 403 on `sms/deliveries/{smsLog}/check|recipients` for a missing id (binding runs before `is.admin`). Id-existence oracle only.

## Step 2: pending-SMS audit (report only)

**Source of the claim.** Memory `serbis-status.md` line 43 (SkySMS facts from the first real sends, 2026-09-20/21), echoed in `docs/it-validator-checklist.md:93`. It was two test messages (queue ids 233895, 233896) sent through `/sms/send-bulk`: they stayed `pending` with `sent_at` null, nothing in `/sms/messages/failed`, credits already charged. So it is a vendor-side report on 2 messages, not a measured rate, and a SkySMS support ticket was open. There is no code or comment with that wording.

**Local DB.** `serbis_test_db` has 4 sms_logs, all with `delivery_checked_at`. Recipient counts: logs 2, 3, 4 each `Failed 1, Pending 1, Queued 1, Sent 1`; log 1 `Pending 2`. That is seeded data (DevVolumeSeeder), not real sends, so it says nothing about SkySMS. Fewer than 10 blasts exist.

**Ways a SkySMS-sent message could stay Pending / Queued** (`SmsDeliveryController`):
1. Vendor never moves it (the original report). Code is correct to leave it Pending.
2. Check is manual only. Nothing calls `check` on a schedule, so a recipient stays Queued until staff press the button.
3. A recipient is matched by `queue_id` AND `PhoneNumber::normalize(resident.phone_number)`. If the resident's number changed after the blast, or is not canonical, `$notFound++` and the row keeps its old status, forever.
4. `fetchMessages` reads at most 10 pages x 100 messages from `created_at` date to +1 day. That window also holds OTP and reminder texts. A big blast on a busy day can exceed 1000 messages, and anything past page 10 is "not found" and stays Queued. A blast over a day old is still queried only from its own date, so late status changes are fine, but the +1 day cap can cut off messages created after midnight UTC two days later.
5. `$byPhone[$phone]` is keyed by phone only: if one number appears twice in the same queue ids (two barangay rows, duplicate recipient), the later message overwrites the earlier one.
6. `Recipient::fromVendor` maps any unknown word (for example "delivered", "expired") to Other, never Sent, so a vendor status outside pending/queued/sent/failed never confirms delivery. Safe, but it will read as not delivered.
7. Log-level rollup: Sent/Failed only when every recipient agrees; one stuck recipient keeps the whole barangay row Queued.
8. Minor code defect: `maskPhone` uses `preg_replace('/D/', ...)`, missing the backslash (`\D`). It strips the letter D, not non-digits. Harmless today because `display()` returns digits only, but wrong.

Not verified against the live API (hard limit: no production, no real SMS).

## Decisions made

- Step 2 source: no code string matched, so I used memory and the validator checklist.
- Mobile and backend final runs were sequential; the baselines ran in parallel.
- Data provider cases for `/register` use `assertJsonValidationErrors($key)`, plus "no SMS texted".
- `AmbulanceAvailabilityOverlapTest` is pure logic only: the overlap runs in SQL, so it pins `windowEnd()` (via reflection), terminal statuses and the default length. Real overlap stays covered by existing Feature tests.
- `RoutePermissionMatrixTest` keeps two explicit allow-lists (PUBLIC, SIGNED_IN). A new `/api` route fails the test until someone classifies it. The lists also fail on stale entries.
- `AdminSectionAccessTest` provider routes: logs, residents, responders, files, service audience, procurement, staff. `/api/admin/service-requests` was dropped because either requests or ambulance opens it.
- The "baseline" backend run happened while I started editing `ResidentPhoneSignupTest`; PHPUnit loads files at start, so the 1568 count is pre-edit.
- Trials 2-3 in the bug log are blank where I did not rerun.

## Follow-up: bug fixes and regression (2026-10-17)

- BUG-001 closed. Correction to the first diagnosis: not a redesign issue. The test's booking date (2026-10-12) passed, so the app correctly showed the overdue state. Test now uses 2099.
- BUG-002 closed. `bootstrap/app.php`: `prependToPriorityList(before: SubstituteBindings, prepend: IsAdmin)`. Global to all admin routes, not only `/sms/deliveries`; priority lists cannot be set per route.
- Three full runs each (`regression/`): backend 1690 tests, 1689 pass, 1 skipped, x3. Mobile 935 pass, 0 fail, x3.
- The one skip is `AdminSectionAccessTest::test_an_admin_with_the_section_is_let_in` for the `staff` row: Staff Accounts is not assignable, so no limited admin can hold it; `test_a_super_admin_is_let_in` covers that route.

## Phase 2: push coverage gaps (2026-10-17)

**Audit of `PATCH /service-requests/{id}/cancel`.** Reachable by a resident (own requests only) and by any admin holding the requests or ambulance section (any resident's request). It cancels Pending/Booked requests, frees the unit and responders, and previously sent no push to anyone. `PUT /service-requests/{id}` cannot reach Cancelled at all: `ALLOWED_TRANSITIONS` has no `Cancelled` key, so it answers 422. `cancel` is the only path.

**Change** (`ServiceRequestController::cancel`): when the caller is staff (`User`), push the resident "Your {service} request was cancelled by MDRRMO. Call the office for details." A resident cancelling their own request sends nothing. Service name falls back to "service" for a request with no service row.

**New tests:**
- `ServiceRequestCancelPushTest` (5): staff cancel pushes once with request_id; resident self-cancel sends nothing; refused cancel sends nothing; staff cancel works with no device; update path answers 422 and sends nothing. Fails without the controller change.
- `VehicleDispatchPushTest` (4): non-ambulance dispatch pushes once; approved ambulance dispatch pushes once and marks the unit Dispatched; unapproved dispatch (422) and busy unit (409) send nothing.

The request to test "push when the update path moves a request to Cancelled" could not be met as written, since that transition is forbidden. The test instead pins that it is refused and silent.

**Bug log:** BUG-003 (Low, Known limitation): a service-request push accepted by zero devices leaves no trace and no staff follow-up. No fix.

**Regression** (`regression/phase2/`): backend 1699 tests, 1698 pass, 1 skipped (same Staff Accounts case), 0 fail, three runs in a row. Mobile not rerun; no mobile change.

## Phase 3: RTM gaps (2026-10-17)

**New backend tests** (+33): `AdminTokenExpiryTest` (5: 8-hour stamp, 7h59 accepted, 8h01 rejected on /me and an admin route, cap follows config), `ResponderCrudTest` (12: CRUD, photo upload/replace/delete, validation, 404s, deployed guard, section gate, 401), `ResidentBulkStatusTest` (6: the panel's per-row PUT loop for Activate/Deactivate, token revocation, one refused row does not stop others, section gate).

**New web tests** (+15, `Web/serbis-admin-vue`): `src/router/routerGuard.test.js` (8, real router) and `src/composables/requestExport.test.js` (8 with the date-range case; CSV columns, row count equals the filtered list, Manila dates, formula guard, quoting).

**Decisions and deviations**
- Vitest is not installed and `npm test` already runs `node --test`, so both web tests use `node:test`. Adding Vitest would be a new dependency; say so if you want it anyway.
- Neither module could be imported by `node --test` as is (`@/` alias, extensionless imports, `.vue`, `import.meta.env`). Added `src/test-support/register-hooks.mjs` plus `api-stub.mjs`, loaded by `--import` in the `npm test` script. No app code changed. The 80 existing web tests still pass.
- Request export is built in the browser (`requestExport.js`); the server only logs it. So "columns and row count match the list" is a web test, not a backend feature test. `ExportLogTest` still covers the log.
- Bulk Activate/Deactivate is a client loop of PUT /residents/{id}; the backend test covers that contract. The selection rules (skip Pending, leave already-in-state) are inline in `UsersView.vue` and untestable without a browser; new row FR-ADM-54, Gap.
- A hand-edited permission list naming `staff` cannot reach the panel (the server strips it from /me), so the router test checks "refused unless /me lists it" instead.
- Skipped test count unchanged (1, Staff Accounts "with the section" case).

**"Verify" rows**: info-materials delete/list stays Gap (list asserts one field, delete asserts HTTP 200 only, not that the row or file is gone). 8-hour cap was a Gap, now Pass with the new test. System-log listing Pass (`ListPaginationTest`, `AdminPermissionsTest`).

**RTM** (`audits/rtm-draft.csv`, 125 rows): Pass 101, Gap 2, Live 15, Manual 6, Out of scope 1. Moved: latency/load and tablet width to Live, migration dry run to Out of scope, admin UI rows FR-ADM-48 to 53 to Manual (FR-ADM-53 keeps execution live-production).

**Regression** (`regression/phase3/`), three runs each: backend 1722 tests, 1721 pass, 1 skipped, 0 fail; web 95 tests, 0 fail. Mobile not rerun (no mobile change). No failures, so nothing added to `bug-log.csv`.

## Final regression (2026-10-17)

**Info-materials (FR-ADM-35, now Pass).** New `InfoMaterialLifecycleTest` (9 tests, `Storage::fake`): upload stores file and row; the admin list returns every field the panel and app read (`files_id, title, file_type, file_size, full_url, created_at, verified, verified_by_name, verified_by_role`) and the resident list the same core fields; newest first; delete removes the row and the stored file (also a legacy `storage/`-prefixed path) and the item leaves both lists while others stay; second delete and unknown id are 404; without the Files section nothing is deleted.

**FR-ADM-54** is Manual: "select Pending, Active and Inactive accounts together; Activate must skip Pending and already-active rows."

**Runs** (`regression/final/`, three each, no failures, nothing added to `bug-log.csv`):

| Suite | Tests | Result |
| --- | --- | --- |
| Backend | 1731 | 1730 pass, 1 skipped, 0 fail |
| Web (`npm test`) | 95 | 95 pass |
| Mobile | 935 | 935 pass |

**Final RTM counts** (`audits/rtm-draft.csv`, 125 rows): Pass 102, Live 15, Manual 7, Out of scope 1, Gap 0.

Bug log: BUG-001 and BUG-002 Closed, BUG-003 Known limitation.

## CI failures on merge commit 82f8c383 (2026-10-17)

PHPUnit (Backend) failed on CI (MySQL 8) with 3 tests that pass on local MariaDB 10.4. Flutter passed.

| Bug | Test | Cause | Production? |
| --- | --- | --- | --- |
| BUG-004 | `EquipmentAvailabilityNotifierTest` follow up not repeated within a day | `ReminderFollowUp` dedup used `new_values LIKE '%"kind":"x"%'`; MySQL stores JSON as `"kind": "x"`, so it never matched | Yes, Medium: duplicate "Follow up by phone" rows |
| BUG-005 | `SendReturnRemindersTest` second run the same day | Same line, reached through the cron command | Yes, Medium |
| BUG-006 | `ExportLogTest` log row carries no record data | `assertSame` on JSON key order; MySQL sorts keys | No, test only, Low |

Fixes: `ReminderFollowUp` now filters with `where('new_values->kind', $kind)`; `ExportLogTest` uses `assertEquals`.

**Verification:** no Docker or MySQL 8 on this machine. On local MariaDB 10.4: the 3 test classes pass (32 tests), full backend suite 1731 tests, 1730 pass, 1 skipped, 0 fail, one run. MariaDB cannot reproduce the original failure, so these runs show no regression but do not prove the fix on MySQL 8; that needs a CI run. BUG-004 to 006 status: "Fixed locally; MySQL 8 not verified".
