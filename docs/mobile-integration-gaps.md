# Mobile ↔ Backend Integration Gaps

**Date:** 2026-07-24
**Scope:** `Backend/SERBIS-Backend` (Laravel 12 + Sanctum) vs `Mobile/` (Flutter)
**Method:** static read of `routes/api.php` + all 12 controllers vs `Mobile/lib`, plus a live run of the API on `http://127.0.0.1:8000` (status codes below marked *verified live* were reproduced with curl against the seeded local DB).
**Read-only audit.** No file under `Mobile/` or `Backend/` was modified.

---

## Executive summary

| # | Finding | Severity |
|---|---|---|
| 1 | Mobile registration calls a route that does not exist — **404, resident cannot sign up at all** | **High** |
| 2 | `ServiceRequest.fromJson` reads `id`; backend returns `request_id` — every request id is `null`, which silently disables cancel and breaks ref numbers | **High** |
| 3 | Hardcoded `ServiceType → service_id` map is misaligned with `tbl_services` — an ambulance request is filed as *Flood Evacuation* | **High** |
| 4 | Android **release** builds have no `INTERNET` permission — every HTTP call fails outside debug | **High** |
| 5 | Cancel targets an admin-only route — **403**, but the UI shows "Cancelled" anyway | **High** |
| 6 | `AppUser.fromJson` reads `id`/`address`; backend returns `resident_id` and has no address column | **Med** |
| 7 | No profile/session endpoint — restoring a saved token yields a blank user | **Med** |
| 8 | Every API failure is swallowed by `catch (_) {}` — 401/403/422 are indistinguishable from success | **Med** |
| 9 | Equipment borrowing endpoints exist and are resident-facing; Mobile never calls them | **Med** |
| 10 | Hardcoded `127.0.0.1` base URL, `APP_URL=http://localhost:8000`, single-origin CORS | **Med** |

---

## A. Backend endpoints not consumed by Mobile

### A1 — Resident-facing (Mobile *should* be calling these)

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `GET /api/services` | Service catalogue is never fetched. Mobile hardcodes ids 1–6 instead (see C5). | `routes/api.php:27` ↔ `Mobile/lib/screens/services_screen.dart:14` | **High** | Fetch on app start, build the picker from the response, send the real `service_id`. |
| `POST /api/borrowings` | Equipment borrowing is a full backend feature (`store` + `index` + `show`, resident-scoped). Mobile's "Equipment / Item Request" tile files a **service request** with `service_id: 6` instead. | `routes/api.php:30`, `EquipmentBorrowingController.php:26` ↔ `services_screen.dart:20` | **Med** | Route `ServiceType.items` to `POST /api/borrowings` with `equipment_id` + `quantity`. |
| `GET /api/borrowings` | Resident can't see their borrowings; no screen exists. | `routes/api.php:30` | **Med** | Add a borrowings list to the Track screen. |
| `GET /api/equipments` | Needed to populate the item picker for the borrowing flow; Mobile hardcodes item names. | `routes/api.php:26` | **Med** | Fetch and bind to the item checklist. |
| `GET /api/info-materials` | Library screen serves bundled static Dart content (`Mobile/lib/data/safety_files.dart`); admin-published materials never reach residents. | `routes/api.php:33`, `InfoMaterialController.php:11` | **Med** | Fetch and merge with (or replace) the bundled list. Note `full_url` is built from `APP_URL` — see D2. |
| `GET /api/service-requests/{id}` | Detail view is never fetched; Track screen renders only what `index` returned. | `routes/api.php:28` | **Low** | Call on tap for fresh status/remarks. |
| `GET /api/service-requests/{id}/valid-id` | Resident cannot review the ID photo they submitted. Route is owner-scoped and already safe for this. | `routes/api.php:29`, `ServiceRequestController.php:123` | **Low** | Render behind `has_valid_id`. |
| `GET /api/barangays` | Needed if registration ever ships — `barangay_id` is required on `tbl_residents`. | `routes/api.php:25` | **Low** | Fetch for the registration barangay picker. |

### A2 — Admin-only (correctly absent from Mobile)

All sit behind `auth:sanctum` + `is.admin` (`routes/api.php:35`), so Mobile must not call them.

| Endpoint | Location |
|---|---|
| `POST /api/admin/login` | `routes/api.php:18` (public, but web-only) |
| `GET /api/admin/analytics` | `routes/api.php:37` |
| `GET /api/admin/service-requests` | `routes/api.php:38` |
| `GET /api/admin/dashboard` | `routes/api.php:39` |
| `GET|POST|DELETE /api/admin/info-materials[/{id}]` | `routes/api.php:42-44` |
| `GET /api/logs/system` | `routes/api.php:46` |
| `POST /api/sms/blast` | `routes/api.php:48` |
| `apiResource /api/vehicles` | `routes/api.php:49` |
| `apiResource /api/residents` | `routes/api.php:50` |
| `barangays` / `equipments` / `services` writes | `routes/api.php:53-55` |
| `PUT|PATCH|DELETE /api/service-requests/{id}` | `routes/api.php:56` — **but see C4**, Mobile calls this |
| `PUT|PATCH|DELETE /api/borrowings/{id}` | `routes/api.php:57` |

---

## B. Mobile calls with no matching backend route

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `POST /api/register` | **Route does not exist.** Deleted during the 2026-07-15 audit when web registration was declared dead code; the mobile-side replacement was never built. **Verified live: 404** with body `{"message":"The route api/register could not be found."}`. Resident signup is impossible on any client. | `Mobile/lib/state/api_service.dart:102` ↔ absent from `routes/api.php` | **High** | Add `POST /api/register` → `AuthController@register` (public, `throttle:login`). It must accept `barangay_id` (required, FK) and `phone_number` (required) — see C1. |
| `POST /api/register` payload key `role` | Mobile sends `'role': 'resident'`. `tbl_residents` has no `role` column, and a client-settable role was the exact shape of audit finding #14. | `api_service.dart:105` | **Med** | Drop the key on the Mobile side; the new endpoint must never validate or persist a client-supplied `role`. |

---

## C. Contract mismatches

### C1 — Registration payload cannot satisfy the residents table

**Mobile** — `Mobile/lib/state/api_service.dart:102-109`:
```dart
      final data = await _post('/register', {
        'first_name': firstName,
        'last_name': lastName,
        'role': 'resident',
        'email_address': email,
        'password': password,
        'password_confirmation': password,
      });
```

**Backend** — `app/Models/Resident.php:14-15`:
```php
#[Table('tbl_residents', key: 'resident_id')]
#[Fillable(['barangay_id', 'first_name', 'middle_name', 'last_name', 'phone_number', 'password', 'photo', 'status', 'email_address', 'otp', 'otp_verified_at'])]
```

`barangay_id` is a non-null FK and `phone_number` is `required` everywhere else it is validated (`ResidentController.php:20-24`). Mobile sends neither, and the register screen collects neither. `role` is sent but does not exist on the table.

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `POST /api/register` | Missing required `barangay_id` + `phone_number`; sends a nonexistent `role`. | `api_service.dart:102-109` ↔ `Resident.php:15`, `ResidentController.php:20-24` | **High** | Add a barangay picker (fed by `GET /api/barangays`) and a phone field to the register screen; server sets `role`/`status` itself. |

---

### C2 — Request id: Mobile reads `id`, backend returns `request_id`

**Mobile** — `Mobile/lib/models/request_models.dart:258-260`:
```dart
  factory ServiceRequest.fromJson(Map<String, dynamic> json) {
    final idValue = json['id'];
    final id = idValue is int ? idValue : int.tryParse(idValue?.toString() ?? '');
```

**Backend** — `app/Models/ServiceRequest.php:14`:
```php
#[Table('tbl_service_request', key: 'request_id')]
```

Verified live — `POST /api/service-requests` returned:
```json
{"resident_id":21,"service_id":"1","description":"...","status":"Pending","processed_by":null,"vehicle_id":null,"request_id":31,"has_valid_id":true}
```

There is no `id` key in any service-request response. Knock-on effects, all silent:

- `request_models.dart:280` → `refNo: id != null ? 'SR-$id' : ''` — every ref number renders as an empty string.
- `request_store.dart:112-114` → `if (current.id == null) { return; }` — the cancel call **never fires**; the local list is mutated and the server is never told.
- `request_store.dart:86` → `requests.indexWhere((item) => item.refNo == refNo)` — all server-loaded rows share `refNo: ''`, so cancelling one can match the wrong row.

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `GET|POST /api/service-requests` | `id` read, `request_id` returned → id always `null`. | `request_models.dart:259` ↔ `ServiceRequest.php:14` | **High** | Mobile: `json['request_id'] ?? json['id']`. Or backend: expose an `id` accessor for API responses (do not rename the column — the admin panel keys on `request_id`). |

---

### C3 — User id / address: Mobile reads `id` and `address`, neither exists

**Mobile** — `Mobile/lib/state/account_store.dart:23-31`:
```dart
  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id: (json['id'] ?? '').toString(),
      firstName: json['first_name'] as String? ?? '',
      lastName: json['last_name'] as String? ?? '',
      email: json['email_address'] as String? ?? json['email'] as String? ?? '',
      address: json['address'] as String? ?? '',
    );
  }
```

**Backend** — `app/Http/Controllers/AuthController.php:51-55`:
```php
        return response()->json([
            'token' => $resident->createToken('resident-token')->plainTextToken,
            'role' => 'resident',
            'user' => $resident
        ], 200);
```

Verified live, the `user` object is:
```json
{"resident_id":21,"barangay_id":2,"first_name":"Mobile","middle_name":null,"last_name":"Tester","phone_number":"09171234567","photo":null,"status":"Inactive","email_address":"mobile.tester@serbis.local","otp_verified_at":null,...}
```

`id` → always `''`. `address` → always `''`; `tbl_residents` has no address column at all (location is `barangay_id`, a FK). `main.dart:121` then passes that empty string as `initialAddress`, so the profile address field is permanently blank.

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `POST /api/resident/login` | `id` read, `resident_id` returned. | `account_store.dart:25` ↔ `AuthController.php:54`, `Resident.php:14` | **Med** | Read `json['resident_id']`. |
| `POST /api/resident/login` | `address` read; column does not exist. | `account_store.dart:29` ↔ `Resident.php:15` | **Med** | Either derive the address from the eager-loaded `barangay` relation (backend must add `->load('barangay')` to the login response) or drop the field. |
| `POST /api/resident/login` | `email` fallback key is dead — backend only ever emits `email_address`. | `account_store.dart:28` | **Low** | Harmless; remove for clarity. |

---

### C4 — Cancel hits an admin-only route

**Mobile** — `Mobile/lib/state/api_service.dart:203-205`:
```dart
  Future<void> cancelRequest(int requestId) async {
    await _patch('/service-requests/$requestId', {'status': 'Cancelled'});
  }
```

**Backend** — `routes/api.php:35` and `:56`:
```php
    Route::middleware('is.admin')->group(function () {
        ...
        Route::apiResource('service-requests', ServiceRequestController::class)->only(['update', 'destroy']);
```

The URI and method resolve, but `update` is inside the `is.admin` group. **Verified live with a resident token: 403** `{"message":"Forbidden"}`. Worse, `request_store.dart:97-110` writes the Cancelled state locally *before* the call, and `:118` swallows the 403 — so the resident sees a cancelled request that the MDRRMO never received, and the lie survives until the next `loadRequests()`.

Note this failure is currently masked by C2: `id` is `null`, so the call is skipped entirely at `request_store.dart:112`. Fixing C2 alone will turn this into a live 403.

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `PATCH /api/service-requests/{id}` | Resident-initiated cancel is admin-gated. | `api_service.dart:204` ↔ `routes/api.php:56` | **High** | Add a resident-scoped route, e.g. `PATCH /api/service-requests/{id}/cancel` outside the admin group, that verifies `resident_id === $request->user()->getKey()` and only ever writes `status = 'Cancelled'` on a Pending row. Do **not** open the general `update()` to residents — it accepts `processed_by`, `status`, and `resident_id`. |

---

### C5 — `service_id` map is misaligned with `tbl_services`

**Mobile** — `Mobile/lib/screens/services_screen.dart:14-21`:
```dart
const Map<ServiceType, int> _serviceIdMap = {
  ServiceType.ambulance: 1,
  ServiceType.transfer: 2,
  ServiceType.road: 3,
  ServiceType.relief: 4,
  ServiceType.inquiry: 5,
  ServiceType.items: 6,
};
```

**Backend** — `tbl_services` as seeded (`GET /api/services`, verified live):

| id | service_name |
|---|---|
| 1 | Flood Evacuation |
| 2 | Fire Rescue |
| 3 | Ambulance/Medical Response |
| 4 | Relief Goods Distribution |
| 5 | Road Clearing |
| 6 | Search and Rescue |
| 7 | Power Line Repair |
| 8 | Debris Removal |
| 9 | Animal Rescue |
| 10 | Sandbagging |

Resulting misfiling — 5 of 6 are wrong:

| Mobile tile (`request_models.dart:67-84`) | Sends | Backend records it as | Correct id |
|---|---|---|---|
| Medical Transport / Ambulance | 1 | Flood Evacuation | 3 |
| Hospital Transfer | 2 | Fire Rescue | — none exists |
| Road Clearing | 3 | Ambulance/Medical Response | 5 |
| Relief Goods | 4 | Relief Goods Distribution | 4 ✅ |
| Information Inquiry | 5 | Road Clearing | — none exists |
| Equipment / Item Request | 6 | Search and Rescue | — belongs on `/borrowings` |

`store()` validates `'service_id' => 'required|exists:tbl_services,service_id'` (`ServiceRequestController.php:45`), so ids 1–6 all pass — the data is accepted and **wrong**. An ambulance request reaches the MDRRMO dispatcher labelled *Flood Evacuation*. This is the most dangerous finding in the report: it is silent, it passes validation, and it corrupts triage on a disaster-response system.

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `POST /api/service-requests` | Hardcoded id map misaligned with the seeded catalogue; ids survive `exists` validation so failure is invisible. | `services_screen.dart:14-21` ↔ `tbl_services`, `ServiceRequestController.php:45` | **High** | Drop the map. Fetch `GET /api/services` and bind ids at runtime. Two of the six tiles have no backing service row — either seed them or remove the tiles. |

---

### C6 — No session/profile endpoint

**Mobile** — `Mobile/lib/main.dart:66-74`:
```dart
        if (_api.isLoggedIn) {
          _currentUser = const AppUser(
            id: '',
            firstName: '',
            lastName: '',
            email: '',
            address: '',
          );
```

**Backend** — `routes/api.php` has no `/me`, `/user`, or `/resident/profile` route. The only source of resident identity is the login response (`AuthController.php:51-55`).

So on every relaunch with a stored token the app is authenticated but has an empty profile: name, email, and address all render blank until the resident logs out and back in.

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| *(missing)* `GET /api/me` | No way to rehydrate the user from a stored token. | `main.dart:67-74` ↔ absent from `routes/api.php` | **Med** | Add `GET /api/me` inside the `auth:sanctum` group returning `$request->user()->load('barangay')`. Mobile calls it in `loadToken()`. |

---

### C7 — Every API failure is swallowed

**Mobile** — `Mobile/lib/state/request_store.dart:43-52`, `:67-82`, `:116-118`:
```dart
      final list = await _api.getRequests();
      ...
    } catch (_) {}
```

**Mobile** — `Mobile/lib/state/api_service.dart:160-167`:
```dart
  Future<List<Map<String, dynamic>>> getRequests() async {
    final data = await _get('/service-requests');
    final raw = data['data'] ?? data['requests'] ?? data.values.first;
    if (raw is List) {
      return raw.cast<Map<String, dynamic>>();
    }
    return [];
  }
```

On a 401 the backend returns `{"message":"Unauthenticated."}` — `data.values.first` is then the string `"Unauthenticated."`, not a `List`, so `getRequests()` returns `[]`. An expired or revoked token is indistinguishable from "you have no requests". Same for `addRequest` (a 422 "No available vehicles at this time." from `ServiceRequestController.php:97` shows the optimistic row forever) and `cancelRequest` (the 403 in C4).

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| All | HTTP status is never inspected; `_decode` (`api_service.dart:83-93`) discards it and returns `{}` on non-JSON. | `api_service.dart:83-93,162`; `request_store.dart:51,82,118` | **Med** | Check `response.statusCode` in `_decode`, throw a typed error, surface it in a snackbar, and route 401 to a forced logout. |

---

### C8 — `required_vehicle_type` is never sent

**Backend** — `ServiceRequestController.php:44-49` and `:67-77` accept `required_vehicle_type`, look up an `Available` vehicle under `lockForUpdate()`, attach it, and flip it to `Dispatched`.

**Mobile** — `services_screen.dart:193-197` calls `addRequest` without the optional `requiredVehicleType`, so it is always `null`.

| Endpoint | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| `POST /api/service-requests` | Auto-dispatch is unreachable from Mobile; field is `nullable` so nothing errors. | `services_screen.dart:193` ↔ `ServiceRequestController.php:48` | **Low** | Send `'Ambulance'` for the ambulance/transfer tiles, and handle the 422 "No available vehicles at this time." path. |

---

### C9 — Auth header handling (no defect found)

Checked and correct: `api_service.dart:35-46` attaches `Authorization: Bearer` whenever a token is held, and `submitRequest` re-attaches it manually for the multipart request (`:179-182`). `Accept: application/json` is set on every call, which is what keeps Laravel returning 401 JSON instead of a redirect. Login and register correctly send no token expectation. No mismatch to report.

---

## D. Hardcoded / environment issues

| Endpoint / setting | Issue | Location (file:line) | Severity | Suggested fix |
|---|---|---|---|---|
| Android release build | `INTERNET` permission is declared **only** in the debug manifest. Release APKs will have no network access — every call fails on a real install. | `Mobile/android/app/src/debug/AndroidManifest.xml:6`; absent from `Mobile/android/app/src/main/AndroidManifest.xml` | **High** | Add `<uses-permission android:name="android.permission.INTERNET"/>` to the **main** manifest. |
| API base URL | `defaultValue: 'http://127.0.0.1:8000/api'` — unreachable from any device or emulator (`10.0.2.2` on Android emulator, LAN IP on a physical handset, real domain in production). | `Mobile/lib/state/api_service.dart:8-11` | **Med** | Already overridable via `String.fromEnvironment('API_BASE_URL')` — good design. Make the *default* the production HTTPS URL and pass `--dart-define=API_BASE_URL=...` for local work, so a forgotten flag fails loudly in dev rather than silently in prod. |
| `GET /api/info-materials` | `full_url` is built with `asset()`, i.e. from `APP_URL`, currently `http://localhost:8000`. Any file link Mobile renders points at the phone itself. | `InfoMaterialController.php:16` ↔ `Backend/SERBIS-Backend/.env:5` | **Med** | Set `APP_URL` to the real domain at deploy time (`.env` is gitignored, so this is a deploy checklist item, not a code change). |
| CORS | `allowed_origins` is a single value from `ADMIN_FRONTEND_URL` (dev: `http://localhost:3000`). A Flutter **web** build served from any other origin is blocked. Native builds are unaffected — CORS is browser-only. | `Backend/SERBIS-Backend/config/cors.php:22-24` | **Med** | If Flutter web ships, make the origin list an array and include the mobile web origin. If Mobile stays native-only, no change needed. |
| Android cleartext | Android 9+ blocks cleartext HTTP by default and no `networkSecurityConfig` is declared. Production HTTPS is fine; any `http://` LAN testing will fail with a confusing socket error. | `Mobile/android/app/src/main/AndroidManifest.xml` | **Low** | Add a debug-only network security config if LAN testing over HTTP is needed. Do not enable cleartext in release. |
| Sanctum tokens | `expiration => null` and `createToken()` passes no `expiresAt` (`AuthController.php:52`); no `sanctum:prune-expired` scheduled. A stolen phone keeps a valid resident token forever. Tracked as audit #30. | `Backend/SERBIS-Backend/config/sanctum.php`, `AuthController.php:52` | **Med** | Set a TTL. Needs a product decision — an 8h server TTL logs residents out with no refresh flow, so pair it with a refresh endpoint or a long-lived mobile token. |

---

## Appendix 1 — Backend endpoint inventory

All routes live in `Backend/SERBIS-Backend/routes/api.php`. Validation rules are inline in controllers; the project has no `app/Http/Requests` directory.

| Method | URI | Controller@method | Auth | Required fields |
|---|---|---|---|---|
| POST | `/api/admin/login` | `AuthController@adminLogin` | public, `throttle:login` | `email_address` (email), `password` |
| POST | `/api/resident/login` | `AuthController@residentLogin` | public, `throttle:login` | `email_address` (email), `password` |
| POST | `/api/logout` | `AuthController@logout` | `auth:sanctum` | — |
| GET | `/api/barangays` | `BarangayController@index` | `auth:sanctum` | — |
| GET | `/api/equipments` | `EquipmentController@index` | `auth:sanctum` | — |
| GET | `/api/services` | `ServiceController@index` | `auth:sanctum` | — |
| GET | `/api/service-requests` | `ServiceRequestController@index` | `auth:sanctum` | — (resident-scoped by `resident_id`) |
| POST | `/api/service-requests` | `ServiceRequestController@store` | `auth:sanctum` | `service_id` (exists), `description` (string), `valid_id` (file, jpg/jpeg/png, ≤2 MB), `required_vehicle_type` (nullable, exists `tbl_vehicles.type`) |
| GET | `/api/service-requests/{id}` | `ServiceRequestController@show` | `auth:sanctum` | — (404 for non-owner) |
| GET | `/api/service-requests/{id}/valid-id` | `ServiceRequestController@validId` | `auth:sanctum` | — (streams from the private disk) |
| GET | `/api/borrowings` | `EquipmentBorrowingController@index` | `auth:sanctum` | — |
| POST | `/api/borrowings` | `EquipmentBorrowingController@store` | `auth:sanctum` | `equipment_id` (exists), `quantity` (int ≥1) |
| GET | `/api/borrowings/{id}` | `EquipmentBorrowingController@show` | `auth:sanctum` | — |
| GET | `/api/info-materials` | `InfoMaterialController@index` | `auth:sanctum` | — |
| GET | `/api/admin/analytics` | `AnalyticsController@getAdvancedAnalytics` | `auth:sanctum` + `is.admin` | — |
| GET | `/api/admin/service-requests` | `ServiceRequestController@adminIndex` | + `is.admin` | — |
| GET | `/api/admin/dashboard` | `AnalyticsController@index` | + `is.admin` | — |
| GET | `/api/admin/info-materials` | `InfoMaterialController@index` | + `is.admin` | — |
| POST | `/api/admin/info-materials` | `InfoMaterialController@store` | + `is.admin` | `title` (≤255), `file` (pdf/doc/docx/jpg/png/zip, ≤10 MB) |
| DELETE | `/api/admin/info-materials/{id}` | `InfoMaterialController@destroy` | + `is.admin` | — |
| GET | `/api/logs/system` | `SystemLogController@index` | + `is.admin` | — |
| POST | `/api/sms/blast` | `SmsController@sendBlast` | + `is.admin`, `throttle:3,60` | `message` (≤160), `barangays` (array ≥1 of existing `barangay_id`) |
| GET/POST/PUT/DELETE | `/api/vehicles[/{id}]` | `VehicleController` | + `is.admin` | `unit_identifier` (unique), `type` (Ambulance/Rescue Vehicle/Fire Truck/Boat), `status` (Available/Dispatched/Maintenance), `specification` (nullable) |
| GET/POST/PUT/DELETE | `/api/residents[/{id}]` | `ResidentController` | + `is.admin` | `barangay_id`, `first_name`, `last_name`, `phone_number`, `email_address` (unique), `status`; `middle_name`/`photo`/`otp`/`otp_verified_at` nullable |
| POST/PUT/DELETE | `/api/barangays[/{id}]` | `BarangayController` | + `is.admin` | `barangay_name` (≤255) |
| POST/PUT/DELETE | `/api/equipments[/{id}]` | `EquipmentController` | + `is.admin` | `item_name` (unique), `total_quantity` (≥1), `status` (Available/Unavailable); `available_quantity` ≤ `total_quantity` on update |
| POST/PUT/DELETE | `/api/services[/{id}]` | `ServiceController` | + `is.admin` | `service_name` (≤255), `description` (nullable) |
| PUT/PATCH | `/api/service-requests/{id}` | `ServiceRequestController@update` | + `is.admin` | any of `resident_id`, `service_id`, `processed_by`, `description`, `status`, `remarks`. `valid_id` deliberately rejected (arbitrary-file-read fix) |
| DELETE | `/api/service-requests/{id}` | `ServiceRequestController@destroy` | + `is.admin` | — |
| PUT/PATCH | `/api/borrowings/{id}` | `EquipmentBorrowingController@update` | + `is.admin` | `status` (Pending/Approved/Released/Returned/Denied) |
| DELETE | `/api/borrowings/{id}` | `EquipmentBorrowingController@destroy` | + `is.admin` | — |

## Appendix 2 — Mobile HTTP call inventory

Every HTTP call is centralised in `Mobile/lib/state/api_service.dart` — no screen calls `http` directly, which made this audit tractable.

| Call site | Target | Payload keys sent | Response keys read |
|---|---|---|---|
| `api_service.dart:102` `register()` | `POST /register` | `first_name`, `last_name`, `role`, `email_address`, `password`, `password_confirmation` | `errors`, `message`, `token` |
| `api_service.dart:134` `residentLogin()` | `POST /resident/login` | `email_address`, `password` | `token`, `user` → then `id`, `first_name`, `last_name`, `email_address`/`email`, `address` (`account_store.dart:23-31`) |
| `api_service.dart:154` `logout()` | `POST /logout` | `{}` | — (result discarded) |
| `api_service.dart:161` `getRequests()` | `GET /service-requests` | — | `data` / `requests` / first value; per item `id`, `service_id`, `description`, `remarks`, `status` (`request_models.dart:258-287`) |
| `api_service.dart:176` `submitRequest()` | `POST /service-requests` (multipart) | `service_id`, `description`, `required_vehicle_type` (never set), file field `valid_id` | same as above via `ServiceRequest.fromJson` |
| `api_service.dart:204` `cancelRequest()` | `PATCH /service-requests/{id}` | `status: 'Cancelled'` | — (result discarded) |

Not consumed anywhere in `Mobile/lib`: `has_valid_id`, `resident_id`, `request_id`, `vehicle_id`, `processed_by`, `created_at`, `barangay`.

---

## Suggested fix order

1. **C5** service id map — silently misfiles emergency requests. Fix first; it is the only finding that produces wrong data rather than no data.
2. **D1** Android `INTERNET` permission — one line, blocks every release build.
3. **C2** `request_id` — unblocks ref numbers and cancel.
4. **B1 + C1** registration endpoint — residents cannot sign up at all today.
5. **C4** resident cancel route — becomes a live 403 the moment C2 lands.
6. **C7** error surfacing — do this alongside 3–5, or the fixes will fail just as silently as the bugs did.
7. **C6** `GET /api/me`, **A1** unconsumed resident endpoints, **D6** token TTL.

Items 2, 3, 5(client half), and 6 touch `Mobile/` — teammate's area. Items 4, 5(route half), 7 and all of D except D1/D2 are backend-only.
