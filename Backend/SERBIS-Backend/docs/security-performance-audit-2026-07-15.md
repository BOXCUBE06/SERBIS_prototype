# Laravel Backend Security & Performance Audit

**Date:** 2026-07-15
**Scope:** `Backend/SERBIS-Backend` — all controllers, models, routes, middleware, migrations, and config touched by the API.
**Status:** **19 of 28 findings fixed and verified live** (updated 2026-07-16): #1–#9, #12, #13, #16, #17, #20, #21, #28, plus the #14/#18/#22 registration cluster. Every fix was exercised for real — IDOR probes from a non-owner, a `resident_id` spoof attempt, a forced insufficient-stock 422 checked against `information_schema.innodb_trx`, rapid-login throttle probes, CORS preflights, `EXPLAIN` with an `IGNORE INDEX` control, faked-HTTP blast targeting, a `config:cache` round-trip, a path-traversal `PUT`, and a real browser with `fetch` stubbed.

**Open:** #10, #11, #15, #23 — all Medium/Low. **No Critical or High findings remain open.**

**#8 and #28 fixed 2026-07-16** (`c66a978`). #28 is new: `update()` accepted `valid_id` as a free-form string, which #8's streaming route would have turned into admin-to-arbitrary-file-read. It had to ship in the same commit — **the #8 fix sketch in this very document would have introduced it.**

> **Committed 2026-07-16 — the fixes had been sitting uncommitted for a day.** `7b2a5bc` ("Add security/performance audit log") committed *this document only*: one file, 736 insertions, **zero code**, while every fix it describes stayed in the working tree. Discovered and resolved 2026-07-16: the whole set now sits in HEAD across nine commits (`c66a978`, `89ac79e`, `9358b9a`, `55839b7`, `b37d89d`, `e249c09`, `46aac35`, `6ef5efe`, `9672db8`). **Nothing has been pushed** — `origin/update-admin-vue` is still behind, so this document has never been public. See "Status at 2026-07-16" at the end.

**Six findings — #17 through #22 — were discovered while fixing and verifying, not during the original read-only pass.** That ratio is the strongest argument in this document for exercising changes rather than reading them.

**Deploy blockers:** `ADMIN_FRONTEND_URL` must be set or CORS fails closed · the admin panel **cannot be built for production at all** (#22) · SkySMS bulk send is **unverified against the live vendor** — no sandbox exists, needs a manual burner-number test (#16).

**Verification lesson (recorded deliberately):** the first pass at #5 was applied straight from this document's own fix snippet, which was wrong, and it took the entire API down — every route 500'd, worse than the bug it fixed. `php -l` reported clean throughout and meant nothing. Findings in this report are hypotheses about a running system; treat a fix as unverified until it has been exercised end-to-end.

Findings are ordered by severity, security and performance mixed together.

---

## CRITICAL

### 1. IDOR — any resident can list every other resident's equipment borrowings
**File:** `app/Http/Controllers/EquipmentBorrowingController.php:12-17`

```php
public function index()
{
    $borrowings = EquipmentBorrowing::with(['resident.barangay', 'equipment'])->orderBy('created_at', 'desc')->get();
    return response()->json($borrowings);
}
```

**What it is:** `GET /borrowings` sits only behind `auth:sanctum` (`routes/api.php:30`), not `is.admin`. The method returns **every** borrowing record in the system with no filter on the caller's identity.

**Why it matters here:** Any resident who registers through the mobile app (a public, unauthenticated action) gets a valid token and can immediately pull every other resident's name, barangay, and borrowed-item history. This is real personal data about disaster-relief recipients, not test data.

**Fix:**
```php
public function index(Request $request)
{
    $user = $request->user();

    $query = EquipmentBorrowing::with(['resident.barangay', 'equipment'])->orderBy('created_at', 'desc');

    if ($user instanceof \App\Models\Resident) {
        $query->where('resident_id', $user->getKey());
    }

    return response()->json($query->get());
}
```
(Admins keep seeing everything; residents see only their own — mirrors the pattern already used correctly in `ServiceRequestController::index()`.)

**Effort:** 15 min.

---

### 2. IDOR — any resident can view any other resident's service request (incl. their ID photo)
**File:** `app/Http/Controllers/ServiceRequestController.php:94-104`

```php
public function show($id)
{
    $serviceRequest = ServiceRequest::with(['resident.barangay', 'service', 'admin'])->find($id);
    ...
    return response()->json($serviceRequest);
}
```

**What it is:** `GET /service-requests/{id}` has no check that `$id` belongs to the authenticated resident. `index()` correctly filters by `resident_id` for non-admins, but `show()` was never given the same treatment — a resident just has to increment the ID in the URL.

**Why it matters here:** The response includes `valid_id`, the path to a scanned government ID photo (see #8). This turns a straightforward IDOR into a PII/ID-document leak.

**Fix:**
```php
public function show(Request $request, $id)
{
    $user = $request->user();

    $query = ServiceRequest::with(['resident.barangay', 'service', 'admin']);

    if ($user instanceof \App\Models\Resident) {
        $query->where('resident_id', $user->getKey());
    }

    $serviceRequest = $query->find($id);

    if (!$serviceRequest) {
        return response()->json(['message' => 'Service request not found'], 404);
    }

    return response()->json($serviceRequest);
}
```

**Effort:** 15 min.

---

### 3. IDOR — any resident can view any other resident's borrowing record
**File:** `app/Http/Controllers/EquipmentBorrowingController.php:37-47`

Same bug as #2, same fix pattern:
```php
public function show(Request $request, $id)
{
    $user = $request->user();
    $query = EquipmentBorrowing::with(['resident.barangay', 'equipment']);

    if ($user instanceof \App\Models\Resident) {
        $query->where('resident_id', $user->getKey());
    }

    $borrowing = $query->find($id);
    ...
}
```

**Effort:** 15 min.

---

### 4. Resident password hash and OTP are returned in API responses
**File:** `app/Models/Resident.php` (whole file — no `$hidden` property or `#[Hidden(...)]` attribute)

Contrast with `app/Models/User.php:16`, which correctly has:
```php
#[Hidden(['password', 'remember_token'])]
```
`Resident` has nothing equivalent. Every endpoint that serializes a `Resident` model leaks the bcrypt hash and OTP in plain JSON:
- `AuthController::register()` — `app/Http/Controllers/AuthController.php:33` (`'user' => $resident`)
- `AuthController::residentLogin()` — `app/Http/Controllers/AuthController.php:80`
- `ResidentController::index/store/show/update` — `app/Http/Controllers/ResidentController.php:13,36,47,77`

**Why it matters here:** Every login and every admin resident-list call ships the bcrypt hash and the (still-present) OTP field to the client. Anyone who can see network traffic — browser devtools, a proxy log, a leaky mobile analytics SDK — gets material for offline password cracking, and the OTP field is a live credential if OTP verification is still used anywhere in the auth flow.

**Fix:**
```php
// app/Models/Resident.php
use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Table('tbl_residents', key: 'resident_id')]
#[Fillable([...])]
#[Hidden(['password', 'otp', 'remember_token'])]
class Resident extends Authenticatable
{
```

**Effort:** 5 min.

---

### 5. No rate limiting anywhere in the API
**File:** `bootstrap/app.php:15-19`

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'is.admin' => \App\Http\Middleware\IsAdmin::class,
    ]);
})
```

**What it is:** Laravel's `api` middleware group only gets `throttle:api` attached if `->throttleApi()` is called here (confirmed in `vendor/laravel/framework/.../Middleware.php:495-499` — the throttle entry is conditional on `$this->apiLimiter`, which is never set). It isn't called, so **every** route — `/admin/login`, `/resident/login`, `/register`, `/sms/blast` — accepts unlimited requests per second.

**Why it matters here:**
- `/admin/login` and `/resident/login` (`AuthController.php:39,62`) are wide open to credential-stuffing/brute-force with no lockout.
- `/sms/blast` (`SmsController.php:17`) makes a real, billed call to SkySMS per request. With zero rate limiting, one authenticated admin token (or a leaked one) can be scripted to fire this endpoint thousands of times per minute — a direct financial-abuse vector, and right now there is nothing stopping it.
- `/register` can be spammed to create junk resident accounts.

**Fix:**
```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->throttleApi('60,1'); // literal limit: 60 req/min per user or IP
    $middleware->alias([
        'is.admin' => \App\Http\Middleware\IsAdmin::class,
    ]);
})
```

> **Correction (2026-07-15).** This snippet originally read `$middleware->throttleApi()` with no argument and a comment claiming a 60/min default. That is wrong for this codebase and was applied verbatim before being caught: the bare call attaches `throttle:api`, a **named** limiter that only exists if something registers `RateLimiter::for('api', ...)`. Laravel 10 shipped that in `RouteServiceProvider`; this is a Laravel 11/12 skeleton with no such provider and no registration, so the middleware throws `MissingRateLimiterException` and **every API route returns 500**. `php -l` passes throughout — an undefined runtime limiter is valid PHP. Passing the literal `'60,1'` avoids the named-limiter lookup entirely.
Then tighten the sensitive endpoints specifically in `routes/api.php`:
```php
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:5,1');
Route::post('/resident/login', [AuthController::class, 'residentLogin'])->middleware('throttle:5,1');
...
Route::post('/sms/blast', [SmsController::class, 'sendBlast'])->middleware('throttle:3,60'); // 3 blasts/hour
```

**Effort:** 30 min.

---

## HIGH

### 6. `EquipmentBorrowingController::store()` trusts a client-supplied `resident_id`
**File:** `app/Http/Controllers/EquipmentBorrowingController.php:19-35`

```php
$validated = $request->validate([
    'resident_id' => 'required|exists:tbl_residents,resident_id',
    ...
]);

$borrowing = EquipmentBorrowing::create([
    'resident_id' => $validated['resident_id'],
    ...
]);
```

**Why it matters here:** `resident_id` comes straight from the request body instead of `$request->user()->getKey()`. Any authenticated resident can submit a borrow request *as* any other resident (just needs a valid `resident_id`, which is a small sequential integer). Compare to `ServiceRequestController::store()` (`ServiceRequestController.php:71`), which does this correctly with `$request->user()->getKey()`.

**Fix:**
```php
$validated = $request->validate([
    'equipment_id' => 'required|exists:tbl_equipments,equipment_id',
    'quantity' => 'required|integer|min:1',
]);

$borrowing = EquipmentBorrowing::create([
    'resident_id' => $request->user()->getKey(),
    'equipment_id' => $validated['equipment_id'],
    'quantity' => $validated['quantity'],
    'status' => 'Pending',
]);
```

**Effort:** 10 min.

---

### 7. Dangling open DB transaction on the insufficient-stock path
**File:** `app/Http/Controllers/EquipmentBorrowingController.php:63-97`

```php
DB::beginTransaction();
try {
    if ($newStatus === 'Released' && $oldStatus !== 'Released') {
        $equipment = Equipment::lockForUpdate()->find($borrowing->equipment_id);
        if ($equipment->available_quantity < $borrowing->quantity) {
            return response()->json(['message' => 'Not enough equipment available to release.'], 422); // <-- no rollBack()
        }
        ...
    }
    ...
    DB::commit();
    return response()->json($borrowing);
} catch (\Exception $e) {
    DB::rollBack();
    ...
}
```

**Why it matters here:** The early `return` on line 70 exits the method from inside the `try` block without throwing, so the `catch`'s `DB::rollBack()` never runs and `DB::commit()` is never reached either. The transaction — and the row lock taken by `lockForUpdate()` on that `Equipment` row — stays open for the rest of the PHP-FPM worker's lifetime (or, on a persistent worker like Octane, bleeds into the next request on that worker). Under concurrent borrow-release attempts this causes lock contention and unpredictable data state, not just a stray connection.

**Fix:**
```php
if ($equipment->available_quantity < $borrowing->quantity) {
    DB::rollBack();
    return response()->json(['message' => 'Not enough equipment available to release.'], 422);
}
```

**Effort:** 10 min.

---

### 8. Government ID photos are stored on the public disk with no access control
**File:** `app/Http/Controllers/ServiceRequestController.php:50-51`, `config/filesystems.php:41-48`

```php
if ($request->hasFile('valid_id')) {
    $filePath = $request->file('valid_id')->store('ids', 'public');
}
```

**Why it matters here:** The `public` disk is symlinked to `public/storage` and served directly by the webserver — no auth, no signed URL, nothing. Combined with finding #2 (IDOR on `show()`), any authenticated resident can pull another resident's `valid_id` path from the API and then fetch the actual ID-document image with a plain unauthenticated GET. Scanned government IDs are about as sensitive as data gets for this app; they shouldn't be reachable without a permission check even if the direct filename is hard to guess.

**Fix:** store on the private disk and serve through an authorized, ownership-checked route instead of a public URL.
```php
// store
$filePath = $request->file('valid_id')->store('ids', 'local'); // private disk

// new route, admin or owning-resident only
Route::get('/service-requests/{id}/valid-id', [ServiceRequestController::class, 'showValidId'])
    ->middleware('auth:sanctum');

// controller
public function showValidId(Request $request, $id)
{
    $serviceRequest = ServiceRequest::findOrFail($id);
    $user = $request->user();

    abort_unless(
        ($user instanceof \App\Models\User) || $serviceRequest->resident_id === $user->getKey(),
        403
    );

    return Storage::disk('local')->response($serviceRequest->valid_id);
}
```

**Effort:** 1-2 hours (includes migrating already-uploaded files and updating the frontend to call the new endpoint instead of rendering a direct URL).

**FIXED + verified live 2026-07-16** (`c66a978`). Shipped shape, with three deliberate departures from the sketch above:

```php
// store() — private disk, uuid per file, resident-scoped directory
$file = $request->file('valid_id');
$filePath = $file->storeAs(
    'valid-ids/'.$request->user()->getKey(),
    (string) Str::uuid().'.'.$file->extension(),
    'local'
);
```

1. **404, not `abort_unless(..., 403)`.** A 403 confirms the request exists; `validId()` scopes the query *before* `find()`, exactly as #2's fix does, so a non-owner is told nothing. The sketch also called `findOrFail()` before its check, which discloses existence via 404-vs-403 either way.
2. **`{uuid}.{ext}` under a per-resident directory**, not a flat `ids/`. The extension comes from `$file->extension()` (derived from content), never the client-supplied filename.
3. **The path is hidden at the model layer**, which the sketch missed. `store()` on the private disk is pointless while `valid_id` is still serialized in every `index`/`show`/`adminIndex` response. `ServiceRequest` now carries `#[Hidden(['valid_id'])]` + `#[Appends(['has_valid_id'])]`; clients get a boolean and fetch bytes from the route.

**Route keying — by request id, not resident id.** `valid_id` is a column on `tbl_service_request`, so a resident with N requests has N photos; a `/valid-ids/{residentId}` route cannot say which one. `GET /api/service-requests/{id}/valid-id` (as this sketch originally proposed) is the only unambiguous key, and it makes the ownership check identical to `show()`.

**No data migration was needed** — the audit assumed one. Every one of the 50 existing rows had `valid_id = null` (`ServiceRequestSeeder.php:26`), and the three files under `storage/app/public/ids/` were **byte-identical duplicates of one test image** (`sha1 d6f8a66b…`) referenced by **zero** rows. They were unreferenced orphans and were deleted, not moved.

**Verified live** (two-resident discipline, resident A = 1, B = 2, against a real upload):

| Test | Result |
|---|---|
| B → A's ID | **404**, 39 bytes JSON, no image bytes |
| A → own | **200** `image/jpeg`, sha1 matches source file exactly |
| Admin → A's | **200**, same sha1 |
| Unauthenticated | **401** |
| `valid_id` in JSON (4 endpoints) | absent everywhere; `has_valid_id` present |
| Old `/storage/ids/...` URL | **403**, HTML error page (`3c21`, not JPEG's `ffd8`) — no bytes served |

The old public URL returns **403 rather than 404** under `php artisan serve`; either way no file is served, and `storage/app/public/` now holds only `.gitignore`.

**Frontend** (`ManageRequestView.vue`): renders on `has_valid_id`, fetches the route with the existing `getHeaders()` and holds the image as a blob URL, revoked on request-switch and on unmount. Verified in a real browser: decoded 1200x801, exactly one `GET .../54/valid-id → 200` and **zero `/storage/` requests**; 6 switch cycles left all 6 prior blobs revoked and exactly one alive.

> **Keeping `Accept: application/json` on that fetch is load-bearing.** Without it an expired token makes Laravel redirect to a nonexistent `login` route and return **500**, not 401.

**This fix required #28** (below) to be closed in the same pass — without it, the new streaming route would have been strictly worse than the bug it fixes.

---

### 9. CORS allows any origin with any header
**File:** `config/cors.php:18-32`

```php
'allowed_origins' => ['*'],
'allowed_headers' => ['*'],
```

**Why it matters here:** This is a Bearer-token API (not cookie/session based, `supports_credentials => false`), which limits the blast radius somewhat — a malicious site can't ride the browser's cookies. But it still means any website can make authenticated cross-origin calls on behalf of a user who has a token sitting in `localStorage`/JS-reachable storage (which is how a Sanctum SPA token typically lives client-side) if that site can get script execution anywhere near the token — e.g. an XSS bug on the admin panel becomes trivially exfiltratable to an attacker-controlled origin instead of being contained by CORS. There's no reason a government MDRRMO admin panel and mobile app need the API open to arbitrary origins.

**Fix:**
```php
'allowed_origins' => [
    env('ADMIN_FRONTEND_URL', 'https://admin.yourdomain.gov.ph'),
],
'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
```

**Effort:** 10 min.

---

### 17. Throttle limits are IP-keyed and double-counted — CGNAT lockout risk
**Files:** `bootstrap/app.php:16`, `routes/api.php:18-20`
**Found:** 2026-07-15, during live verification of #5 (not visible by reading the code).

Two separate defects, both measured against a running server.

**17a — every request burns two tokens.** Observed on six rapid `POST /api/admin/login`:

```
req 1 -> HTTP 401 | limit=5 remaining=3
req 2 -> HTTP 401 | limit=5 remaining=1
req 3 -> HTTP 429 | limit=5 remaining=0 | retry-after=59s
```

`remaining` drops by 2 per request. The `api` group's `throttle:60,1` and the route's `throttle:5,1` resolve to the **same cache key**, so both middleware instances call `hit()` on one counter. Effective limit on `/admin/login` is ~2/min, not the intended 5/min.

**17b — all guest routes share one bucket per IP.** A single request to `/resident/login` dropped `/admin/login`'s budget from 5 to 1. The cause is Laravel's own signature resolution (`vendor/laravel/framework/.../ThrottleRequests.php`):

```php
protected function resolveRequestSignature($request)
{
    if ($user = $request->user()) {
        return $this->formatIdentifier($user->getAuthIdentifier());
    } elseif ($route = $request->route()) {
        return $this->formatIdentifier($route->getDomain().'|'.$request->ip());
    }
    ...
```

For unauthenticated callers the key is `sha1(domain|ip)` — **the route is not part of it**. So `/register`, `/admin/login` and `/resident/login` share a single bucket per IP; combined with 17a that is ~2 requests/min per IP across all three.

**Why it matters here:** PH mobile carriers use CGNAT heavily, so an entire carrier's subscribers can share one public IP. Roughly two login attempts per minute for that whole pool would lock residents out during exactly the disaster response this system exists for. This is an **availability** risk. Note the direction of the error: it is *more* restrictive than intended, so it fails safe against brute force and fails badly for legitimate users.

**Fix — applied and verified 2026-07-15.** The auth limiters are now keyed by submitted credential rather than IP, via a named limiter registered in `AppServiceProvider::boot()`:
```php
RateLimiter::for('login', function (Request $request) {
    $email = Str::lower((string) $request->input('email_address'));

    return [
        Limit::perMinute(5)->by('email:'.$email.'|'.$request->ip()),
        Limit::perMinute(20)->by('ip:'.$request->ip()),
    ];
});
```
`/register`, `/admin/login` and `/resident/login` moved from `throttle:5,1` to `throttle:login`. The per-email limit stops credential stuffing against one account; the looser per-IP limit still caps a shared NAT without locking it out.

This deliberately reintroduces a named limiter — the exact construct that broke the API in #5 — so it was smoke-tested first: `POST /api/resident/login` returned 401, not 500.

**Live verification:**
- **17a resolved.** `remaining` now decrements by 1 (4,3,2,1,0), 429 at attempt 6 — the intended 5/min, not ~2/min.
- **17b resolved.** After resident A was locked out by 7 failed attempts, resident B logged in from the **same IP**: `200`, `remaining=4`. Separate budgets — the CGNAT lockout is gone.
- **IP fallback intact.** An attacker rotating distinct emails from one IP got 401 up to the 20th hit, then 429. The arithmetic confirms correct composition: only **6** IP tokens were spent before rotation, because A's two 429s were rejected at the *email* limit and never reached the IP limit — 6 + 14 = exactly 20. Laravel also swaps the reported header from `limit=5` to `limit=20` once the IP limit becomes binding.
- **Global throttle independent.** An authed route reports `limit=60` decrementing by 1, keyed by user. A's API token kept working while she was login-locked — a lockout does not kill an active session.

**Effort:** 30 min + live verification. **Done.**

---

## HIGH (discovered 2026-07-15 while fixing and verifying)

### 18. `/api/register` is completely broken — 500 on every request
**File:** `app/Http/Controllers/AuthController.php:22-28`

```
SQLSTATE[HY000]: General error: 1364 Field 'barangay_id' doesn't have a default value
```

`register()` never sets `barangay_id`, but `database/migrations/..._create_tbl_residents_table.php:16` declares it `foreignId('barangay_id')->constrained('tbl_barangay', 'barangay_id')` — NOT NULL, no default. Every registration attempt fails at the INSERT, with any password.

Confirmed against `git show HEAD` that this predates all 2026-07-15 work. Found while verifying #12: the weak password correctly 422'd, and the strong password reached the INSERT and blew up there — which is what proved #12 passed while exposing this.

**Why it matters here:** resident self-registration — the mobile app's front door — does not work at all. Whether that is a live outage or dead code depends on whether the Flutter app actually calls `/register`; worth confirming before prioritising.

**Fix:** decide the intended contract. Either accept and validate `barangay_id` in `register()`, or make the column nullable if residents genuinely register before being assigned a barangay. Note **#14** (vestigial `role` validation) lives in the same few lines — fix both together.

**Effort:** 20 min once the contract is decided.

---

### 19. Orphaned resident — dangling FK, silently excluded from SMS alerts
**Files:** data-level (`tbl_residents`), surfaced via `SmsController::sendBlast`

Resident 10 (Ernest Jerde, seeded 2026-06-26) has `barangay_id = 6`. **Barangay 6 does not exist.** Surfaced while verifying #16: per-barangay resident counts summed to 21 against 22 total residents.

The FK constraint did not prevent this row, so checks were presumably disabled during seeding. **Where there is one orphan there are usually more — this needs an integrity sweep, not a one-row patch.**

**Why it matters here:** `whereHas('barangay')` — the targeting query in #16 — **silently skips orphans**. Resident 10 would never receive an SMS disaster alert, and nothing would report the omission. Same hazard applies to any dashboard aggregate or report that joins through `barangay`. This is the failure mode where a real person misses a real evacuation notice.

**Fix:** sweep for dangling FKs, then decide per row — reassign to a real barangay or null the column and make it nullable.
```sql
SELECT r.resident_id, r.barangay_id FROM tbl_residents r
LEFT JOIN tbl_barangay b ON b.barangay_id = r.barangay_id
WHERE b.barangay_id IS NULL;
```
Left as-found during the #16 pass rather than quietly repaired.

**Effort:** 30 min for the sweep; longer if many rows need judgement calls.

---

### 20. `env()` outside config silently returns null under `config:cache`
**File:** `app/Http/Controllers/SmsController.php:39`

```php
'X-API-Key' => env('SKYSMS_API_KEY'),
```

Once `php artisan config:cache` runs — standard in any production deploy — `env()` calls outside of `config/` return **null**. The API key silently becomes empty and every SkySMS call fails auth. Nothing warns; the endpoint just stops working.

Pre-existing; preserved during the #16 rewrite rather than widening that pass's scope.

**Fix — applied and verified 2026-07-15.**
```php
// config/services.php  (slotted alphabetically, matching the postmark/resend shape)
'skysms' => [
    'key' => env('SKYSMS_API_KEY'),
],

// SmsController
'X-API-Key' => config('services.skysms.key'),
```

**Live verification** — the bug was demonstrated *before* the fix, so the fix means something:

| | dev (no cache) | after `php artisan config:cache` |
|---|---|---|
| Before — `env('SKYSMS_API_KEY')` | resolved, len=35 | **NULL — key gone** |
| After — `config('services.skysms.key')` | resolved, len=35 | **resolved, len=35** |

Then end-to-end **with config cached**, via `Http::preventStrayRequests()` + `Http::fake()` (nothing left the machine): `X-API-Key` present, len=35, matching `config()` exactly, sent to `https://skysms.skyio.site/api/v1/sms/send-bulk`. That is the test that counts — `config()` resolving in tinker does not prove the controller sends it. Config cache cleared afterwards; dev state restored.

**Swept for the same bug class:** `grep -rn "env(" app/ routes/ bootstrap/ database/` → **zero remaining**. `SmsController` was the only `env()` call outside `config/`, so this finding is closed completely rather than at one site.

**Relevant to #16:** SMS would have failed in production regardless of the pending burner-number test, because the key would have been empty.

**Effort:** 10 min. **Done.**

---

### 21. SMS blast is dead from the admin UI
**File:** `Web/serbis-admin-vue/src/views/SmsView.vue:129`

```js
barangays: ['test_mode_bypass'] // Fulfills backend validation without UI selection
```

There was no barangay picker in the admin panel — the frontend sent a placeholder string purely to satisfy the old validation. Once #16 did real targeting, this payload **422'd**.

**Fixed and verified 2026-07-15.** The TEST MODE alert block was replaced with a `v-select` (`multiple chips closable-chips`) populated from `GET /barangays` on mount, reusing the existing Vuetify styling and the `item-title`/`item-value` pattern already used in `UsersView.vue`. No new components or CSS. `test_mode_bypass` removed; button relabelled "Dispatch Blast"; the confirm dialog now names the actual target barangays instead of "TEST MODE ... 2 configured test numbers"; selection clears on success.

**Contract changed to IDs.** #16 originally targeted by `barangay_name` (matching the pre-existing `'barangays.*' => 'string'` validation). That was reversed by decision on 2026-07-15 — the frontend now sends `barangay_id` integers and the backend validates `integer|exists:tbl_barangay,barangay_id`, targeting via a plain `whereIn('barangay_id', ...)` (one fewer join than the old `whereHas`). Rationale: matches `UsersView.vue`'s existing `item-value="barangay_id"`, and survives a barangay rename.

**Backend re-verified** (`Http::fake()`, nothing sent):

| Payload | Result |
|---|---|
| `[3, 4]` (IDs) | **200**, 7 recipients — 4+3 active, matches |
| `["Brgy. Gucab"]` (old name contract) | **422** must be an integer |
| `["test_mode_bypass"]` | **422** must be an integer |
| `[]` | **422** field is required |
| `[999]` | **422** selected is invalid |

**Frontend verified in a real browser** with `window.fetch` stubbed before submit, so the payload was captured and **nothing reached the backend or SkySMS**:
- Valid 80-char message + **0 barangays → button still disabled**. This isolates the barangay guard from the pre-existing empty-message rule.
- 2 barangays selected → chips render, button enables.
- Confirm dialog: *"Dispatch this alert to all active residents in: Brgy. Gucab, Brgy. Soyung?"*
- **Captured payload: `{"message":"MDRRMO Alert: Flood warning...","barangays":[3,4]}`** — `number,number`, no `test_mode_bypass`, byte-for-byte the shape the backend accepts.
- After send: message cleared, chips cleared, button re-disabled.

**Still pending:** #16's manual burner-number test against the live vendor. The UI can now actually drive it.

**Effort:** 1-2 hours. **Done.**

---

### 22. The admin panel has never been buildable for production
**File:** `Web/serbis-admin-vue/src/views/RegisterView.vue` (0 bytes)

`npm run build` / `npx vite build` fails outright:
```
[plugin vite:vue] src/views/RegisterView.vue
RolldownError: At least one <template> or <script> is required in a single file component.
```

`RegisterView.vue` is a **0-byte file**, committed in `8807174` ("Initial commit — clean SERBIS repo") and unmodified since. It is the only empty `.vue` file in `src/`. `src/router/index.ts:5` lazily imports it:

```ts
{ path: '/register', component: () => import('../views/RegisterView.vue') },
```

**Why it matters here:** because the import is lazy, `vite dev` starts fine and nobody notices — the failure only appears when someone tries to build for production, which on this project has apparently never happened. **The admin panel cannot currently be deployed.** Discovered 2026-07-15 while trying to use `vite build` to validate the #21 change; the browser had to be used instead.

**Related to #18.** `POST /api/register` 500s on every request *and* the admin `/register` page is an empty file. Registration was never finished on either end, and nothing surfaces it: the broken API route is only reachable from a page that does not render.

**Fix:** either implement `RegisterView.vue`, or delete the file and its route if admin self-registration is not a real feature (likely — admins are seeded, and `/register` on the API creates *residents*, not admins). Decide alongside #18.

Note: `npx eslint` also fails to run in this project (module resolution error in the ESLint config), unrelated to this file.

**Effort:** 5 min to delete route + file; longer if the page is genuinely wanted.

---

## HIGH (discovered 2026-07-16 while planning #8)

### 28. `update()` lets an admin set `valid_id` to any path — arbitrary file read once #8's route exists
**File:** `app/Http/Controllers/ServiceRequestController.php:127` (pre-fix)

```php
$validated = $request->validate([
    // ...
    'valid_id' => 'nullable|string|max:255',   // free-form string
    // ...
]);

$serviceRequest->update($validated);
```

**Why it matters here:** `valid_id` is a **storage path**, not user data, but `update()` accepted it as an arbitrary 255-char string and mass-assigned it (`valid_id` is in `#[Fillable]`). On its own this was near-harmless: the value only fed a `public`-disk URL the frontend concatenated, so a bogus path produced a broken image.

**#8's fix is what makes it dangerous.** The moment a route streams `Storage::disk('local')->response($serviceRequest->valid_id)`, an admin-settable path becomes a **path-traversal primitive**:

```
PUT /api/service-requests/54   {"valid_id": "../../../../.env"}
GET /api/service-requests/54/valid-id   ->  streams .env
```

That yields `APP_KEY`, `DB_PASSWORD`, and `SKYSMS_API_KEY` — an admin-to-arbitrary-server-file-read escalation. Laravel's `local` disk driver does not constrain the path for you here. **Shipping #8 without this fix would have been strictly worse than the bug #8 closes**: it converts a leak of ID photos into a leak of the whole server.

**This is a separate vulnerability, not part of #8's original scope.** The read-only audit never flagged it, because in isolation it looks like sloppy validation rather than a security bug — it only becomes exploitable in combination with #8's fix. It was found while writing #8's implementation plan, by asking what the new route would trust.

**Fix (shipped 2026-07-16, `c66a978`):** drop `valid_id` from `update()`'s validation entirely. Only `store()` writes that column; no legitimate flow has an admin hand-typing a storage path.

```php
// 'valid_id' is deliberately not accepted here. It is a storage path written
// only by store(); allowing it to be set would let any admin point it at an
// arbitrary file for validId() to stream back.
```

**Verified live 2026-07-16:** admin `PUT` with `{"valid_id": "../../../../.env"}` returned 200 (other fields applied), the stored `valid_id` was **unchanged**, and the route still streamed the correct JPEG (107255 bytes, sha1 matching the source) rather than `.env` contents.

**Lesson:** a fix's blast radius includes what it makes *newly reachable*. `valid_id` was inert as a URL fragment and lethal as a filesystem path; the same untrusted string changed severity because a new consumer trusted it. When adding a component that reads a stored value, re-audit every writer of that value.

**Effort:** 2 min (delete one validation rule). Mandatory prerequisite for #8.

---

## MEDIUM

### 10. No pagination anywhere — every list endpoint returns the full table
**Files (all use `::all()` or `->get()` with no limit):**
- `BarangayController.php:16`
- `EquipmentController.php:13`
- `ServiceController.php:12`
- `VehicleController.php:13`
- `ResidentController.php:12`
- `ServiceRequestController.php:17` (`adminIndex`) and `:28/:32` (`index`)
- `EquipmentBorrowingController.php:15`
- `SystemLogController.php:12-16`
- `InfoMaterialController.php:13`

**Why it matters here:** `tbl_service_request`, `tbl_equipment_borrowing`, and `tbl_system_logs` are append-only, unbounded-growth tables. Right now they're small so this is invisible; a year into real disaster-response usage, `GET /logs/system` and `GET /admin/service-requests` will be loading and serializing the entire table on every page view, which will show up first as slow dashboard/log loads and eventually as memory pressure on the PHP worker.

**Fix (pattern, apply to each):**
```php
// SystemLogController::index
$logs = SystemLog::with(['admin' => fn($q) => $q->select('admin_id', 'first_name', 'last_name')])
    ->orderBy('created_at', 'desc')
    ->paginate(25);
```
Note: switching to `paginate()` changes the response shape (`{data: [...], meta: {...}}` instead of a bare array), so the Vue frontend's consumers of these endpoints need a matching update.

**Effort:** ~20 min per endpoint backend-side; budget more if the frontend needs pagination UI added too.

---

### 11. Dashboard analytics loads full tables into PHP memory on every request, uncached
**File:** `app/Http/Controllers/AnalyticsController.php:101-105`

```php
$serviceReqs = ServiceRequest::with('resident.barangay')->get();
$borrowReqs = EquipmentBorrowing::with('resident.barangay')->get();
$allRequests = $serviceReqs->concat($borrowReqs);
```

**Why it matters here:** The heatmap/pie/bar chart data is built by pulling every service request and every borrowing row into a Collection and grouping in PHP, on every single dashboard load, with zero caching. This is the most expensive controller in the app and the one most likely to get hit repeatedly (it's the admin landing page).

**Fix — quick win, cache the whole payload for a short window:**
```php
public function index(Request $request): JsonResponse
{
    return response()->json(
        Cache::remember('admin.dashboard', now()->addMinutes(2), function () {
            // existing body
        })
    );
}
```
Longer-term, replace the PHP-side `groupBy` for the heatmap/pie/bar sections with `GROUP BY` aggregate queries so the database does the counting instead of hydrating full Eloquent collections.

**Effort:** 30 min for the cache wrap; 2-3 hours to push the aggregations into SQL.

---

### 12. Password policy is length-only
**Files:** `AuthController.php:19` (register), `ResidentController.php:25,66` (admin create/update)

```php
'password' => 'required|string|min:8',
```

**Why it matters here:** No complexity requirement and no check against known-breached passwords. For an account tied to government services and PII, `min:8` alone permits things like `12345678`.

**Fix — applied and verified 2026-07-15.**
```php
use Illuminate\Validation\Rules\Password;

'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
```
Applied at all three sites: `AuthController::register()`, `ResidentController::store()`, and `ResidentController::update()` (kept `nullable` there so a password-less update still works).

**Live verification:** `12345678` → **422** at all three sites (*"The password field must contain at least one uppercase and one lowercase letter."*); a compliant password → **201** on create, **200** on update; a password-less update still **200**; and the updated resident could then log in, proving hashing is not regressed. The `register()` site could only be verified as far as validation — see **#18**, that endpoint 500s at the INSERT for unrelated reasons.

**Effort:** 10 min. **Done.**

---

### 13. No index on frequently-filtered `status` columns
**Files:** `database/migrations/2026_06_05_071926_create_tbl_service_request_table.php:21`, `database/migrations/2026_06_22_042205_create_tbl_equipment_borrowing_table.php:16`

```php
$table->string('status'); // tbl_service_request — no index
$table->enum('status', [...])->default('Pending'); // tbl_equipment_borrowing — no index
```

**Why it matters here:** `AnalyticsController.php:19-20` runs `ServiceRequest::where('status', 'Pending')->count()` and the equivalent for borrowings on every dashboard load. Foreign key columns (`resident_id`, `equipment_id`, etc.) already get an implicit index from `constrained()`/`references()`, but `status` doesn't, so these counts become full table scans as the tables grow.

**Fix — applied and verified 2026-07-15** as `database/migrations/2026_07_15_070000_add_status_index_to_request_tables.php`:
```php
Schema::table('tbl_service_request', function (Blueprint $table) {
    $table->index('status');
});
Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
    $table->index('status');
});
```

**Live verification** — `EXPLAIN` on the exact count queries from lines 19-20, with `IGNORE INDEX` as a before-control:

| Table | Before (`IGNORE INDEX`) | After |
|---|---|---|
| `tbl_service_request` | `type=ALL` `key=NULL` **rows=50** | `type=ref` `key=tbl_service_request_status_index` **rows=7** |
| `tbl_equipment_borrowing` | `type=ALL` `key=NULL` **rows=50** | `type=ref` `key=tbl_equipment_borrowing_status_index` **rows=9** |

`type=ALL` is the full table scan; `type=ref` is the index lookup. Both report `Extra: Using index` — a covering index, so the count is answered from the index without touching the table at all.

**Effort:** 15 min. **Done.**

---

## LOW

### 14. Vestigial `role` field in registration validation
**File:** `AuthController.php:17`

`register()` still validates `'role' => 'required|string|max:50'`, but `Resident`'s fillable list has no `role` column, so the value is silently dropped by mass-assignment protection. Not exploitable, just dead/confusing code left over from when `register()` created `User` rows (see commit `663d527`). Remove the validation rule.

**Effort:** 5 min.

### 15. Admin CRUD exposes `otp` / `otp_verified_at` as directly settable fields
**File:** `ResidentController.php:28-29,66`

These are mobile-app OTP-verification fields; letting an admin set `otp_verified_at` directly via the residents CRUD form has no legitimate use case and is an odd surface to leave open. Low risk since the route is admin-only, but worth trimming from the admin-facing validation rules.

**Effort:** 10 min.

### 16. SMS blast ignores its own `barangays` validation (functional, not security)
**File:** `SmsController.php:19-31`

The endpoint validates a `barangays` targeting array but never uses it — it always sends to two hardcoded test numbers (line 28-31), and the SkySMS URL itself has a stray space (`'...sms/send -bulk'`, line 36) that would break the real integration.

**Fixed 2026-07-15 — but NOT verified against the live vendor.** Hardcoded numbers and the dead `$testNumbers` property removed; recipients now resolve from the `barangays` array (Active residents with a non-empty phone); URL corrected; added a guard that returns 422 rather than calling a **billed** endpoint with zero recipients.

**Endpoint confirmed from vendor docs, not inferred.** SkySMS's own features page documents four endpoints including `POST /api/v1/sms/send-bulk` — so `send -bulk` → `send-bulk` is correct. Their `/docs` returns **403**, so the bulk request *schema* could not be read; the pre-existing `{recipients: [{phone_number}], message}` shape was kept on faith and remains unverified.

> **No sandbox exists.** SkySMS offers only "a free account and 10 SMS credits to test" — real sends, real handsets, real billing. No dry-run flag, no test keys, no simulation mode. **This fix cannot be verified without spending money and texting a real phone, and must get a manual burner-number test before shipping.**

**Verified safely** with `Http::preventStrayRequests()` + `Http::fake()` — nothing left the machine:
- Targeting `[Brgy. Gucab, Brgy. Soyung]` → 7 active recipients; 1 inactive resident correctly excluded.
- URL actually sent: `https://skysms.skyio.site/api/v1/sms/send-bulk` — no space. `X-API-Key` header present.
- Zero-recipient path → 422 with **0 outbound calls** (asserted via `Http::assertSentCount(0)`).
- The frontend's current payload → 422 (see **#21**).

**Not fixed — flagged:**
- **No phone normalization.** Seeded numbers are Faker **US** junk in five formats (`+1-763-248-1770`, `+14847900609`, `(458) 453-3290`, `480-705-0491`); only resident 22 has a PH-format number. Normalization is untestable against this data and the vendor's expected format is undocumented at 403, so `phone_number` is passed through as stored.
- **No chunking.** SkySMS's bulk recipient cap is unknown (docs 403). Fine at 22 residents; unknown at real scale.
- See **#20** for the `env()` key footgun in this same file.

**Design choices, revisit if wrong:** targets by `barangay_name` (matches the existing string validation and #21's proposed contract), and **Active residents only** — reconsider if inactive residents should still receive disaster alerts.

---

## Summary table

| # | Severity | Finding | File | Effort |
|---|----------|---------|------|--------|
| 1 | Critical | IDOR — all borrowings listed to any user | EquipmentBorrowingController.php:12 | 15 min |
| 2 | Critical | IDOR — any service request readable by ID | ServiceRequestController.php:94 | 15 min |
| 3 | Critical | IDOR — any borrowing readable by ID | EquipmentBorrowingController.php:37 | 15 min |
| 4 | Critical | Resident password hash/OTP in JSON responses | Resident.php | 5 min |
| 5 | Critical | No rate limiting anywhere | bootstrap/app.php:15 | 30 min |
| 6 | High | Client-controlled `resident_id` on borrow create | EquipmentBorrowingController.php:19 | 10 min |
| 7 | High | Dangling open transaction | EquipmentBorrowingController.php:69 | 10 min |
| 8 | High | ID photos public, unauthenticated | ServiceRequestController.php:51 | done (`c66a978`) |
| 9 | High | CORS wide open | config/cors.php:22 | 10 min |
| 10 | Medium | No pagination, 9 endpoints | multiple | ~20 min each |
| 11 | Medium | Dashboard loads full tables, uncached | AnalyticsController.php:101 | 30 min–3 hr |
| 12 | Medium | Password policy length-only | AuthController.php:19 | 10 min |
| 13 | Medium | Missing index on `status` | 2 migrations | 15 min |
| 14 | Low | Dead `role` validation | AuthController.php:17 | 5 min |
| 15 | Low | OTP fields in admin CRUD | ResidentController.php:28 | 10 min |
| 16 | — | SMS blast dead code + malformed URL | SmsController.php | 15 min |
| 17 | High | Throttle IP-keyed + double-counted, CGNAT lockout | bootstrap/app.php:16, routes/api.php:18 | 30 min |
| 18 | High | `/api/register` 500s on every request (`barangay_id`) | AuthController.php:22 | 20 min |
| 19 | High | Orphaned resident, dangling FK, skipped by SMS targeting | data / tbl_residents | 30 min |
| 20 | High | `env()` returns null under `config:cache`, kills SkySMS key | SmsController.php:39 | 10 min |
| 21 | High | SMS blast dead from admin UI (no barangay picker) | SmsView.vue:129 | 1-2 hr |
| 22 | High | Admin panel cannot build for production (0-byte `RegisterView.vue`) | RegisterView.vue, router/index.ts:5 | 5 min |
| 23 | Low | No 404/catch-all route in Vue router; unknown authenticated paths render blank instead of a not-found page. Pre-existing, affects all unmatched routes, not just the removed `/register`. | router/index.ts | 10 min |
| 24 | Low | `tsconfig.app.json` had `"ignoreDeprecations": "6.0"`, invalid on the pinned TS 5.9.3 — `type-check` aborted before checking any file. Shipped broken in `8807174`. | tsconfig.app.json:8 | done |
| 25 | Low | `useAppTheme.js` was plain JS in a TS project (TS7016 in `App.vue`, `AppSidebar.vue`). Masked by #24. Renamed `.js`→`.ts`; **fix is in the working tree, not committed** — the file has no importer at HEAD, so it lands with the uncommitted theming work. | src/composables/ | fixed, uncommitted |
| 26 | High | `AdminSeeder` hardcodes `admin@serbis.com` / `password123` and `DatabaseSeeder` calls it **unconditionally — no environment guard**. `db:seed` or `migrate --seed` against production plants a known-credential admin on a DB holding government-ID scans. **Fixed 2026-07-15** (`f4a29c5`): guarded on `app()->environment(['local','testing'])` **and** skips when the admin already exists. Note the old blind `insert()` never overwrote a password — `email_address` is unique, so a re-seed aborted the whole run on a duplicate key. | AdminSeeder.php:21, DatabaseSeeder.php:12 | done |
| 27 | High | **`ResidentSeeder` was the root cause of #19, and carried #26's credential problem.** Three defects in 20 lines: (a) hardcoded `Hash::make('password')` for all 20 residents; (b) unguarded — `DatabaseSeeder` called it in any environment; (c) **`Schema::disableForeignKeyConstraints()` wrapped around `'barangay_id' => rand(1, 6)`, while `BarangaySeeder` creates only ids 1–5** — `barangay_id` is the *only* FK on `tbl_residents`, so that call existed solely to suppress this check. ~97% of seed runs orphaned at least one resident (`1-(5/6)^20`). **Fixed 2026-07-15** (`8364705`): draws from real barangay ids, FK disabling removed, env-guarded, distinct `Str::password(16)` each. Verified on a scratch DB — 5 fresh runs, 100 residents, 0 orphans; old logic replayed on the same schema gave 3 orphans (proving the check can fail); 0/20 crack to common guesses vs 20/20 before. | ResidentSeeder.php:17,21,26 | done |
| 28 | High | **`update()` accepted `valid_id` as a free-form string** — inert while it only fed a public URL, but #8's streaming route turns it into a path-traversal primitive: `PUT {"valid_id":"../../../../.env"}` then `GET .../valid-id` returns `APP_KEY` + `DB_PASSWORD`. Admin-to-arbitrary-file-read. **Separate vulnerability, not part of #8's scope** — invisible to the read-only audit because it is only exploitable *in combination with #8's fix*; found while planning #8. **Fixed 2026-07-16** (`c66a978`): rule dropped; only `store()` writes that column. Verified: traversal PUT ignored, path unchanged, route still streams the real JPEG. | ServiceRequestController.php:127 | done |

### Status at 2026-07-16

**Fixed and verified live (19):** #1, #2, #3, #4, #5, #6, #7, #8, #9, #12, #13, #16*, #17, #20, #21, #28, plus #14/#18/#22 — the registration cluster, resolved by **deleting** the flow (product decision: registration is mobile-only). `/api/register` → 404, both logins still 200/401, `npm run build` → exit 0. #24 and #25 were found and fixed while verifying #22.
\* #16 is code-complete and verified with faked HTTP, but **unverified against the live vendor — no sandbox exists.** Needs a manual burner-number test; #21 means the UI can now drive it.

**Open (4):** #10, #11, #15, #23. (#26 fixed in `f4a29c5`; #27 in `8364705`; #19 closed by data fix.) **No Critical or High findings remain open.**

**#8 and #28 fixed 2026-07-16 (`c66a978`)** — backend and frontend together. #28 was found while planning #8 and had to ship with it: #8's streaming route would have turned #28's admin-settable path into arbitrary server file read. The audit's own #8 fix sketch would have shipped that hole.

> ### The fixes were uncommitted for a day — resolved 2026-07-16
> **How it happened.** Of the five commits made on 2026-07-15, `7b2a5bc` — "Add security/performance audit log" — touched **exactly one file: this document, 736 insertions, zero code**. The other four were real code but unrelated to these findings: the registration removal, `ignoreDeprecations`, and the two seeders. **Every fix described above was left in the working tree.** For a day, HEAD documented its own vulnerabilities in detail while still containing all of them — `show($id)` with no ownership scope, login routes with no throttle, `Resident` with no `#[Hidden]`, CORS at `*`. The reflog shows no reverts and no resets: they were simply never staged. A plausible reading is that the four that landed were self-contained single-file changes, while the fixes were spread across a tree that also held unrelated theming — but that is speculation about intent, not something git records.
>
> **Nothing was ever pushed.** `origin/update-admin-vue` remained behind throughout, so this document never reached GitHub. Had it been pushed while the repo was public (it was, until 2026-07-15), it would have been a precise exploitation guide to vulnerabilities live in the committed code.
>
> **Now committed**, after a full working-tree snapshot was taken at `wip/safety-snapshot-2026-07-16` (`3a4b2fe`) as a recovery point:
>
> | Commit | Findings |
> |---|---|
> | `c66a978` | #8, #28 — private disk + ownership-checked route; also carries #2's `show()` scoping and #17's `throttle:login`, which could not be split out |
> | `9358b9a` | #1, #3, #6, #7 — borrowing IDORs, `resident_id` spoof, transaction leak |
> | `55839b7` | #4 — `Resident` `#[Hidden]` |
> | `b37d89d` | #5, #17 — `throttleApi('60,1')` + the named `login` limiter |
> | `e249c09` | #9 — CORS restricted to `ADMIN_FRONTEND_URL` |
> | `46aac35` | #12 — password policy |
> | `6ef5efe` | #13 — `status` indexes |
> | `9672db8` | #16, #20, #21 — SMS targeting, key via `config()`, barangay picker |
>
> **Per-finding commits were not fully possible**, because several files mix findings: `ServiceRequestController.php` carries #2 and #8, and `routes/api.php` carries #17 and #8. Both rode along in `c66a978`, which says so in its message.
>
> **Still uncommitted and deliberately so:** the admin panel theming, `AnalyticsController.php` (heatmap feature work, not an audit fix — #11 remains open), and the `Mobile/` tree.

**Lesson worth keeping.** Committing the *report* is not committing the *fix*. For a full day this document asserted "14 of 22 fixed and verified live" — true of the working tree, false of the repository — and nothing in the audit process caught the gap, because verification ran against the working tree too. **A fix is not shipped until it is committed; check `git show HEAD:<file>`, not the file on disk.**

**#19 closed 2026-07-15.** Root cause was #27, fixed in `8364705`. The one orphan — resident 10, faker data — was deleted along with its 2 borrowings and the 2 `tbl_system_logs` rows referencing them (backed up first). **Integrity sweep result: 15 declared FKs and 5 polymorphic audit types checked — 0 orphans, 0 dangling refs system-wide.** Barangay distribution (4/3/5/3/6) now sums to 21 = total residents; that gap *was* the orphan.

**Sweep method, for whoever repeats it:** iterate `information_schema.KEY_COLUMN_USAGE` for declared FKs, then separately check `*_id` columns that have **no** FK — `tbl_system_logs.auditable_id` is polymorphic across 5 models and nothing protects it. (`tbl_service_request.valid_id` is a `varchar` file path, not a reference, despite the name; `borrow_id`/`files_id` are primary keys.)

**The mobile app makes no HTTP calls at all** — `Mobile/lib/state/user_store.dart` persists accounts to `shared_preferences` on-device ("There is still no backend"). So `/api/register` was dead on both ends, and **resident signup now has no backend path**; the mobile flow remains to be built.

**Findings #17–#22 were all discovered while fixing and verifying — not during the original read-only pass.** Six of twenty-two, including a total production-build breakage and a completely non-functional registration flow, were invisible to a careful read of the same code. That is the single clearest argument in this document for exercising changes rather than reading them.

**Cluster worth noting:** #14 (dead `role` validation), #18 (`/api/register` 500s), and #22 (0-byte `RegisterView.vue`) are all the same unfinished registration feature, seen from three angles. They should be decided together — most likely by removing the flow if admins are seeded and residents register via the mobile app.

No mass-assignment holes were found (every model has `$fillable`/`#[Fillable]`, no `request()->all()` anywhere), no raw SQL interpolation (`DB::raw`/`whereRaw`), and no hardcoded secrets — the SkySMS key correctly comes from `env()`.
