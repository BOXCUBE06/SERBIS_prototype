# Code Duplication Audit — SERBIS

**Scope:** `Backend/SERBIS-Backend` (Laravel 11), `Web/serbis-admin-vue` (Vue 3), `Mobile/` (Flutter).
**Date:** 2026-08-31 · **Commit:** `1fc04eb` (branch `update-admin-vue`)
**Method:** token-level extraction of all 119 backend function bodies, whitespace- and comment-normalised, hashed for exact matches and compared pairwise with `similar_text()` for near matches. Vue and Dart helpers located by identifier grep and diffed by hand. Scripts are throwaway; every figure below is reproducible from the cited file and line.

| Codebase | Lines | Files |
|---|---|---|
| Backend `app/` | 5,597 | 15 controllers, 11 models, 3 traits |
| Backend `tests/` | 6,825 | 34 feature tests |
| Web `src/` | 11,419 | 14 views, 3 components, 6 composables |
| Mobile `lib/` | 13,923 | — |
| Mobile `test/` | 8,410 | 34 test files |

---

## Summary

**Estimated duplication: ~4% of production code, ~9% of test code.**

That is low, and the reason is visible in the code: `API_BASE`, `useRowNumbers`, `authToken`, `PaginatesLists` and `CompletesAdminMfa` are all existing extractions. The team does factor things out. What is left falls into three buckets — a handful of genuinely large near-duplicate methods, a long tail of framework boilerplate that is not worth touching, and one deliberate duplication with the reasoning already written down.

**One caution before acting on anything here.** `Mobile/lib/state/borrow_cache.dart:18-24` carries a comment explaining that it mirrors `RequestCache` *on purpose* and why folding them together was rejected. Several other "duplicates" below are similarly deliberate. Read the comment above a block before deduplicating it.

| # | Finding | Importance |
|---|---|---|
| 1 | `store()` / `adminStore()` — 87% similar, ~110 lines each | **8/10** |
| 2 | Backend test fixtures — ~513 hand-rolled lines, no factories | **7/10** |
| 3 | `validId()` / `sitePhoto()` — 94.6% similar | **7/10** |
| 4 | `getHeaders` — 11 copies, 3 variants | **6/10** |
| 5 | `adminLoginVerify()` / `residentLoginVerify()` — 86.4% similar | **5/10** |
| 6 | `initials` — 4 copies, one behaviourally different | **5/10** |
| 7 | Borrow status vocabulary declared in 6 places | **5/10** |
| 8 | `privateDisk()` / `publicDisk()` — 3 copies | **4/10** |
| 9 | `notify` — 7 copies | **3/10** |
| 10 | CRUD `find`-or-404 boilerplate — 36 occurrences | **2/10** |
| 11 | Flutter cache classes — 71.4% similar | **2/10** (deliberate) |
| 12 | Dart test fakes — 20 hand-rolled `ApiService` subclasses | **2/10** |

---

## Finding 1 — `ServiceRequestController::store()` vs `adminStore()`

**Importance: 8/10** · Near duplicate · **87.1% similar, 2,557 / 3,140 normalised chars**

`app/Http/Controllers/ServiceRequestController.php:88` and `:333`. The largest duplication in the repository — roughly 110 lines each, sharing the same seven-stage pipeline: validate → resolve `scheduled_at` → store `valid_id` → store `site_photo` → open a transaction → create the row → claim a vehicle → clean up files on failure.

The genuine differences are only four:

| | `store()` | `adminStore()` |
|---|---|---|
| Owner | `$request->user()->getKey()` | `resident_id` or walk-in name/contact |
| `valid_id` | `required` | `nullable` |
| Upload path segment | resident key | `$residentId ?: 'walk-in'` |
| Extra fields | — | `walk_in_name`, `walk_in_contact_number` |

**Why it matters:** the compensating-delete logic — the block at `:43-48` that removes an orphaned government ID scan when the transaction fails — exists in both copies. A fix to one is a fix to only one. That block guards a real failure that the comment says "is not an edge case: it fires whenever the fleet is busy".

**Remediation:** extract the shared pipeline, parameterised by an owner descriptor.

```php
// ServiceRequestController
private function fileRequest(array $validated, string $ownerSegment, array $ownerColumns): ServiceRequest
{
    $scheduledAt   = $this->resolveScheduledAt($validated['scheduled_at'] ?? null);
    $filePath      = $this->storeUpload($validated, 'valid_id', 'valid-ids/'.$ownerSegment);
    $sitePhotoPath = $this->storeUpload($validated, 'site_photo', 'site-photos/'.$ownerSegment);

    try {
        return DB::transaction(fn () => $this->createRow($validated, $ownerColumns, $filePath, $sitePhotoPath, $scheduledAt));
    } catch (\Throwable $e) {
        $this->discardUploads([$filePath, $sitePhotoPath]);   // one copy, not two
        throw $e;
    }
}

public function store(Request $request)
{
    $validated = $request->validate([...]);   // rules stay per-endpoint

    return response()->json(
        $this->fileRequest($validated, (string) $request->user()->getKey(), ['resident_id' => $request->user()->getKey()]),
        201
    );
}
```

Keep the two `validate()` calls where they are — the rules genuinely differ, and merging them behind a flag is how `valid_id` stops being required for residents.

**Effort:** ~3 hours, plus running `WalkInServiceRequestTest`, `ScheduledServiceRequestTest` and `ServiceRequestDispatchTest`, which already cover both paths.

---

## Finding 2 — Backend test fixtures are hand-rolled 90 times

**Importance: 7/10** · Structural duplication · **~513 lines**

`grep -r "Resident::create(\[" tests/` → **31** occurrences; `User::create([` → **25**; `Barangay::create([` → **34**. Each Resident block is the same eight keys with the same values:

```php
$this->resident = Resident::create([
    'barangay_id' => $barangay->barangay_id,
    'first_name' => 'Maria',
    'last_name' => 'Santos',
    'phone_number' => '09171111111',
    'email_address' => 'maria@test.local',
    'password' => Hash::make('Password123'),
    'status' => 'Active',
]);
```
*(`tests/Feature/ServiceRequestApproveTest.php:52`, and 30 near-identical siblings.)*

`database/factories/` contains **only** `ServiceRequestFactory.php`, so the pattern is established but unapplied. `tests/TestCase.php` is an empty stub.

**Why it matters:** the `status` column was tightened to `in:Active,Inactive,Deactivated` at `ResidentController.php:50`. A future column with a `NOT NULL` default and no fixture value means editing 31 files. This is also the single largest maintenance cost in the repo by line count.

**Remediation:**

```php
// database/factories/ResidentFactory.php
class ResidentFactory extends Factory
{
    protected $model = Resident::class;

    public function definition(): array
    {
        return [
            'barangay_id'   => Barangay::factory(),
            'first_name'    => fake()->firstName(),
            'last_name'     => fake()->lastName(),
            'phone_number'  => '09'.fake()->numerify('#########'),
            'email_address' => fake()->unique()->safeEmail(),
            'password'      => Hash::make('Password123'),
            'status'        => 'Active',
        ];
    }

    public function deactivated(): static
    {
        return $this->state(fn () => ['status' => 'Deactivated']);
    }
}
```

Call sites become `Resident::factory()->create()` or `Resident::factory()->deactivated()->create()`.

**Effort:** ~1 hour to write three factories (`Resident`, `User`, `Barangay`); ~4 hours to migrate 34 test files, mechanical and individually verifiable. Worth doing incrementally — a factory and its old call sites can coexist.

---

## Finding 3 — `validId()` vs `sitePhoto()`

**Importance: 7/10** · Near duplicate · **94.6% similar, 515 / 523 chars**

`app/Http/Controllers/ServiceRequestController.php:473` and `:499`. Twenty-four lines each; the entire difference is the column name and one message string.

**Why it matters:** these are the two endpoints that serve government ID scans and photographs of residents' homes. They share an ownership guard, a deliberate 404-instead-of-403 so existence is not disclosed, and an existence check. Any change to that guard has to be made twice, and Finding 3 of `audits/input-validation.md` (the missing `nosniff` header) is a change that would need making in both.

**Remediation:**

```php
private function servePrivateColumn(Request $request, $id, string $column, string $label)
{
    $query = ServiceRequest::query();

    if ($refusal = $this->guardPrivateFile($request, $query)) {
        return $refusal;
    }

    $serviceRequest = $query->find($id);

    // 404 rather than 403 for a non-owner, so the response does not disclose
    // that the request exists.
    if (! $serviceRequest || ! $serviceRequest->{$column}) {
        return response()->json(['message' => 'Service request not found'], 404);
    }

    if (! Storage::disk(self::privateDisk())->exists($serviceRequest->{$column})) {
        return response()->json(['message' => "{$label} file not found"], 404);
    }

    return Storage::disk(self::privateDisk())->response($serviceRequest->{$column});
}

public function validId(Request $request, $id)   { return $this->servePrivateColumn($request, $id, 'valid_id', 'Valid ID'); }
public function sitePhoto(Request $request, $id) { return $this->servePrivateColumn($request, $id, 'site_photo', 'Site photo'); }
```

`$column` is a hardcoded literal at both call sites, never request input, so the dynamic property access introduces no injection surface.

**Effort:** ~30 minutes. `PrivateFileAccessTest` already covers both endpoints.

---

## Finding 4 — `getHeaders` declared in 11 files, in 3 variants

**Importance: 6/10** · Exact and near duplication

| File | Line | Variant |
|---|---|---|
| `src/components/ServiceRequestQueue.vue` | 1549 | quoted keys, `Content-Type` |
| `src/views/ConductionRequestView.vue` | 462 | unquoted, `Content-Type` |
| `src/views/EquipmentBorrowingView.vue` | 939 | unquoted, `Content-Type` |
| `src/views/EquipmentInventoryView.vue` | 219 | one-line, `Content-Type` |
| `src/views/FilesView.vue` | 298 | **no `Content-Type`** — multipart upload |
| `src/views/LogsView.vue` | 175 | **no `Content-Type`** |
| `src/views/ServicesConfigView.vue` | 368 | one-line |
| `src/views/SmsView.vue` | 147 | multi-line |
| `src/views/StaffView.vue` | 284 | multi-line |
| `src/views/UsersView.vue` | 564 | one-line |
| `src/views/VehiclesView.vue` | 263 | one-line |

The `FilesView` variant is correct and deliberate — setting `Content-Type` by hand on a multipart request stops the browser generating the boundary. That is why the replacement below takes a flag rather than assuming JSON.

**Remediation:** `authHeaders(json = true)` in the new `src/composables/adminUi.ts` (created by this audit).

```ts
import { authHeaders } from '@/composables/adminUi'
// JSON call:      authHeaders()
// Multipart call: authHeaders(false)
```

**Effort:** ~15 minutes per view, 11 views, each independently testable. ~2.5 hours total.

---

## Finding 5 — `adminLoginVerify()` vs `residentLoginVerify()`

**Importance: 5/10** · Near duplicate · **86.4% similar, 993 / 980 chars**

`app/Http/Controllers/AuthController.php:396` and `:599`. Shared: validation, `consumeMfaChallengeAttempt()`, the not-found/expired branch, the token response shape. Different, and *legitimately* so:

- admin verifies through `Totp::verify()`; resident through `Hash::check()` against `code_hash`
- admin calls `markEnrolled()`; resident does not
- different failure copy — the admin's names an authenticator app, the resident's mentions expiry

**Assessment:** the differences are the security-relevant half. A shared method with an `if ($isAdmin)` inside the credential check would be *harder* to audit, not easier, and this is the code path an attacker cares most about. **Recommend extracting only the surrounding envelope** — the challenge lookup and the response shaping — and leaving each verification branch spelled out.

Related and smaller: `issueAdminToken()` (`:438`) and `issueResidentToken()` (`:447`) are **89.7% similar**, 127/136 chars. Those differ only in the token name and abilities and can be merged safely.

**Effort:** ~1 hour for the token helpers. The verify methods are a judgement call — my recommendation is to leave them.

---

## Finding 6 — `initials` in 4 places, one behaving differently

**Importance: 5/10** · Near duplicate with a live inconsistency

| File | Line | Uppercased? |
|---|---|---|
| `src/components/ResidentDetailPanel.vue` | 185 | ✅ |
| `src/views/StaffView.vue` | 293 | ✅ |
| `src/views/UsersView.vue` | 550 | ✅ |
| `src/views/EquipmentBorrowingView.vue` | 859 | ❌ **no `.toUpperCase()`** |

**Why it matters:** this is not hypothetical drift, it is drift that already happened. The same resident's avatar renders `MS` on the Residents page and `mS` on Equipment Borrowing. Nothing catches it because each copy is locally correct.

**Remediation:** `initials()` in `src/composables/adminUi.ts`. Note that adopting it **changes** the Equipment Borrowing avatars — that is the fix, but it is a visible change, so land it deliberately rather than as a silent refactor.

**Effort:** ~20 minutes.

---

## Finding 7 — The borrow status vocabulary is declared in 6 places

**Importance: 5/10** · Data duplication, cross-codebase

The five values `Pending / Approved / Released / Returned / Denied` appear in:

1. `database/migrations/2026_06_22_042205_create_tbl_equipment_borrowing_table.php:16` — the `enum` column
2. `app/Http/Controllers/EquipmentBorrowingController.php:123` — `in:` validation rule
3. `app/Http/Controllers/EquipmentBorrowingController.php:26-30` — the `TRANSITIONS` map
4. `database/seeders/MockRequestSeeder.php:70` — `$borrowStatuses`
5. `Web/serbis-admin-vue/src/views/EquipmentBorrowingView.vue:605+` — the `columns` array
6. `Mobile/lib/models/borrow_models.dart:37` + `borrowStatusFromText():39-52` — enum and parser

**Assessment:** three languages and a database cannot share one declaration, so **items 5 and 6 are unavoidable**. Items 1–4 are all PHP and can share one source of truth.

**Remediation:**

```php
// app/Enums/BorrowStatus.php
enum BorrowStatus: string
{
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Released = 'Released';
    case Returned = 'Returned';
    case Denied = 'Denied';

    /** Which status each status may move to. Returned and Denied are terminal. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending  => [self::Approved, self::Denied],
            self::Approved => [self::Released, self::Denied],
            self::Released => [self::Returned],
            self::Returned, self::Denied => [],
        };
    }
}
```

Validation becomes `Rule::enum(BorrowStatus::class)`; the migration keeps its literal list, because a migration must describe the schema as it was on the day it ran and must not change meaning when an enum is edited later.

**Effort:** ~2 hours including `EquipmentBorrowingTransitionTest`. The service-request vocabulary (`Pending / Responding / Resolved`) has the same shape and is a second, identical piece of work.

---

## Finding 8 — `privateDisk()` / `publicDisk()` in 3 controllers

**Importance: 4/10** · Exact duplicate

`ResidentController.php:16`, `ServiceRequestController.php:273` (both `privateDisk`), `InfoMaterialController.php:81` (`publicDisk`). One line each.

**Why it is worth the 4 rather than a 1:** the private disk is where government ID scans go. If that config key is renamed and a copy is missed, `config()` returns null, `Storage::disk(null)` silently falls back to the **default** disk, and ID photos start landing somewhere unintended with no error raised.

**Remediation:** `app/Traits/ResolvesUploadDisks.php` (created by this audit). Add `use ResolvesUploadDisks;` to the three controllers and delete the local copies. Note the trait declares them `protected static` where the originals were `private static` — required for a trait, and no call site is outside the class.

**Effort:** ~15 minutes.

---

## Finding 9 — `notify` in 7 views

**Importance: 3/10** · Exact duplicate

`ConductionRequestView.vue:444`, `EquipmentBorrowingView.vue:654`, `EquipmentInventoryView.vue:218`, `FilesView.vue:296`, `ServicesConfigView.vue:367`, `UsersView.vue:555`, `VehiclesView.vue:262`. Every one is byte-identical modulo line breaks:

```js
const notify = (text, color = 'success') => { snackbar.value = { show: true, text, color } }
```

Low importance because it is one line, has not drifted, and each copy needs a matching `snackbar` ref anyway.

**Remediation:** `useSnackbar()` in `adminUi.ts` returns the ref and the function together, so a view cannot declare one without the other.

**Effort:** ~10 minutes per view.

---

## Finding 10 — CRUD `find`-or-404 boilerplate

**Importance: 2/10** · Structural duplication · 36 occurrences across 9 controllers

`destroy()` is 84–92% similar across `ServiceController:59`, `VehicleController:90`, `EquipmentController:85`, `ResidentController:134` and `ServiceRequestController:997`. `show()` clusters similarly at 70–83%.

```php
public function destroy($id)
{
    $service = Service::find($id);

    if (!$service) {
        return response()->json(['message' => 'Service not found'], 404);
    }

    $service->delete();

    return response()->json(['message' => 'Service successfully deleted']);
}
```

**Assessment: recommend NOT extracting this.** It is Laravel's standard resource-controller shape. A `BaseCrudController` would save ~40 lines and cost every reader an indirection on the most-visited methods in the codebase — and several of these are *not* uniform: `ServiceRequestController::destroy()` cleans up files, `InfoMaterialController::destroy()` deletes from storage, `AdminController::destroy()` refuses two specific deletions. Route-model binding (`Service $service` instead of `$id`) would remove the 404 block idiomatically with no new abstraction, and is the change worth making if any is.

**Effort:** ~2 hours for route-model binding across the resource controllers; zero if left alone. Both are defensible.

---

## Finding 11 — `BorrowCache` vs `RequestCache` (deliberate)

**Importance: 2/10** · Near duplicate · **71.4% similar**

`Mobile/lib/state/borrow_cache.dart` (87 lines) and `Mobile/lib/state/request_cache.dart` (106 lines). Same `save` / `load` / `clear` shape over `shared_preferences`, differing in the model type and the two storage keys.

**This is already documented as intentional.** `borrow_cache.dart:18-24`:

> *"Mirrors `RequestCache` exactly … Kept as a separate store rather than folded into it: the two lists are unrelated records with different shapes, and a shared key would mean a version that reads one has to be able to read both."*

**Assessment:** the stated reason argues against sharing a *key*, which a generic class would not require — `CacheStore<T>` parameterised by key and codec keeps the keys separate. But the benefit is ~60 lines against a real risk of breaking the offline path, which is the feature the project owner has called crucial. **Recommend leaving it.** Revisit only if a third cache of the same shape appears; two is not yet a pattern.

Related, same file family: `AppState.hydrateBorrowRequests()` vs `hydrateRequests()` are **81.7% similar** (`request_store.dart:285` and `:603`), and `loadBorrowRequests()` vs `loadRequests()` **67.7%** (`:312`, `:643`). Same reasoning applies.

---

## Finding 12 — 20 hand-rolled `ApiService` fakes in Dart tests

**Importance: 2/10** · Structural duplication

`grep -rn "extends ApiService" Mobile/test/` → 20 classes across 19 files, named variously `_FakeApi`, `_RecordingApi`, `FakeApi`, `_FakeAuthApi`, `_FakeVerifyApi`.

**Assessment:** each overrides a different subset of methods for a different test, which is what a hand-rolled fake is *for*. A shared base fake would need every method stubbed and would couple 19 test files to one class — a change to it breaks all of them at once. The naming inconsistency is worth fixing (`_FakeApi` everywhere); the classes are not.

**Remediation:** none recommended beyond naming. If the boilerplate ever does bite, `mocktail` is the idiomatic answer, not a shared base class.

---

## Utilities modules created by this audit

Two files, both written to match existing behaviour exactly, both currently **unimported**:

| File | Contents | Replaces |
|---|---|---|
| `Web/serbis-admin-vue/src/composables/adminUi.ts` | `authHeaders()`, `useSnackbar()`, `initials()`, `fmtDate()`, `fmtDateTime()` | Findings 4, 6, 9 |
| `Backend/SERBIS-Backend/app/Traits/ResolvesUploadDisks.php` | `privateDisk()`, `publicDisk()` | Finding 8 |

Both compile (`vue-tsc` clean, `php -l` clean) and the backend suite still passes 277/277. **Neither is wired into a call site** — migrating 11 views and 3 controllers is a separate, reviewable change, and half-migrating would leave two competing patterns, which is worse than one duplicated one. Delete either file if the migration is not going to happen.

`fmtDate` / `fmtDateTime` are included because `ConductionRequestView` and `EquipmentBorrowingView` each declare their own; the versions in `adminUi.ts` add explicit null handling those two lack, so adopting them is a small behaviour change (`—` instead of `Invalid Date`).

---

## Recommended order

1. **Finding 3** — `validId`/`sitePhoto`, 30 minutes, security-relevant, fully covered by existing tests.
2. **Finding 8** — disk trait, 15 minutes, trait already written.
3. **Finding 1** — `store`/`adminStore`, the single largest win, and it removes a duplicated failure-cleanup path.
4. **Finding 2** — model factories, highest line-count saving, safe to do incrementally.
5. **Finding 4 + 6 + 9** together — one pass per Vue view, since all three touch the same files.
6. **Finding 7** — status enum, if the panel review is likely to ask about magic strings.

Leave Findings 5 (verify methods), 10, 11 and 12 alone, for the reasons given in each.

---

## Unable to verify

- **Duplication inside `.vue` templates.** The percentages above cover `<script setup>` blocks only. The `<template>` sections are large — `EquipmentBorrowingView.vue` is 1,246 lines — and visibly share table, dialog and filter-chip structures across views, but comparing them needs an SFC-aware parser, not `similar_text` over raw text, which would score any two Vuetify templates as similar regardless of intent. What would settle it: `jscpd --format vue` or `vue-tsc` AST extraction over `src/views/`.
- **Cross-codebase model-shape duplication.** `Resident`, `ServiceRequest` and `EquipmentBorrowing` are each declared three times — a PHP model, a Dart model, a set of Vue property accesses. Whether that is reducible depends on whether generating the Dart models from an OpenAPI spec is in scope, which is a project decision rather than an audit finding.
- **Dart production-code duplication beyond `lib/state/`.** `lib/screens/` and `lib/widgets/` total ~10,000 lines and were not machine-compared; the analysis there was limited to the state layer and the models the store touches. What would prove it: a Dart AST pass equivalent to the PHP one used here.
