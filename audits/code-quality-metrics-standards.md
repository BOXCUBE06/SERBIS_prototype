# Code Quality Metrics and Standards — Mobile and Admin Panel

**Scope:** `Mobile/lib` (42 Dart files, 13,923 lines) and `Web/serbis-admin-vue/src`
(31 files, 11,524 lines).

**Date:** 2026-08-31. **Read-only. Nothing in this file has been applied.**

**Method.** A script stripped comments and string literals, then brace-matched
every function and method it could bracket and measured length, cyclomatic
complexity (decision points + 1: `if`, `for`, `while`, `case`, `catch`, `&&`,
`||`, `??`, ternary) and maximum brace nesting. **461 functions were bracketed
and 0 were skipped**, so the numbers below cover the whole of both trees rather
than a sample. Vue files were measured on their `<script>` block only.

Cyclomatic complexity from a text scan is an approximation — it cannot see
short-circuit evaluation that never runs, and it counts a `??` in a data literal
the same as a branch. Treat the numbers as a ranking, not as certified values.
Every finding below was then confirmed by reading the function.

---

## The shape of the two codebases is different, and the usual thresholds mislead

| | Functions | > 50 lines | CC > 10 | CC > 15 | Nesting ≥ 4 | Median length |
|---|---|---|---|---|---|---|
| Mobile | 270 | **44** | 18 | 5 | **2** | 17 lines |
| Admin panel | 191 | **2** | 18 | 4 | **19** | 8 lines |
| Total | 461 | 46 | 36 | 9 | 21 | 13 lines |

Read that carefully before acting on it. **Mobile's long functions are mostly not
complex, and the panel's short functions mostly are.**

Flutter's `build()` returns a declarative widget tree, so a 205-line `build()`
can be a flat list of layout with no decisions in it at all —
`dashboard_screen.dart:29` is 205 lines with **cc=7 and nesting 1**. Applying a
"functions over 50 lines" rule to that produces 44 findings of which most are
noise.

The panel is the mirror image: median function 8 lines, only two over 50, but
**19 functions nested four deep or more** and a worst case of cc=25 in 67 lines.

So the discriminator used below is **not length**. For Mobile it is "does this
`build()` contain decisions" (cc > 10); for the panel it is complexity and
nesting outright.

---

## Severity summary

| # | Finding | Where | Severity |
|---|---------|-------|----------|
| 1 | 17 of 18 `.vue` files opt out of TypeScript; `vue-tsc` passes because it checks almost nothing | Web | **9/10** |
| 2 | `ServiceRequestQueue.vue` is 2,297 lines and serves two different screens via a string prop | Web | **8/10** |
| 3 | `submitWalkIn` — cc 25, five abstraction levels in one function | `ServiceRequestQueue.vue:1872` | **7/10** |
| 4 | Five `build()` methods carry real branching logic | Mobile | 7/10 |
| 5 | `flutter_lints` is the untouched scaffold default | `Mobile/analysis_options.yaml` | 6/10 |
| 6 | `fromJson` parsers at cc 15–20 | Mobile models/stores | 5/10 |
| 7 | `profile_screen.dart` at 1,385 lines is four screens in one file | Mobile | 5/10 |
| 8 | Card and row CSS duplicated between two views, by admission | Web | 4/10 |
| 9 | 26 files over 300 lines | both | 3/10 |

Coupling and cohesion findings live in `design-pattern-implementation.md` —
`ApiService` (862 lines) and `AppState` (837 lines) are architecture problems,
not metric problems, and the fix is structural.

---

## Finding 1 — the type-check passes because it barely checks anything

**Severity 9/10.** The most important finding in this document, and the one most
likely to come up in a review.

```
TS   src/components/AppSidebar.vue
JS   src/App.vue
JS   src/components/ResidentDetailPanel.vue
JS   src/components/ServiceRequestQueue.vue
JS   src/views/ConductionRequestView.vue
JS   src/views/DashboardView.vue
JS   src/views/EquipmentBorrowingView.vue
JS   src/views/EquipmentInventoryView.vue
JS   src/views/FilesView.vue
JS   src/views/LoginView.vue
JS   src/views/LogsView.vue
JS   src/views/ManageRequestView.vue
JS   src/views/NotFoundView.vue
JS   src/views/ServicesConfigView.vue
JS   src/views/SmsView.vue
JS   src/views/StaffView.vue
JS   src/views/UsersView.vue
JS   src/views/VehiclesView.vue
```

**One of eighteen** `.vue` files declares `<script setup lang="ts">`. The other
seventeen — including all 2,297 lines of `ServiceRequestQueue.vue` — are plain
JavaScript inside a TypeScript project.

The seven files in `src/composables/` *are* fully typed, which is why this is
easy to miss: the type-safe part of the panel is the 543-line support layer, and
the 9,000 lines of screens that handle resident data are unchecked.

This matters beyond style because **"`vue-tsc --noEmit` is clean" has been
recorded as a quality signal**. It is clean, but it is checking 1 component and 7
composables. A misspelled field on an API response, a `null` where an object was
expected, a renamed prop — none of it is caught anywhere in the panel today.

### Remediation

Do **not** convert all seventeen at once; that is a large diff with no tests
behind it. Convert in this order, one file per commit:

1. `LoginView.vue` — smallest surface, and auth is worth checking first.
2. `ResidentDetailPanel.vue` — 265 lines, already consumes typed composables.
3. `EquipmentInventoryView.vue`, `LogsView.vue`, `SmsView.vue` — under 500 lines each.
4. `ServiceRequestQueue.vue` — **only after Finding 2 splits it.**

Per file the change is:

```diff
-<script setup>
+<script setup lang="ts">
```

then fix what the compiler reports. Expect the API response shapes to be the bulk
of it; declare them once and share:

```ts
// src/types/api.ts  (new)
export interface ServiceRequest {
  service_request_id: number
  resident_id: number | null
  walk_in_name: string | null
  service_id: number
  description: string
  status: 'Pending' | 'Approved' | 'Disapproved' | 'Booked' | 'Completed' | 'Cancelled'
  created_at: string
  scheduled_at: string | null
}
```

**Unable to verify** the exact status set from the client alone — it is a plain
varchar on `tbl_service_request`. `ServiceRequestController`'s validation rules
and `ServiceRequestQueue.vue`'s filter list together would settle it; do that
before writing the union type rather than guessing.

Add a CI gate once the count reaches zero, so it cannot regress:

```json
// package.json — there is currently no "lint" script at all
"scripts": {
  "type-check": "vue-tsc --noEmit",
  "lint": "eslint . --ext .vue,.ts",
  "check:ts-only": "! grep -rL 'lang=\"ts\"' src --include='*.vue'"
}
```

---

## Finding 2 — one component, 2,297 lines, two jobs

**Severity 8/10.**

```
<template>   lines 1–983      (982 lines)
<script>     lines 985–1979   (994 lines)
<style>      lines 1981–2297  (316 lines)
```

It holds **41 reactive bindings** (21 `ref`, 20 `computed`) and is used by two
different screens that ask it to behave differently:

```vue
<!-- views/ManageRequestView.vue:6 -->
<ServiceRequestQueue scope="other" />
<!-- views/ConductionRequestView.vue:24 -->
<ServiceRequestQueue scope="ambulance" :standalone="false" @dispatch-booking="..." />
```

`scope` is a string flag switching behaviour inside the component — `submitWalkIn`
alone branches on `props.scope === 'ambulance'` twice. This is the classic shape
that grows without bound: every new difference between the two screens adds
another conditional rather than another file.

`ManageRequestView.vue` is 10 lines. All of its content is here. So the panel's
largest file is also the one with the least obvious owner.

### Remediation

Do not split it by cutting the template in half. Extract the **state** first, into
a composable, and let the two screens keep their own markup:

```ts
// src/composables/useServiceRequestQueue.ts  (new)
export function useServiceRequestQueue(scope: 'ambulance' | 'other') {
  const rows = ref<ServiceRequest[]>([])
  const loading = ref(false)
  const filters = reactive({ status: 'All', query: '' })

  const filtered = computed(() => /* moved from the component */)

  async function fetchData() { /* moved from the component */ }

  return { rows, loading, filters, filtered, fetchData }
}
```

Then `ManageRequestView.vue` and `ConductionRequestView.vue` each own a template
sized to what they actually show, and the `scope` conditionals disappear into two
call sites instead of living inside every method.

This is a multi-day change. **Sequence it after Finding 1's smaller conversions**
so the extraction happens with the type-checker switched on, not before.

---

## Finding 3 — `submitWalkIn`: cc 25, the highest in either codebase

**Severity 7/10.** `Web/serbis-admin-vue/src/components/ServiceRequestQueue.vue:1872`,
67 lines, nesting 4.

One function does five separable things:

1. **Validation** — five guard clauses, each setting `createDialog.value.error`
   and returning.
2. **UI state** — `loading`, `error`, `open` on the dialog.
3. **Payload assembly** — eleven `body.append(...)` calls with conditionals.
4. **Transport** — a hand-built `fetch` with hand-built auth headers.
5. **Error decoding** — digging the first Laravel validation message out of
   `errData.errors`.

`createDialog.value.` appears fourteen times, which is the readability symptom of
the same problem.

### Remediation

Three functions, no behaviour change. Complexity drops from 25 to roughly 9 / 6 / 4:

```js
/** Returns the message to show, or null when the form is fileable. */
function validateWalkIn(form, requesterType, scope, scheduleForLater) {
  const isResident = requesterType === 'resident'
  if (isResident && !form.resident_id) return 'Pick a Head of the Family'
  if (!isResident && (!form.walk_in_name.trim() || !form.walk_in_contact_number.trim()))
    return 'Name and contact number are required for someone with no account'
  if (!form.service_id) return 'Pick a service'
  if (!form.description.trim()) return 'Description is required'
  if (scope === 'ambulance' && scheduleForLater && !form.scheduled_at)
    return 'Pick a date and time, or turn off scheduling to dispatch now'
  return null
}

function buildWalkInBody(form, requesterType, scope, scheduleForLater) {
  const body = new FormData()
  // ... the eleven appends, unchanged
  return body
}

const submitWalkIn = async () => {
  const d = createDialog.value
  const problem = validateWalkIn(d.form, d.requesterType, props.scope, d.scheduleForLater)
  if (problem) { d.error = problem; return }

  d.loading = true
  d.error = ''
  try {
    await postServiceRequest(buildWalkInBody(d.form, d.requesterType, props.scope, d.scheduleForLater))
    d.open = false
    await fetchData()
  } catch (error) {
    d.error = error.message
  } finally {
    d.loading = false
  }
}
```

`validateWalkIn` is then a pure function — **the first thing in this component
that could be unit-tested at all.** That is the real return here, given the panel
has no tests.

The `postServiceRequest` transport and its Laravel error decoding belong in the
shared API client proposed in `design-pattern-implementation.md` Finding 2, not
inline here.

**Keep the existing comment about `Content-Type`** — it explains why the
multipart request must not set the header, which is a genuine trap and the kind
of comment worth preserving through a refactor.

---

## Finding 4 — five `build()` methods carry branching logic

**Severity 7/10.** These are the mobile functions where length and complexity
coincide, which is the signal that layout and logic have merged.

| Location | Lines | CC | Nesting |
|---|---|---|---|
| `screens/auth/register_screen.dart:166` `build` | 219 | **18** | 3 |
| `screens/library_screen.dart:23` `build` | 87 | **14** | 1 |
| `screens/auth/verify_email_screen.dart:194` `build` | 78 | **12** | 1 |
| `screens/auth/verify_login_screen.dart:196` `build` | 78 | **12** | 1 |
| `screens/library_screen.dart:223` `build` | 50 | **11** | 1 |

For contrast, the *longest* `build()` in the app — `dashboard_screen.dart:29`, 205
lines — is **cc 7, nesting 1**. It is long because a dashboard has many cards.
That one is fine and should be left alone.

`register_screen.dart`'s is the outlier on both axes and is the one to fix.

### Remediation

Move decisions out of `build()`, leave layout in it. For the two verify screens,
which are near-identical twins at 78 lines and cc 12 each, the branching is
mostly channel/state copy:

```dart
// before, inside build()
Text(delivery == null
    ? 'We sent a 6-digit code to ${widget.email}.'
    : delivery!.bySms
        ? (delivery!.sentTo.isEmpty ? '...' : 'We sent a 6-digit code by text ...')
        : 'We sent a 6-digit code to ${delivery!.sentTo}.')

// after — already exists as a helper in verify_email_screen.dart; give
// verify_login_screen.dart the same treatment and build() stops branching
String get _deliveryLine { ... }
```

`verify_email_screen.dart` and `verify_login_screen.dart` being twins is itself
worth noting — see `code-duplication.md` for how that pair was reasoned about.
The existing comment in `verify_login_screen.dart:19` explains why they are
deliberately two files; that reasoning still holds, but the *shared* copy logic
does not have to be duplicated with them.

---

## Finding 5 — the Flutter lint config is the untouched scaffold

**Severity 6/10.** `Mobile/analysis_options.yaml` is byte-for-byte the file
`flutter create` emits: every rule under `linter: rules:` is commented out.

```yaml
  rules:
    # avoid_print: false  # Uncomment to disable the `avoid_print` rule
    # prefer_single_quotes: true  # Uncomment to enable the `prefer_single_quotes` rule
```

So the only rules running are `flutter_lints` defaults. Several of this
document's and the naming audit's findings are things the analyzer could have
caught for free.

### Remediation

Add the rules that map onto findings already made, so they cannot come back:

```yaml
linter:
  rules:
    # Finding 1 of readability-and-naming.md: positional booleans.
    avoid_positional_boolean_parameters: true
    # Catches the stale-comment class of bug at the source.
    avoid_print: true
    prefer_single_quotes: true
    always_declare_return_types: true
    prefer_final_locals: true
    unawaited_futures: true
```

**Expect `avoid_positional_boolean_parameters` to fail on all 24 sites
immediately.** That is the point, but it means enabling it and doing the named-
argument pass are the same commit, not two. If that is too large, enable the
other five now and add this one with the fix.

`flutter analyze` currently reports 25 issues (24 info, 1 warning) per the
project's own records; **Unable to verify** that count here without running it,
and the numbers above do not depend on it.

---

## Finding 6 — `fromJson` parsers are the densest logic in the mobile app

**Severity 5/10.**

| Location | Lines | CC |
|---|---|---|
| `models/request_models.dart:655` `fromJson` | 59 | **20** |
| `models/borrow_models.dart:167` `fromJson` | 31 | **18** |
| `state/account_store.dart:77` `fromJson` | 21 | **18** |
| `state/material_cache.dart:43` `fromJson` | 19 | **18** |
| `models/request_models.dart:739` `fromCacheJson` | 39 | **15** |

The complexity is almost entirely null-coalescing and type-checking against an
untyped `Map<String, dynamic>` — `value is String ? ... : ...`, `?? ''`, `?? 0`
repeated per field. That is defensive and correct given the input, so this is a
lower severity than the raw number suggests.

The problem is that it is written out by hand, per field, per model, so a missing
guard is invisible.

### Remediation

Extract the coercions once and let each `fromJson` read as a field list:

```dart
// Mobile/lib/models/json.dart  (new)
String asString(dynamic v, [String fallback = '']) => v is String ? v : fallback;
int asInt(dynamic v, [int fallback = 0]) =>
    v is int ? v : (v is String ? int.tryParse(v) ?? fallback : fallback);
bool asBool(dynamic v, [bool fallback = false]) =>
    v is bool ? v : (v is num ? v != 0 : fallback);
DateTime? asInstant(dynamic v) =>
    v is String && v.isNotEmpty ? DateTime.tryParse(v)?.toLocal() : null;
```

```dart
// before
status: json['status'] is String ? json['status'] as String : '',
quantity: json['quantity'] is int ? json['quantity'] as int : 0,
// after
status: asString(json['status']),
quantity: asInt(json['quantity']),
```

`borrow_models.dart:253` already has `_parseDate`/`_parseInstant` doing exactly
this for dates, with a good comment on why a calendar date must not be parsed as
an instant. **Move those two into the shared file and keep the comment** — the
distinction is real and worth stating once for everyone.

This also closes the model-boundary gap in
`design-pattern-implementation.md` Finding 3, where `ApiService` hands back raw
maps and every caller re-parses them.

---

## Finding 7 — `profile_screen.dart` is 1,385 lines

**Severity 5/10.** The largest Dart file. It contains four `build()` methods
(lines 238, 719, 1229 and others) plus `_showProblemReport` (105 lines),
`_pickPhoto`, `_removePhoto`, `_setSmsOptIn`, `_showPhotoActions` — that is the
profile view, the photo management flow, the settings toggles and a problem-report
dialog in one file.

None of the individual functions is alarming; cohesion is the issue.

### Remediation

Split along the seams the `build()` methods already mark:

```
screens/profile/profile_screen.dart        the screen itself
screens/profile/profile_photo_actions.dart _pickPhoto/_removePhoto/_showPhotoActions
screens/profile/problem_report_sheet.dart  _showProblemReport
```

Low urgency — this is organisation, not a defect. **Do it when the file next
needs a change**, not as a standalone commit before a review.

---

## Finding 8 — duplicated card and row CSS, by the code's own admission

**Severity 4/10.**

```
/* Web/serbis-admin-vue/src/views/ConductionRequestView.vue:780 */
/* Mirrors ServiceRequestQueue.vue's .soft-card/.request-row exactly (same
```

Both files carry `<style scoped>` blocks — 316 lines in `ServiceRequestQueue.vue`
alone — and one of them documents that it is a copy of the other. A comment
saying "this is a duplicate" is a duplicate that someone already noticed and did
not have anywhere better to put.

### Remediation

Move the shared rules into one place and drop `scoped` for just those:

```
src/styles/cards.css   .soft-card, .request-row
```

imported once in `App.vue`. Keep genuinely local rules scoped in each file.

**Unable to verify** how much of the 316-line block is shared versus local — that
needs a line-by-line diff of the two `<style>` sections, which is worth doing
before moving anything.

---

## Finding 9 — 26 files over 300 lines

**Severity 3/10.** Recorded for completeness; mostly covered by Findings 2 and 7.

**Mobile — 15 files:** `profile_screen.dart` 1385, `shared_widgets.dart` 1078,
`api_service.dart` 862, `request_store.dart` 837, `request_models.dart` 824,
`safety_files.dart` 694, `borrow_equipment_screen.dart` 615, `main.dart` 615,
`register_screen.dart` 600, `services_screen.dart` 527, `library_screen.dart` 458,
`track_screen.dart` 403, `dashboard_screen.dart` 400, `service_widgets.dart` 372,
`translations.dart` 345.

**Panel — 11 files:** `ServiceRequestQueue.vue` 2297, `EquipmentBorrowingView.vue`
1277, `UsersView.vue` 1119, `ConductionRequestView.vue` 828, `DashboardView.vue`
803, `ServicesConfigView.vue` 615, `LoginView.vue` 594, `FilesView.vue` 542,
`VehiclesView.vue` 539, `StaffView.vue` 517, `EquipmentInventoryView.vue` 482.

Two of these are **not** problems and should not be "fixed":

- `safety_files.dart` (694) and `translations.dart` (345) are **data**, not logic.
  A long table of strings is exactly what they should be.

`shared_widgets.dart` at 1,078 lines is the one worth a second look — a
grab-bag filename usually means the contents have no single owner. Splitting it
by widget family would be a cheap improvement, but only alongside other work in
that file.

---

## What is already right

- **Nesting is genuinely shallow in mobile** — 2 functions of 270 reach depth 4,
  none deeper. Flutter code often nests badly; this does not.
- **The panel's median function is 8 lines.** The complexity is concentrated in a
  handful of places, not spread everywhere, which is what makes Findings 2 and 3
  tractable.
- **Zero unbracketable functions** across 461 — no `eval`-style dynamic
  construction, no generated code, no minified vendor blobs checked into `src/`.
- **`config/api.ts` deliberately throws rather than defaulting** when
  `VITE_API_BASE` is missing, and says why in a docblock. That is the right call
  and it is documented.
- **The support layer is disciplined** — `authToken.ts`, `apiSession.ts`,
  `residentStatus.ts`, `rowNumber.ts` are small, single-purpose, fully typed and
  well commented. The problems are all in the screens.

---

## Suggested order

1. **Finding 5** (minus the boolean rule) — 15 minutes, and it starts catching
   regressions immediately.
2. **Finding 3** — half a day, and it produces the panel's first unit-testable
   function.
3. **Finding 1, files 1–3** — one small view per commit, type errors fixed as
   they surface.
4. **Finding 6** — half a day, removes the densest logic in mobile.
5. **Finding 2** — multi-day. Schedule deliberately, after the type-checker is
   on for the neighbouring files.
6. **Findings 4, 7, 8, 9** — opportunistic, when those files are next touched.

**Do not** attempt Findings 1 and 2 in the same week as a review. Both are large
diffs across screens that currently have no test coverage in the panel at all.
