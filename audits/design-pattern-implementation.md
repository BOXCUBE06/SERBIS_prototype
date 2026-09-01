# Design Pattern Implementation — Mobile and Admin Panel

**Scope:** `Mobile/lib` (42 Dart files, 13,923 lines) and `Web/serbis-admin-vue/src`
(31 files, 11,524 lines).

**Date:** 2026-08-31. **Read-only. Nothing in this file has been applied.**

**Method.** Structural read of the two trees: every file under `state/` and
`models/` in mobile, every file under `composables/`, `config/` and `components/`
in the panel, plus the call-site counts quoted below, which came from grep over
the whole tree rather than from a sample.

**Headline: the two clients are at completely different levels of architectural
maturity, and it is not close.** The mobile app has a deliberate layered design —
an HTTP facade, a typed error object, a result type, an observable store, and
platform adapters via conditional import. The admin panel has **52 raw `fetch()`
calls across 16 files, 17 hand-built `Authorization` headers, and no HTTP client,
no store library and no model layer of any kind.** Both talk to the same Laravel
API.

That gap — not any individual pattern misuse — is the finding that matters.

---

## Severity summary

| # | Finding | Where | Severity |
|---|---------|-------|----------|
| 1 | No data-access layer at all in the panel: 52 raw `fetch()`, 17 hand-built auth headers | Web | **9/10** |
| 2 | No error model in the panel; each call site re-invents Laravel error decoding | Web | **8/10** |
| 3 | Model layer exists but the API layer does not return models | Mobile | 7/10 |
| 4 | `AppState` is one observable for four unrelated domains | `request_store.dart` | 7/10 |
| 5 | `ApiService` is a correct facade that has become a God object | `api_service.dart` | 6/10 |
| 6 | `window.fetch` is monkey-patched to intercept 401 | `apiSession.ts` | 6/10 |
| 7 | Two-language assumption is baked into a tuple type | `translations.dart` | 5/10 |
| 8 | `scope` string prop selects behaviour where a Strategy belongs | `ServiceRequestQueue.vue` | 5/10 |

---

## Finding 1 — the panel has no data-access layer

**Severity 9/10.**

```
ServiceRequestQueue.vue        13 fetch() calls
UsersView.vue                   5
StaffView.vue                   5
VehiclesView.vue                4
EquipmentBorrowingView.vue      4
ServicesConfigView.vue          3
EquipmentInventoryView.vue      3
ConductionRequestView.vue       3
SmsView.vue / LogsView.vue /
LoginView.vue / FilesView.vue   2 each
DashboardView.vue               1
residentPhoto.ts                1
apiSession.ts                   1
components/index.ts             1
                              ─────
                               52 total
```

`package.json` contains **no `pinia`, no `vuex`, no `axios`**. There is no
repository, no service layer, no client object. Every view is its own data layer.

The consequence is already visible and already documented in the repo:
`composables/adminUi.ts` exists because *"eleven views declared their own
`getHeaders`, seven their own `notify`, four their own `initials`"* — and its own
docblock records that `authHeaders()` **is not adopted anywhere yet**. So there
are still **17 hand-built `Authorization` headers** in the tree.

Compare the mobile app, which has exactly one place a request is built
(`ApiService._send`) and one place a token is attached.

This is what makes Finding 2 possible, and it is why
`ServiceRequestQueue.vue` reached 2,297 lines.

### Remediation

Do **not** start by adopting `authHeaders()` in eleven views — that is the
migration already deferred as "Findings 4 and 9", and it treats the symptom.
Introduce the missing layer, then let views move onto it one at a time.

```ts
// src/api/client.ts  (new)
import { API_BASE } from '@/config/api'
import { getToken } from '@/composables/authToken'

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** Laravel's field-keyed validation bag, when it sent one. */
    readonly errors: Record<string, string[]> | null = null,
  ) {
    super(message)
  }

  /** The first validation message, which is what every dialog in the panel shows. */
  get firstError(): string | null {
    return this.errors ? Object.values(this.errors)[0]?.[0] ?? null : null
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const isForm = init.body instanceof FormData

  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      Authorization: `Bearer ${getToken()}`,
      Accept: 'application/json',
      // Never set Content-Type for FormData: the browser has to generate the
      // multipart boundary itself, and overriding it sends a body no parser
      // can read. This trap is currently commented at three separate call sites.
      ...(isForm ? {} : { 'Content-Type': 'application/json' }),
      ...init.headers,
    },
  })

  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new ApiError(body.message ?? 'Request failed', res.status, body.errors ?? null)
  }

  return res.status === 204 ? (undefined as T) : res.json()
}

export const api = {
  get: <T>(p: string) => request<T>(p),
  post: <T>(p: string, body?: unknown) =>
    request<T>(p, { method: 'POST', body: body instanceof FormData ? body : JSON.stringify(body) }),
  patch: <T>(p: string, body: unknown) =>
    request<T>(p, { method: 'PATCH', body: JSON.stringify(body) }),
  del: <T>(p: string) => request<T>(p, { method: 'DELETE' }),
}
```

Then a view's data call becomes one line:

```js
// before — EquipmentInventoryView.vue
const res = await fetch(API, { headers: getHeaders() })
if (!res.ok) { /* hand-rolled */ }
const data = await res.json()

// after
const data = await api.get('/equipments')
```

**Migrate in this order**, one view per commit: `LogsView` and `SmsView` (2 calls
each, read-only), then `EquipmentInventoryView`, `VehiclesView`,
`ServicesConfigView`, then the large ones. `ServiceRequestQueue.vue` last, and
only alongside its split.

`authHeaders()` in `adminUi.ts` then becomes dead and should be **deleted rather
than adopted** — the client subsumes it. That closes the deferred eleven-view
migration by making it unnecessary.

---

## Finding 2 — no error model in the panel

**Severity 8/10.** The direct consequence of Finding 1, but worth its own entry
because the mobile app shows exactly what is missing.

Mobile has a purpose-built error type, and its docblocks explain the design:

```dart
// Mobile/lib/state/api_exception.dart
class ApiException implements Exception {
  final String message;
  final int? statusCode;

  /// The server's machine-readable reason, when it sent one — `email_unverified`,
  /// `invalid_code`, `resend_too_soon`. Screens route on this instead of matching
  /// the message text, which is written for a person and changes freely.
  final String? code;
  ...
}
```

"Screens route on this instead of matching the message text" is the right rule,
stated in the right place. It also has a proper result type for the one flow
where failure is expected rather than exceptional:

```dart
// Mobile/lib/state/verification_delivery.dart:61
class RegisterOutcome {
  const RegisterOutcome.failed(String this.error) : delivery = null;
  const RegisterOutcome.sent(this.delivery) : error = null;
  bool get failed => error != null;
}
```

The panel has neither. Each call site decodes Laravel by hand, and they do not
agree with each other. From `ServiceRequestQueue.vue:1928`:

```js
const errData = await res.json().catch(() => ({}))
const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
throw new Error(firstError || errData.message || 'Failed to file the request')
```

Seven other files instead do `console.error('Failed to fetch X:', error)` and show
a generic message, so a 422 with a real, actionable validation message from the
backend is silently reduced to "Failed to fetch data" in some views and surfaced
properly in others.

### Remediation

`ApiError` in Finding 1's snippet is the fix; it carries `status`, `errors` and
`firstError` so every view can do:

```js
catch (e) {
  dialog.error = e instanceof ApiError ? (e.firstError ?? e.message) : 'Something went wrong'
}
```

**Also worth copying from mobile:** route on a server `code` rather than on
message text wherever the backend sends one. The backend already emits
`email_unverified`, `invalid_code`, `resend_too_soon`, `already_verified`,
`mfa_required`, `not_found` — the panel currently reads none of them.

---

## Finding 3 — the model layer exists, but the API layer does not return models

**Severity 7/10.** The DTO pattern is half-applied in mobile.

There are real model classes — `request_models.dart` (824 lines),
`borrow_models.dart` (267), `advisory.dart`, `info_material.dart`,
`service_forms.dart` — with `fromJson` constructors and domain behaviour on them
(`timelineFor`, `labelFor`, `titleFor`).

But `ApiService` hands back raw maps. It contains **38 occurrences of
`Map<String, dynamic>`** and only **three** typed returns in the whole file:

```dart
Future<RegisterOutcome> register({...})              // :312
Future<VerificationDelivery?> resendVerificationCode({...})  // :388
Future<VerificationDelivery?> resendLoginCode({...})         // :427
```

Everything else is untyped:

```dart
Future<List<Map<String, dynamic>>> getRequests()      // :528
Future<List<Map<String, dynamic>>> getEquipments()    // :835
Future<List<Map<String, dynamic>>> getBorrowings()    // :843
Future<Map<String, dynamic>> me()                     // :440
```

So `fromJson` gets called from **eight different files** — `account_store.dart`
(9 times), `request_store.dart` (4), `api_service.dart` (4),
`material_cache_io.dart`, and the models themselves. The boundary where wire
format becomes domain object is not one line; it is scattered, and each caller
has to remember to cross it.

### Remediation

Move the parse inside the facade, so the boundary is the method signature:

```dart
// before
Future<List<Map<String, dynamic>>> getEquipments() async { ... }
// caller
final rows = await api.getEquipments();
final items = rows.map(Equipment.fromJson).toList();

// after
Future<List<Equipment>> getEquipments() async {
  final data = await _get('/equipments');
  return listFrom(data).map(Equipment.fromJson).toList();
}
```

`ApiService.listFrom` (`:621`) already exists for exactly this unwrapping, so the
plumbing is there.

Do this **together with** `code-quality-metrics-standards.md` Finding 6 (the
shared `asString`/`asInt`/`asInstant` coercions) — the same edit touches the same
constructors, and doing them separately means reading every `fromJson` twice.

**Leave `RegisterOutcome` and `VerificationDelivery` as they are.** They are
already the target shape.

---

## Finding 4 — one observable for four domains

**Severity 7/10.** The Observer pattern is implemented correctly and applied at
the wrong granularity.

```dart
// Mobile/lib/state/request_store.dart:44
class AppState extends ChangeNotifier {
```

837 lines, **28 `notifyListeners()` calls**, and it owns service requests,
equipment borrowings, info materials, advisories, the language setting and
several loading flags. Anything that listens to `AppState` rebuilds when *any* of
those change — a borrowing list refresh rebuilds the dashboard's advisories.

The class name is honest about it: `AppState`, not `RequestStore`, in a file
called `request_store.dart`. The filename and the class already disagree about
what it is.

### Remediation

Split by domain, keeping `ChangeNotifier` — no new dependency needed:

```dart
class RequestStore extends ChangeNotifier { ... }   // requests + timeline
class BorrowStore extends ChangeNotifier { ... }    // equipment borrowings
class LibraryStore extends ChangeNotifier { ... }   // materials + advisories
class SettingsStore extends ChangeNotifier { ... }  // language, sms_opt_in
```

If they need to be reached from one place, compose rather than merge:

```dart
class AppState {
  AppState(this.requests, this.borrowing, this.library, this.settings);
  final RequestStore requests;
  final BorrowStore borrowing;
  final LibraryStore library;
  final SettingsStore settings;
}
```

Screens then listen to the one they use.

**This is a large change and it is not urgent.** The app is small enough that the
extra rebuilds are not a measured problem — **Unable to verify** any actual
performance cost; that would need a profile run with the Flutter DevTools
timeline, which has not been done. Sequence this behind Findings 1 and 2, which
are on the panel and have visible consequences.

The existing comment at `request_store.dart:252` — explaining why one path
deliberately omits a synchronous `notifyListeners()` — should survive the split.
It documents a real ordering trap.

---

## Finding 5 — `ApiService` is a correct facade that outgrew itself

**Severity 6/10.** Noting what is right first, because the structure is sound.

`ApiService` (862 lines) is a **Facade** over HTTP with a small template-method
core: `_send` does the transport, `_get`/`_post`/`_patch` specialise it, `_decode`
normalises the response, `_errorMessage` turns a body into resident-facing text.
The token handling is properly encapsulated (`_readSecureToken`,
`_writeSecureToken`, `FlutterSecureStorage`), and `onUnauthorized` is a callback
hook rather than a hard dependency on the router. All of that is good.

The problem is that it is also the **only** service object, so it holds roughly
thirty endpoint methods spanning auth, profile, photos, service requests,
equipment, materials, advisories, barangays and file download.

### Remediation

Keep the transport core; split the endpoint surface onto it. This is
composition, not rewriting:

```dart
// lib/state/api/http.dart — the existing _send/_get/_post/_decode core
class ApiHttp { ... }

// lib/state/api/auth_api.dart
class AuthApi {
  AuthApi(this._http);
  final ApiHttp _http;
  Future<RegisterOutcome> register({...});
  Future<Map<String, dynamic>> residentLogin({...});
  Future<Map<String, dynamic>> verifyEmail({...});
}

// lib/state/api/requests_api.dart, borrowing_api.dart, library_api.dart
```

`ApiService` stays as the assembly point so existing call sites keep working
while the split happens.

**Low urgency.** Unlike the panel, this file is coherent and well commented — it
is long, not tangled. Do it when the auth surface next changes.

---

## Finding 6 — `window.fetch` is monkey-patched

**Severity 6/10.** A Decorator applied to a global. The intent is right and the
reasoning is documented; the mechanism is the concern.

```ts
// Web/serbis-admin-vue/src/composables/apiSession.ts:29
const originalFetch = window.fetch.bind(window)
window.fetch = async (input, init) => { ... }
```

The docblock argues the case well: *"Intercepting fetch once here beats threading
a handler through ~10 views: there is exactly one rule, and it cannot be
forgotten at a new call site."* Given Finding 1 — no client layer to put the rule
in — that is the correct call. It also guards re-entry with an `installed` flag
and correctly exempts the credential routes so a failed login is not redirected
to itself.

Three real costs:

1. **It mutates a global**, so it affects every `fetch` on the page including any
   third-party script, filtered only by the `url.startsWith(API_BASE)` check.
2. **It is untestable** without a DOM and a fake `window`.
3. **`installed` is module-level mutable state** — a hidden singleton. In tests
   or HMR, the second install is silently a no-op.

### Remediation

Once Finding 1's `api` client exists, the interceptor moves inside it and the
global is left alone:

```ts
// inside request(), replacing the monkey-patch
if (res.status === 401 && !isCredentialRoute(path)) {
  clearToken()
  if (router.currentRoute.value.path !== '/login') {
    router.push({ path: '/login', query: { expired: '1' } })
  }
}
```

**Do not remove `apiSession.ts` before the client exists and every view is on
it** — until then it is the only thing handling an expired session, and deleting
it early reintroduces the exact bug its docblock describes. Delete it in the same
commit that migrates the last view.

---

## Finding 7 — two languages are baked into the type, not just the data

**Severity 5/10.**

```dart
// Mobile/lib/state/translations.dart:2
const Map<String, (String, String)> _strings = {
  'common.cancel': ('Cancel', 'Kanselahin'),
```

The record type `(String, String)` means "English and Filipino" structurally. A
third language is not a data change, it is a type change plus every read site.
Combined with `enum AppLanguage { english, filipino }` and the 54 `filipino:` call
sites in `readability-and-naming.md` Finding 1, the two-language assumption is
expressed in three separate places.

**Being fair about this:** SERBIS serves one municipality, and English plus
Filipino may well be the permanent requirement. This is only a defect if a third
language is ever plausible. It is listed because the cost is invisible until the
day it is asked for, and then it is large.

### Remediation

If a third language is genuinely possible, the map key changes and nothing else
has to move at once:

```dart
const Map<String, Map<AppLanguage, String>> _strings = {
  'common.cancel': {
    AppLanguage.english: 'Cancel',
    AppLanguage.filipino: 'Kanselahin',
  },
};
String tr(String key, AppLanguage lang) => _strings[key]?[lang] ?? key;
```

If a third language is not plausible, **write that down in the file's docblock**
and close the question. A stated assumption is not a defect; an unstated one is.

---

## Finding 8 — a string prop selecting behaviour

**Severity 5/10.** Cross-referenced from
`code-quality-metrics-standards.md` Finding 2, recorded here as the pattern.

```vue
<ServiceRequestQueue scope="other" />
<ServiceRequestQueue scope="ambulance" :standalone="false" @dispatch-booking="..." />
```

`props.scope === 'ambulance'` appears inside the component's methods, including
twice in `submitWalkIn` alone. This is a Strategy selected by a string, with the
strategies inlined as conditionals — so every new difference between an ambulance
queue and an ordinary queue adds a branch to a shared 994-line script rather than
a line to one of two small files.

### Remediation

The composable extraction in the metrics audit is the fix. If the two screens'
behaviour keeps diverging, make the strategy explicit:

```ts
interface QueueBehaviour {
  endpoint: string
  allowsScheduling: boolean
  validate(form: WalkInForm): string | null
}

const AMBULANCE: QueueBehaviour = { ... }
const GENERAL: QueueBehaviour = { ... }
```

**Only if it keeps diverging.** Two conditionals do not justify a strategy
interface; ten do. Right now the composable split is enough.

---

## Patterns used well — do not "fix" these

- **Platform adapter via conditional import.** Textbook, and used twice:

  ```dart
  // Mobile/lib/state/file_opener.dart:5
  import 'file_opener_unsupported.dart'
      if (dart.library.io) 'file_opener_io.dart' as platform;
  // Mobile/lib/state/material_cache.dart:5
  ```

  The web build gets a stub, the mobile build gets the real implementation, and
  no caller branches on platform. This is the right way to do it in Dart.

- **`ApiException` as a routing surface.** Carrying a server `code` and routing on
  it rather than on message text is the correct design and is documented as such.

- **`RegisterOutcome`** — a two-constructor result type for a flow where failure
  is ordinary rather than exceptional. Correct use, correctly scoped to the one
  place it belongs.

- **`config/api.ts` failing loudly.** Throwing when `VITE_API_BASE` is unset,
  with no localhost fallback, is deliberate and explained: *"a fallback is how a
  bundle quietly ships pointing at nothing."*

- **The composables that exist are properly shaped.** `authToken.ts`,
  `residentStatus.ts`, `rowNumber.ts`, `residentPhoto.ts`, `useAppTheme.ts` are
  small, single-purpose, typed, and documented. The panel's problem is not that
  its abstractions are wrong — it is that there are only seven of them for 9,000
  lines of screens.

- **`ResolvesUploadDisks` trait** (backend, landed 2026-08-31) — the same
  instinct applied on the Laravel side. Worth carrying into the panel.

---

## Missing patterns worth adding

| Pattern | Where | Why |
|---|---|---|
| **Repository / API client** | Panel | Finding 1. The single highest-value change in either codebase. |
| **Typed error object** | Panel | Finding 2. `ApiError` with Laravel's validation bag. |
| **DTO boundary at the facade** | Mobile | Finding 3. Models exist; the facade should return them. |
| **Per-domain stores** | Mobile | Finding 4. One `ChangeNotifier` per domain, composed. |

Explicitly **not** recommended: adding Pinia, adding axios, or introducing a
Builder or Abstract Factory anywhere. The panel does not need a state library —
it needs a client and typed responses. Anything larger is ceremony that would
have to be justified to a review panel and would not pay for itself at this size.

---

## Suggested order

1. **Finding 1** — build `src/api/client.ts`. One file, no migration yet.
2. **Finding 2** — `ApiError` ships with it.
3. Migrate views onto the client, smallest first, one per commit. This is also
   where each view gets `lang="ts"` (metrics audit Finding 1), because typing a
   view is far easier once its data calls return a declared shape.
4. **Finding 6** — retire the `fetch` monkey-patch in the commit that migrates
   the last view, not before.
5. **Finding 3** with metrics-audit Finding 6 — one pass over the mobile models.
6. **Findings 4, 5, 7, 8** — none is urgent; take them when the relevant file is
   next open.

**Sequencing note.** Steps 1–4 are the panel and steps 5–6 are mobile; they do
not touch the same files and can run independently. If only one gets done, do the
panel — it is where the gap is.
