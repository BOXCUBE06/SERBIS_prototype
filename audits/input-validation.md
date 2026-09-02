# Input Validation Audit — SERBIS

**Scope:** `Backend/SERBIS-Backend` (Laravel 11, MySQL/MariaDB), `Web/serbis-admin-vue` (Vue 3 + Vuetify), `Mobile/` (Flutter).
**Date:** 2026-08-31 · **Commit:** `1fc04eb` (branch `update-admin-vue`)
**Surface:** 73 API routes, 15 controllers.
**Method:** static review of every controller, route and validation rule, plus two runtime probes executed against the `serbis_phpunit` MySQL test database.

---

## Summary

**Risk score: 3.5 / 10 (Low–Moderate).**

The injection classes that usually dominate this kind of review are essentially absent, and not by luck — the codebase consistently uses Eloquent, assigns columns one at a time instead of splatting validated input, and stores uploads under server-generated UUIDs. There is **no raw SQL built from request data, no command execution, no XML parsing, no mass assignment, and no XSS sink in the admin panel.**

The real weakness is narrower and duller: **validation rules describe types but not sizes.** Twenty rules declare `string` with no `max:`, while the underlying columns are `varchar(255)` or `text`. Because the MySQL connection runs in strict mode, an over-length value is rejected by the *database* rather than the *validator*, turning what should be a 422 into an unhandled 500. Both instances below were reproduced.

| # | Finding | Severity |
|---|---|---|
| 1 | Missing length ceilings turn validation failures into HTTP 500 | **Medium** |
| 2 | `phone_number` accepts any 20-character string | **Low** |
| 3 | No `X-Content-Type-Options: nosniff` on private file responses | **Low** |
| 4 | `file_type` stored from the client-supplied extension | **Low** (informational) |

---

## Finding 1 — Missing length ceilings turn validation failures into HTTP 500

**Severity:** Medium · **CWE-20** (Improper Input Validation), **CWE-1284** (Improper Validation of Specified Quantity in Input)

**Evidence**

| File | Line | Rule | Column type |
|---|---|---|---|
| `app/Http/Controllers/ResidentController.php` | 38–41 | `first_name`, `middle_name`, `last_name`, `phone_number` → `string` | `varchar(255)` |
| `app/Http/Controllers/ResidentController.php` | 95–98 | same four, on `update()` | `varchar(255)` |
| `app/Http/Controllers/ServiceRequestController.php` | 92 | `description` → `required\|string` | `text` (64 KB) |
| `app/Http/Controllers/ServiceController.php` | 22, 51 | `description` → `nullable\|string` | `text` |
| `app/Http/Controllers/ConductionRequestController.php` | 67, 144 | `medical_diagnosis`, `others` → `string` | `text` |
| `app/Http/Controllers/EquipmentController.php` | 36 | `item_name` → `required\|string\|unique:…` | `varchar(255)` |
| `app/Http/Controllers/AuthController.php` | 79, 399–400, 602–603, 648 | `code`, `challenge_id` → `required\|string` | cache keys, not columns |
| `app/Http/Controllers/VehicleController.php` | 21, 23, 51–55, 57 | `unit_identifier` → `required\|unique:…` (no `string`, no `max:`), `specification` → `nullable\|string` | `varchar(255)` |

> **Correction, 2026-09-03.** The two `VehicleController` rules were **missed by
> the original pass** and the matrix below recorded `/vehicles` `POST/PUT` as
> length-bounded, which was false. They are the same defect as the rest of this
> finding — `unit_identifier` additionally had no `string` rule at all, so an
> array reached the `unique` check. Both are fixed and now covered by
> `ValidationLengthLimitsTest`; the matrix row is corrected.

`config/database.php:73,102` sets `'strict' => true`, so MySQL raises error 1406 (*Data too long for column*) instead of silently truncating. Laravel has no handler for it, so it surfaces as a 500.

**Exploitability**

Authenticated-only, and no data is disclosed — `APP_DEBUG=false` is enforced at boot by `AppServiceProvider::assertDebugIsOffInProduction()`, so the response is a bare 500. The impact is availability and log noise, not compromise. A resident can push ~64 KB per request body into `tbl_service_request.description` when *under* the limit, and every request over it produces an unhandled exception.

**Reproduction** — both confirmed on 2026-08-31 against `serbis_phpunit`:

```php
// 1. varchar(255) overflow — POST /api/residents as an admin
'first_name' => str_repeat('a', 300),    // → HTTP 500

// 2. text overflow — POST /api/service-requests as a resident
'description' => str_repeat('a', 70000), // → HTTP 500
```

Both should be **422 with a field error**.

**Remediation** — add the ceiling to each rule:

```php
// ResidentController::store() and ::update()
'first_name'   => 'required|string|max:255',
'middle_name'  => 'nullable|string|max:255',
'last_name'    => 'required|string|max:255',
'phone_number' => 'required|string|max:20',

// ServiceRequestController::store()
'description' => 'required|string|max:5000',

// ServiceController::store() / ::update()
'description' => 'nullable|string|max:5000',

// ConductionRequestController
'medical_diagnosis' => 'required|string|max:5000',
'others'            => 'sometimes|nullable|string|max:5000',

// EquipmentController::store()
'item_name' => 'required|string|max:255|unique:tbl_equipments,item_name',

// AuthController — codes are fixed-width, challenge ids are UUIDs
'code'         => 'required|string|size:6',
'challenge_id' => 'required|string|max:64',
```

`max:5000` on the `text` columns is a product choice, not a technical one — pick a number the UI can actually display. The point is that a ceiling exists.

**Defense in depth:** `AuthController`'s `code` and `challenge_id` never reach a column, so they cannot 500 — but bounding them keeps an oversized body from being hashed and compared on every MFA attempt.

---

## Finding 2 — `phone_number` accepts any string up to the column width

**Severity:** Low · **CWE-20**

**Evidence:** `ResidentController.php:41,98`, `AuthController.php:31` — all `string` with no format rule. `App\Services\PhilSms::normalize()` (`app/Services/PhilSms.php:66-76`) strips non-digits and returns `''` for anything that is not a recognisable PH mobile number.

**Why it matters:** the failure is silent and late. A malformed number is accepted at registration, stored, and only discovered when a blast or an OTP is attempted — at which point `normalize()` returns `''` and the resident simply never receives anything. Nothing in the admin list looks wrong. This is the same failure shape the `status` rule at `ResidentController.php:50` was tightened to prevent, and the comment there says so.

**Remediation:**

```php
'phone_number' => ['required', 'string', 'max:20', 'regex:/^(09\d{9}|639\d{9}|\+639\d{9})$/'],
```

---

## Finding 3 — No `nosniff` header on private file responses

**Severity:** Low · **CWE-430** (Deployment of Wrong Handler)

**Evidence:** `ServiceRequestController.php:493,519` and `ResidentController.php:264` return `Storage::disk(...)->response($path)`. No `X-Content-Type-Options` header is set anywhere — grep over `app/`, `bootstrap/` and `config/` returns nothing.

**Why it matters:** these endpoints serve user-uploaded images. Uploads are constrained to `mimes:jpg,jpeg,png` and stored under server-generated UUID names, so this is genuinely defense-in-depth rather than a live hole — but a browser that content-sniffs a crafted file is the one remaining path to script execution in the viewer's origin.

**Remediation** — a small middleware applied to the API group:

```php
// app/Http/Middleware/SecurityHeaders.php
public function handle(Request $request, Closure $next): Response
{
    return tap($next($request), fn ($r) => $r->headers->add([
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
    ]));
}
```

---

## Finding 4 — `file_type` is stored from the client-supplied extension

**Severity:** Low (informational) · **CWE-20**

**Evidence:** `InfoMaterialController.php:58` — `'file_type' => $file->getClientOriginalExtension()`.

The file itself is validated by `mimes:pdf,jpg,jpeg,png` and stored under a hashed name by `$file->store()`, so the *file* is safe. Only the label is attacker-influenced. It reaches the admin table through Vue's default interpolation, which escapes, and the panel contains **no `v-html`** — verified by grep across `Web/serbis-admin-vue/src`. So there is no XSS today; this is noted because it becomes one the moment somebody renders that column unescaped.

**Remediation:** `'file_type' => $file->extension()` — the guessed extension from the file's own content, not the client's claim.

---

## Validation matrix

Write endpoints. All routes except the six auth endpoints and `GET /barangays` sit behind `auth:sanctum` (`routes/api.php:43`); `is.admin` is noted where it applies.

| Endpoint | Method | Auth | Validated | Length-bounded | Notes |
|---|---|---|---|---|---|
| `/register` | POST | public | ✅ | ✅ | `max:255` throughout, `Password::min(8)->mixedCase()->numbers()` |
| `/admin/login`, `/resident/login` | POST | public | ✅ | ✅ | `throttle:login` — 5/min per email+IP, 20/min per IP |
| `/admin/login/verify`, `/resident/login/verify` | POST | public | ✅ | ❌ | `code`/`challenge_id` unbounded (F1); `throttle:mfa` |
| `/resident/login/resend` | POST | public | ✅ | ❌ | `challenge_id` unbounded (F1) |
| `/resident/verify-email`, `…/resend` | POST | public | ✅ | ❌ | `code` unbounded (F1) |
| `/me` | PATCH | resident | ✅ | ✅ | cannot reach `barangay_id`, `status`, `role` |
| `/me/photo` | POST | resident | ✅ | ✅ | `mimes:jpg,jpeg,png\|max:4096` |
| `/service-requests` | POST | resident | ✅ | ❌ | `description` unbounded (F1) |
| `/service-requests/{id}/cancel` | PATCH | owner | n/a | n/a | no body; ownership enforced in controller |
| `/borrowings` | POST | resident | ✅ | ✅ | `purpose` `max:255`; stock ceiling checked |
| `/admin/service-requests` | POST | admin | ✅ | ❌ | walk-in; same `description` rule (F1) |
| `/admin/info-materials` | POST | admin | ✅ | ✅ | `mimes:pdf,jpg,jpeg,png\|max:10240` |
| `/residents` | POST/PUT | admin | ✅ | ❌ | four name/phone fields unbounded (F1) |
| `/admins` | POST/PUT | admin | ✅ | ✅ | |
| `/equipments` | POST/PUT | admin | ✅ | ❌ | `item_name` unbounded (F1) |
| `/services` | POST/PUT | admin | ✅ | ❌ | `description` unbounded (F1) |
| `/barangays` | POST/PUT | admin | ✅ | ✅ | |
| `/vehicles` | POST/PUT | admin | ✅ | ❌ | `unit_identifier`, `specification` unbounded (F1) — **corrected 2026-09-03, this row previously read ✅** |
| `/conduction-requests` | POST | admin | ✅ | ❌ | `medical_diagnosis` unbounded (F1) |
| `/conduction-requests/{id}/trip-log` | PATCH | admin | ✅ | ❌ | `others` unbounded (F1) |
| `/sms/blast` | POST | admin | ✅ | ✅ | `max:160`, `barangays.*` `integer\|exists`; `throttle:3,60` |
| `/borrowings/{id}` | PUT | admin | ✅ | ✅ | status transitions whitelisted |
| `/service-requests/{id}` | PUT | admin | ✅ | ✅ | |
| `/service-requests/{id}/approve`, `/reschedule` | PATCH | admin | ✅ | ✅ | |
| all `DELETE` routes | DELETE | admin | n/a | n/a | no request body |

Read endpoints taking parameters:

| Endpoint | Parameter | Handling |
|---|---|---|
| `/logs/system`, `/logs/sms` | `?search` | ✅ bound `LIKE` with `addcslashes($s, '%_\\')` — `SystemLogController.php:73` |
| `/logs/system`, `/logs/sms` | `?per_page` | ✅ `integer()`, floored at 1, capped at 100 — `PaginatesLists.php:66` |
| `/service-requests/{id}/valid-id`, `/site-photo` | `{id}` | ✅ ownership guard, 404 not 403 so existence is not disclosed |
| `/residents/{id}/photo` | `{id}` | ✅ admin-or-self, re-checks `isDeactivated()` |

---

## Checklist

| Item | Status | Evidence |
|---|---|---|
| **1. SQL injection** | | |
| Raw SQL without parameterization | **Pass** | 4 `selectRaw` calls in `AnalyticsController.php:165,190,198,219` are static literals. The one `orWhereRaw` (`SystemLogController.php:85`) binds `?`. |
| Dynamic query building | **Pass** | `$table` interpolated at `AnalyticsController.php:160,216` comes only from hardcoded call sites, never from a request. |
| Stored procedure calls | **N/A** | None in the schema. |
| **2. NoSQL injection** | **N/A** | MySQL/MariaDB only; no MongoDB. |
| **3. Command injection** | **Pass** | Zero hits for `exec`, `shell_exec`, `proc_open`, `passthru`, `system`, `popen`, `Symfony\Process` across `app/` and `routes/`. |
| **4. XSS — input sanitization** | **Pass** | Nothing is stored as HTML; the only client-influenced label is F4. |
| **4. XSS — output encoding** | **Pass** | No `v-html`, `innerHTML` or equivalent in `Web/serbis-admin-vue/src`. API returns JSON only; no Blade views render user data. |
| **4. XSS — Content-Type headers** | **Fail** | No `nosniff` (F3). |
| **5. XXE** | **N/A** | No XML parsing anywhere — zero hits for `simplexml`, `DOMDocument`, `XMLReader`, `libxml`. Uploads are images and PDFs, never parsed server-side. |
| **6. Path traversal — filesystem ops** | **Pass** | Every `Storage::` path comes from a DB column written by `Str::uuid()` (`ServiceRequestController.php:112-115`), never from request input. |
| **6. Directory listing** | **Pass** | Private disk roots at `storage/app/private`, outside the web root. Public disk serves only `info_materials`. |
| **7. Body size limits** | **Pass** | `post_max_size=40M`, `upload_max_filesize=40M`; per-field `max:` on every upload rule. |
| **7. Parameter pollution** | **Pass** | Laravel takes the last value for a duplicated key; no endpoint branches on array-vs-scalar shape. |
| **7. Type checking** | **Partial** | `integer`/`exists`/`in:`/`date` used consistently; sizes missing (F1), phone format missing (F2). |
| **7. Required field validation** | **Pass** | Every write endpoint calls `validate()`. Methods without it are GET/DELETE with no body — confirmed by walking all 15 controllers. |

---

## Top fixes, in order

1. **Add `max:` to the twenty unbounded `string` rules** (F1). One line each, removes every confirmed 500 in this report. Highest value by a wide margin.
2. **Add the `phone_number` regex** (F2). Prevents a silent, permanent SMS-delivery failure that currently has no symptom.
3. **Add the two-header `SecurityHeaders` middleware** (F3).
4. **Switch `file_type` to `$file->extension()`** (F4). One word.

Fixes 1 and 2 are worth a regression test each — assert 422, not just "not 500", so a future rule change cannot quietly reintroduce the database-level rejection.

## What was not covered

- **Authentication and authorization logic** beyond the input surface — session handling, token lifetime and the MFA flow were not reviewed here. `PrivateFileAccessTest` exists and the ownership guards read correctly, but a full authz review is a separate pass.
- **Rate limiting adequacy.** The limiters are present and sensibly keyed; whether 5/min per email is the right number is a policy question.
- **The Flutter client** was checked for injection sinks only. It builds every URL from a compile-time `baseUrl` plus a literal path (`Mobile/lib/state/api_service.dart:160-182`), decodes JSON with `jsonDecode`, and has no WebView or process execution. Nothing to report.
- **Dependency vulnerabilities** (`composer audit`, `npm audit`) — out of scope for input validation.
