# Cleanup report

Read-only survey of `Backend/SERBIS-Backend/app` and `Web/serbis-admin-vue/src`, taken
at `6ffe2e7` on branch `update-admin-vue`, 2026-09-08. `Mobile/` was excluded on
request; migrations and tests were read but not assessed for cleanup.

Phase 2 covered only unused imports, commented-out code, Pint, and the safe half of
eslint `--fix`; §6 records exactly what that came to.

**Corrected 2026-09-08, after acting on part of §5.** Three findings below were wrong
as first written and are rewritten in place: B3 was not a live bug, B5 overstated what
was missing, and B6's reading of `no-array-sort` was simply incorrect. Each says so at
its own heading. Nothing else in §2 through §5 has been acted on.

---

## 1. Dead code

### Unused imports

Two, both confirmed by grepping the whole file for the short name:

| File | Line | Import |
| --- | --- | --- |
| `app/Http/Controllers/BarangayController.php` | 7 | `use Illuminate\Http\Response;` — the controller returns `response()->json(...)`, which is the helper, not this class |
| `app/Models/Resident.php` | 12 | `use Illuminate\Support\Facades\Hash;` — hashing moved to `AuthController`; the model no longer touches it |

`Web/serbis-admin-vue/src` has none. Every `import` in every `.vue` and `.ts` file is
referenced; eslint's `no-unused-vars` reports nothing at import scope.

### Unused private/protected methods

None. All 58 `private`/`protected` methods under `app/` have at least one call site.
Three that a naive scan flags are false positives worth recording so they are not
re-flagged: `User::casts()` is a framework override called by Eloquent, and
`PaginatesLists::resolvePerPage()`, `PaginatesLists::paginationMeta()` and
`ScopesToOwner::scopeToOwner()` are trait methods whose callers live in the consuming
controllers, not in the trait file.

### Commented-out code

Two lines, both Laravel skeleton leftovers rather than anything this project wrote:

| File | Line | Content |
| --- | --- | --- |
| `app/Models/User.php` | 5 | `// use Illuminate\Contracts\Auth\MustVerifyEmail;` |
| `config/sanctum.php` | 25 | `// Sanctum::currentRequestHost(),` — inside the `stateful` `sprintf()` args |

The `config/sanctum.php` one is stock published config; removing it makes a future
`vendor:publish` diff marginally noisier, which is the only argument for keeping it.

There is no commented-out code in `Web/serbis-admin-vue/src`. Every `//` line that
pattern-matches as code is prose that names a function — `// clearToken() removes the
expiry stamp too`, `// getHeaders() sets Content-Type: application/json, which would
stop the browser…`. Same on the backend: ten of the twelve hits are of that shape.

### Variables assigned and never read

One, and it is the interesting kind:

- `app/Http/Controllers/EquipmentBorrowingController.php:427` — `catch (\Exception $e)`
  binds `$e` and then discards it, returning `['message' => 'Failed to process
  borrowing update'], 500`. Every other catch in the backend logs before it
  responds (`AuthController:408`, `ServiceRequestController:406/806/1093`,
  `SmsController:174`, `PurgeRetiredServices:162`). This one is the sole place where a
  failure inside a stock-mutating transaction leaves nothing behind to read.

`ConductionRequestController.php:435-436` (`$previousField`, `$previousLabel`) look
unused to a forward-only scan but are read on the next loop iteration. Not dead.

### Unreachable branches

None found. No `if (false)`, no code after `return`/`throw`, no duplicated conditions
in a chain. `TRANSITIONS['Cancelled'] = []` in `EquipmentBorrowingController` looks
like a dead key and is not — the file's own docblock explains that the key must exist
so an already-Cancelled record is refused every move with the standard message.

---

## 2. Duplication (3+ occurrences)

### D1 — `getHeaders()` in eleven views · **safe to extract, already written**

`components/ServiceRequestQueue.vue:1963`, `views/ConductionRequestView.vue:696`,
`views/EquipmentBorrowingView.vue:1127`, `views/EquipmentInventoryView.vue:220`,
`views/FilesView.vue:343`, `views/LogsView.vue:179`, `views/ServicesConfigView.vue:322`,
`views/SmsView.vue:364`, `views/StaffView.vue:314`, `views/UsersView.vue:619`,
`views/VehiclesView.vue:298`.

The replacement already exists and is unused: `composables/adminUi.ts:27`
`authHeaders(json = true)`. Its docblock records this as Findings 4 and 9, deliberately
deferred. Note that `FilesView.vue:343` is *not* identical — it omits `Content-Type`
because it posts multipart, which is exactly the `json: false` argument `authHeaders`
takes. A blind find-and-replace here breaks uploads; that is the one line in this
migration that needs reading rather than replacing.

### D2 — `notify()` + `snackbar` ref in eight views · **safe to extract, already written**

`ConductionRequestView.vue:679`, `EquipmentBorrowingView.vue:792`,
`EquipmentInventoryView.vue:219`, `FilesView.vue:341`, `ServicesConfigView.vue:321`,
`UsersView.vue:610`, `VehiclesView.vue:297`, plus `StaffView.vue` which declares the ref
without a local `notify`. `useSnackbar()` at `composables/adminUi.ts:48` returns both
together specifically so a view cannot declare one without the other. Same deferred
migration as D1.

### D3 — date formatters in five places · **safe to extract, already written**

`ServiceRequestQueue.vue:1949-1950`, `ConductionRequestView.vue:685`,
`EquipmentBorrowingView.vue:1056` and `:1072`, `LogsView.vue:241`, against
`adminUi.ts:78` `fmtDate()` / `:88` `fmtDateTime()`. The copies differ in their empty
value: the composable returns an em dash, `ConductionRequestView` returns `''`, and
`ServiceRequestQueue` had no null guard at all. That last one was a latent hazard
rather than a visible defect, and is closed as of `4fb79e0` — see B3.

### D4 — resident-only 403 guard, four copies · **safe to extract**

`AuthController.php:572`, `ResidentController.php:202` and `:251`,
`SmsController.php:322`:

```php
$user = $request->user();

if (! $user instanceof Resident) {
    return response()->json([
        'message' => 'This endpoint is for resident accounts.',
    ], 403);
```

Identical including the message. `App\Traits\ScopesToOwner` is the natural home — it
already does the neighbouring "staff see all, resident sees own" job and is already
used by four controllers.

### D5 — console-command connection banner, three copies · **safe to extract**

`PurgeRetiredServices.php:49`, `ReportDuplicateConductionRequests.php:25`,
`ReportEquipmentStockDiscrepancies.php:31` — six lines each of
`config('database.connections.'.config('database.default').'.…')` printed as
`Connected to %s@%s:%s/%s`. Pure ceremony, no per-command variation. A small shared
`Command` base class or a trait takes all three.

### D6 — status-pill CSS, four copies · **safe, but low value**

`ResidentDetailPanel.vue:211`, `ServiceRequestQueue.vue:2689`,
`ConductionRequestView.vue:1266`, `UsersView.vue:1148` — the same seven declarations
(`padding: 5px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 700;
text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap;`). These live in
four `<style scoped>` blocks, so extracting means a global class and losing the scoping.
Worth doing only alongside a wider decision about where shared styles live.

### D7 — audit-trail row insert, three copies · **do NOT extract**

`AuthController.php:1092`, `EquipmentBorrowingController.php:391`,
`TracksHistory.php:63` all end with the same
`ip_address`/`user_agent`/`created_at`/`updated_at` tail on a `tbl_system_logs` insert.
They should stay separate. `TracksHistory` is the automatic model-observer path;
the other two are deliberate manual writes that exist *because* the trait would not
have logged them — `EquipmentBorrowingController:391` writes `stock_clamped` precisely
because the trait's "no dirty attributes, don't log" rule would drop a fully-clamped
return. Coupling them would put the exception and the rule it exempts itself from
behind one function.

### D8 — model file headers, four copies · **not duplication**

`Barangay.php`, `Recipient.php`, `Service.php`, `SmsLog.php` share their first six
lines. That is a namespace plus four imports. Listed only so it is not re-reported.

---

## 3. Files over 400 lines

### Backend

| File | Lines | What it does | Is the size a problem? |
| --- | --- | --- | --- |
| `Http/Controllers/ServiceRequestController.php` | 1586 | The spine: resident intake, staff walk-in intake, approve/reject/reschedule, fleet sync, private-file serving, SMS notification copy | **Yes, mildly.** It holds three separable concerns: 16 private methods, of which `approvalMessage`/`rejectionMessage`/`rescheduleMessage`/`withReason`/`forResident` are message composition and touch no database. That group is ~150 lines and lifts out cleanly |
| `Http/Controllers/AuthController.php` | 1258 | Both token audiences: resident signup/login two-step, admin login + TOTP MFA, cache-backed pending signups, OTP bypass | **Yes, mildly.** Resident auth and admin MFA barely share code — 29 private methods split cleanly along that line. Not urgent; the file is well-commented and the halves do not interleave |
| `Http/Controllers/EquipmentBorrowingController.php` | 552 | Borrowing CRUD, the status-transition table, stock deduct/return with clamping, handover photo upload/serve | No. Long because roughly half the file is explanatory comment, and the transition table is data |
| `Http/Controllers/ConductionRequestController.php` | 500 | Trip records: checkpoint ordering validation, three passenger roles, Manila/UTC boundary | No. One cohesive resource |
| `Http/Controllers/SmsController.php` | 484 | Blast send, the unconfirmed-timeout path, balance, recipient count, history, advisories | No. Long controller, single subject |

### Admin panel

| File | Lines | What it does | Is the size a problem? |
| --- | --- | --- | --- |
| `components/ServiceRequestQueue.vue` | 2826 | The shared queue component, rendered in two different modes (`ambulance` → Bookings tab, `other` → Resident Requests) | **Yes.** This is the one file where size is a real risk. Two products in one component, with mode branches throughout, plus CSV export, description parsing and a create form. It is also where the unguarded duplicate formatters lived until `4fb79e0` (B3) |
| `views/EquipmentBorrowingView.vue` | 1581 | Borrowing queue, approve/deny/release/return dialogs, handover photos, borrower-type and other-item fields | Borderline. Grew ~300 lines in the batch just pulled. Watch it rather than split it now |
| `views/ConductionRequestView.vue` | 1349 | Two tabs — bookings (delegates to `ServiceRequestQueue`) and the hand-filled trip log | No. Mostly template |
| `views/UsersView.vue` | 1207 | Head-of-family list, detail panel, status transitions, photo/valid-ID viewing | No. Long view, one subject |
| `views/DashboardView.vue` | 803 | Analytics: Chart.js bar/line, Leaflet map against real PSA barangay boundaries | No. Chart and map config is inherently verbose |
| `views/SmsView.vue` | 686 | Blast composer, segment counter, barangay picker, history | No |
| `views/FilesView.vue` | 643 | Info-material library, upload with progress, the new verified toggle | No |
| `views/LoginView.vue` | 594 | Admin login + TOTP step | No |
| `views/VehiclesView.vue` | 580 | Fleet CRUD and status | No |
| `views/ServicesConfigView.vue` | 564 | Service catalogue config, category matching | No |
| `views/StaffView.vue` | 562 | Admin account CRUD | No |
| `views/EquipmentInventoryView.vue` | 483 | Equipment CRUD and stock | No |

---

## 4. Places a comment would genuinely help

This codebase already comments its non-obvious lines unusually well, so this list is
short by design. Every entry below is a decision whose *why* is currently invisible.

1. **`app/Http/Controllers/EquipmentBorrowingController.php:427`** — the bare
   `catch (\Exception $e)` that returns a generic 500 and logs nothing, while every
   other catch in the backend logs. Either it is deliberate (and should say what it is
   protecting) or it is an oversight (B1). A reader cannot currently tell which.

2. **`app/Http/Controllers/EquipmentBorrowingController.php:341` and `:358`** — the two
   `Equipment::lockForUpdate()->find(...)` results are used without a null check. The
   return branch at `:358` has a comment explaining why null is impossible there; the
   release branch at `:341` has the same reasoning available (`equipment_id === null` is
   refused before the transaction opens, and `EquipmentController::destroy` blocks
   deleting equipment with a live borrowing) but does not state it. Two lines would
   close the gap.

3. **`app/Http/Controllers/InfoMaterialController.php:95`** — `verify()` runs
   `InfoMaterial::find($id)` *before* `$request->validate()`, which is the reverse of
   the order every other method in the codebase uses. It changes which error a bad
   request gets: an unknown id with a malformed body returns 404, not 422. If that is
   intended, say so; it currently reads as an accident.

4. **`Web/serbis-admin-vue/src/views/UsersView.vue:793`** — `emailRule`'s regex
   `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` is deliberately loose, matching the phone rule three
   lines below it which *does* carry a "deliberately loose" comment. The email one does
   not, so it reads as an incomplete validator rather than a chosen one.

5. **`Web/serbis-admin-vue/src/views/ServicesConfigView.vue:326-328`** — the three
   category-matching regexes use capturing groups (`/(medical|ambulance|health|first
   aid)/`) whose captures are never read, because the call is `.test()`. Harmless, but a
   reader assumes a capture is captured for a reason. Either switch to `(?:…)` or note
   that the grouping is only for alternation.

6. **`Backend/SERBIS-Backend/routes/api.php:112`** — the removed `/sms/blast` throttle
   is well commented as `TEMPORARY — REMOVED FOR THE DEMO`, but nothing in the code
   states the deadline. `docs/session-note-2026-09-08.md` says it must be resolved
   before this branch merges to `main`; the route file is where someone will actually
   be standing. One line pointing at that constraint.

Not listed, on purpose: places that only need a comment restating the code. There are
plenty; none of them are worth the line.

---

## 5. Suspected bugs

B1, B2 and B4 are open and untouched. B3 and B5 have since been acted on and their
entries record what was actually true; B6 is corrected.

### B1 — `EquipmentBorrowingController::update()` swallows the failure that matters most

`app/Http/Controllers/EquipmentBorrowingController.php:427`

```php
} catch (\Exception $e) {
    DB::rollBack();
    return response()->json(['message' => 'Failed to process borrowing update'], 500);
}
```

This is the transaction that moves physical stock. If it throws, the office sees
"Failed to process borrowing update" and there is no record anywhere of why — no
`Log::error`, no `$e` written down. The Oxygen Tank discrepancy (45 total, 46
available) that the clamping code at `:360` was written to fix is exactly the class of
event that would have passed through here silently.

### B2 — `\Error` escapes that same catch, leaving the transaction open

Same block. `catch (\Exception)` does not catch `\Error`. If `Equipment::lockForUpdate()
->find()` at `:341` or `:358` returns null, `$equipment->save()` raises
`Error: Call to a member function save() on null`, which is not an `\Exception`, so
`DB::rollBack()` never runs. The connection teardown at request end rolls it back in
practice, which is why this has not shown as data loss — but it is luck, not design.

Reachability is low: `equipment_id === null` is refused before the transaction, and
`EquipmentController::destroy` refuses to delete equipment with a Pending/Approved/
Released borrowing. Low severity, real mechanism.

### B3 — `ServiceRequestQueue`'s unguarded date formatters — **CORRECTED: not a bug**

**As first written this entry was wrong.** It said a null "puts `Invalid Date` in a
table cell" and called it the highest-value fix in the report. Nothing reached those
formatters with a null. Every call site is either behind a `v-if` on the value
(`:263`, `:377`, `:553`) or passes `created_at`, which is never null. No operator ever
saw `Invalid Date`, and no screenshot or report of one exists.

What was true: `formatDate` and `formatDateTime` at
`Web/serbis-admin-vue/src/components/ServiceRequestQueue.vue:1949-1950` were the only
copies in the panel with no guard, where `adminUi.ts:78` returns an em dash and
`ConductionRequestView.vue:685` returns `''`. A guard was added in `4fb79e0`.

**That commit closed a hazard; it did not fix a visible defect.** It should not be
written up later as a bug that was found in production and fixed — the value of it is
that the next call site added to this component cannot open the hole, not that anything
was broken.

`formatTime` on the next line still has the same gap. Its one call site is `v-if`'d on
`scheduled_end`, so it is in exactly the position these two were.

### B4 — `InfoMaterialController::verify()` validates after the lookup

`app/Http/Controllers/InfoMaterialController.php:95-101`. `find($id)` runs before
`$request->validate(['verified' => 'required|boolean'])`, so a request with no
`verified` field against an unknown id returns 404 rather than 422, and a request with
a *valid* id and no body still validates correctly. Inconsistent with every other
controller here. Cosmetic unless a client branches on the status code.

### B5 — `/sms/blast`'s missing route throttle — **CORRECTED: overstated, now fixed**

**The heading as first written — "has no server-side rate limit" — was too broad.**
One already existed. `f6b51dc` added `SmsController::assertCurrentPassword`, a
per-account limiter at 5 attempts per 15 minutes, keyed on `admin_id` and checked
before the hash comparison so the correct password is refused too once the window is
spent.

What was actually missing was the second, separate guard: the route-level limit
`cdb3f37` removed for a demo. The two bound different things — the password limiter
stops guessing, the route limit stops repeated *legitimate* sends — and only the first
was in place.

Restored in `66a952d`, but **not in the form the route comment asked for**, because
that form never worked. `->middleware('throttle:3,60')` builds its cache key from
`ThrottleRequests`' route signature, which is the same signature the api group's own
`throttleApi('60,1')` in `bootstrap/app.php` already counts on. Two middleware, one
bucket: every request incremented it twice, so the limit of 3 was reached on the second
send rather than the fourth. Restoring the original line verbatim would have restored
that bug along with it.

It is a named `sms-blast` limiter now, alongside `login`/`register`/`mfa` in
`AppServiceProvider`, keyed on the account at 3 per hour. `AppServiceProvider`'s
`register` limiter already documented this exact collision in its `->by()` comment; the
blast route had it too and nothing caught it because no test covered the limit. One
now does, and it fails if the middleware is taken off again.

### B6 — eslint errors that describe real behaviour, not style

Reported by `npm run lint`. None of the three below is `--fix`-able, so all three
survive Phase 2:

- `unicorn/no-array-sort` × 11 — **CORRECTED: this entry was wrong.** It said
  `Array#sort()` mutates in place and warned that a `computed` over a `ref`'d array
  could reorder its source and feed back into reactivity. **No site does that.** All
  eleven already sort a throwaway array: `[...list]` or `[...ids]` at
  `DashboardView.vue:446,497`, `SmsView.vue:425`, `UsersView.vue:643` and
  `EquipmentBorrowingView.vue:837,844`; a `.map()` or `.filter()` result, which is
  always a new array, at `ServiceRequestQueue.vue:1773,1865`,
  `DashboardView.vue:522` and `EquipmentBorrowingView.vue:915,932`.

  The rule is not a mutation check. It flags **every** `.sort()` call regardless of
  receiver — `[...x].sort()` is reported just the same, which is why six of these
  eleven are flagged despite the spread being right there. The only thing that
  silences it is `toSorted()`, and switching is a style decision, not a correctness
  one. Nothing here needs fixing.
- `unicorn/no-array-callback-reference` × 3 (`ServiceRequestQueue.vue:1929`,
  `DashboardView.vue:645`, `SmsView.vue:352`). `.map(csvCell)` passes `index` and the
  array as extra arguments. Only a bug if the callback takes a second parameter —
  `csvCell` and `nameCharacter` should each be checked.
`regexp/no-super-linear-backtracking` × 1 (`UsersView.vue:793`) was the fourth error
here and is the one exception — it *is* `--fix`-able, and Phase 2 applied it after
checking the rewrite against the original on 335,938 strings. See §6.

---

## 6. What Phase 2 actually changed

Recorded here so this report and the commits stay in step. The suite was green
before the first commit and after every one of them: 618 tests, 2198 assertions.

1. **Unused imports** — the two in §1. One commit.
2. **Commented-out code** — the two in §1, including the stock `config/sanctum.php`
   line. One commit.
3. **Pint** — 98 files. Migrations excluded per the constraint on this pass: Pint
   wanted to reformat 14 of them, so they were reverted after the run and
   `vendor/bin/pint --test` still reports those 14 as unformatted, deliberately.
   `git diff -w` shrinks the change from 899/764 lines to 265/130, so almost all of
   it is whitespace. Two trailing comments were put back by hand afterwards:
   `ordered_imports` sorts `use` lines and carries an end-of-line comment with the
   line rather than with the class it describes, which landed AuthController's
   "Represents Admins/Staff" on `Resident` — the one class it is not true of.
4. **eslint `--fix`** — 76 lines across 14 files, all token swaps.

### On the eslint count

The pass was scoped around 16 problems. `npm run lint` reports **155** (22 errors,
133 warnings), of which 127 are `--fix`-able. Applying all 127 would not have been a
mechanical change. eslint rewrites a statement and never reformats it, and this
config has every formatting rule deliberately switched off — `eslint.config.js` says
so, and says why: the first run reported 7,921 problems and fixing them would have
rewritten nearly every file under `src/`. So a restructuring fix here lands
unindented.

Five rules were held back on that basis. Behaviour is preserved by each; the output
is not usable:

| Rule | Count | What `--fix` produced |
| --- | --- | --- |
| `curly` | 24 | `if (!token) {return null}` |
| `unicorn/switch-case-braces` | 16 | `case 'pending': { return 'warning'` / `}` |
| `unicorn/no-array-for-each` | 8 | a stray `;` left in front of the new `for` |
| `unicorn/prefer-switch` | 4 | `case 'outcome': { {`, `break;` in a semicolon-free file, whole body flattened to column 0 |
| `unicorn/prefer-ternary` | 1 | one 130-character line ending in a semicolon |

Each needs someone to reformat the result, which is judgment, not mechanics. They
are still reported by `npm run lint`.

The 19 remaining errors are not `--fix`-able at all and are written up in §5 (B6).

### The one fix that changed a pattern rather than a token

`UsersView.vue:793`:

```
/^[^\s@]+@[^\s@]+\.[^\s@]+$/   ->   /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/
```

`regexp/no-super-linear-backtracking`'s autofix. Equivalence was checked rather than
assumed: brute-forced over every string up to length 7 drawn from `{a, b, @, ., space,
-}` plus 16 realistic addresses — 335,938 strings, zero disagreements.

`npm run type-check` and `npm run build` both pass on the result.

Phase 2 itself left §2 through §5 alone. Two findings were acted on afterwards, in
their own commits and outside this batch: B3 in `4fb79e0` and B5 in `66a952d`. Both
entries are rewritten above to say what was actually true rather than what this
report first claimed.
