# Mobile App — Internal Quality Audit & Task List

**Date:** 2026-07-25
**Scope:** `Mobile/` (Flutter) — the app's own internal quality. Backend/mobile
*contract* mismatches are already covered in `docs/mobile-integration-gaps.md`
and are referenced here by their IDs (C1–C9, D1–D6, A1, B1) rather than repeated.
**Method:** full read of all 18 files in `Mobile/lib`, plus `pubspec.yaml`,
`analysis_options.yaml`, both Android manifests, `build.gradle.kts`, and
`ios/Runner/Info.plist`. Cross-checked against branch `mobile-c1-c6-c7`.
**Read-only.** No file under `Mobile/` was modified.

---

## ⚠️ Read this before starting: two versions of this app exist

Line numbers below refer to the **working tree of `update-admin-vue`** — what is
on disk today. Branch **`mobile-c1-c6-c7` (`d8d9262`, pushed, PR not opened)**
already fixes several findings. Each affected task is tagged
**`[fixed on mobile-c1-c6-c7]`**.

**Step 0 of any work here is to merge that branch.** Otherwise you will re-fix
M5, M6, M12, M17 by hand and then hit a merge conflict.

What the branch does **not** fix, and what this audit is really about: the
emergency hotlines don't dial, five form fields are collected and thrown away,
the success sheet fires whether or not the request reached the server, the
reference number is fiction, and the "offline" library is a 700 ms animation.

---

## Fix First

Highest severity × lowest effort, in the order they should be done.

| # | Task | Why first |
|---|---|---|
| 0 | **Merge `mobile-c1-c6-c7`** | Not a task in this list. Everything below assumes it. |
| 1 | **M2 — stop discarding five form fields** | S effort, Critical. The patient's condition and the caller's phone number are typed in and deleted before the request is sent. Pure data loss, zero UI work. |
| 2 | **M3 — don't show the success sheet on a failed submit** | S effort, Critical. Needs M5's rollback (on the branch) to already exist. |
| 3 | **M4 — use the server's reference number, not a client counter** | S effort, Critical. The number the resident reads out to MDRRMO currently matches no record anywhere. |
| 4 | **M1 — make the hotline rows actually dial** | S–M effort, Critical. One dependency + one `launchUrl`. This is the app's only life-threatening-emergency path and it is a snackbar. |
| 5 | **M8 — stop claiming "cancelled" before the server says so** | S effort, High. Two-line change once M5 is in. |

---

## Sequencing: fixes that unmask hidden bugs

Explicit, because this codebase has a history of one silent failure hiding
another (see `mobile-integration-gaps.md` C2 → C4).

- **M5/M6 (error propagation) must land before M3 and M8.** Both are "stop
  lying about success" fixes, and neither has anything true to say until
  failures actually reach the UI. Merging `mobile-c1-c6-c7` satisfies this.
- **M4 (real ref number) unmasks M3.** Once the ref comes from the server, a
  failed submit has *no* ref to display — the confirmation sheet will render
  `Ref #` with nothing after it. Land M3 in the same change or the fix looks
  like a new bug.
- **M2 (stop discarding fields) unmasks nothing but changes the payload shape.**
  `description` grows by 2–3 lines. Backend validates `description` as
  `required|string` with no length cap, so this is safe — but confirm the admin
  panel's request-detail view doesn't truncate before shipping.
- **M11 (fetch `/info-materials`) unmasks the `full_url` / `APP_URL` deploy
  item** in the gaps report (section D). Today no client reads those URLs, so a
  wrong `APP_URL` is invisible. The moment the Library fetches, every download
  link points at whatever `APP_URL` says.
- **M7 (secure token storage) will log every existing user out once** unless the
  migration reads the old `shared_preferences` key first. Plan the one-time
  read-and-move, don't just swap the storage backend.
- **M21 (refresh request list) makes M16 and M27 visible constantly.** Right now
  server-loaded rows are only seen once per launch, so the wrong icon and the
  missing timeline are easy to miss. Add refresh and they are on screen all day.
- **M10 (real offline caching) supersedes M11's simplest form.** Don't build
  "fetch the list" and then rebuild it for caching — decide the cache layer
  first, then fetch through it.

---

# Critical

Anything that can cause a wrong, lost, or falsely-confirmed emergency request.

---

- [ ] **M1 — Make emergency hotline rows place a real phone call**
      **Severity:** Critical
      **Category:** UX Gaps (also Correctness)
      **Location:** `Mobile/lib/widgets/sos_button.dart:70-73`;
      `Mobile/lib/screens/library_screen.dart:115`;
      `Mobile/lib/screens/services_screen.dart:535-538`
      **Problem:** Tapping any hotline — including the red SOS button, the one
      control designed for a life-threatening emergency — calls
      `showAppSnackBar(context, 'Calling $title · $sub')` and nothing else. No
      call is placed. The snackbar says "Calling MDRRMO · 0917-123-4567" and
      auto-dismisses in 2 seconds, so a panicking resident sees a confirmation
      that a call is happening while their phone does nothing. `url_launcher`
      is not even in `pubspec.yaml`. The hotline block inside the Services
      screen's safety notice (`:535-538`) is not tappable at all — plain `Text`.
      **Fix:**
      1. Add `url_launcher: ^6.3.0` to `pubspec.yaml`.
      2. Replace the snackbar with
         `await launchUrl(Uri(scheme: 'tel', path: '09171234567'))`; strip
         non-digits from the display string before building the URI.
      3. Android: add a `<queries><intent><action android:name="android.intent.action.DIAL"/></intent></queries>`
         entry to `android/app/src/main/AndroidManifest.xml` (Android 11+ package
         visibility blocks the intent otherwise).
      4. iOS: add `tel` to `LSApplicationQueriesSchemes` in `ios/Runner/Info.plist`.
      5. If `launchUrl` returns false (tablet, web build, no dialer), copy the
         number to the clipboard and show it in a persistent dialog — never a
         2-second snackbar.
      6. Make the `services_screen.dart:547` `_hotlineLine` rows tappable too.
      **Effort:** S
      **Blocks:** M28

---

- [x] **M2 — Stop discarding five form fields before submitting**
      **Done 2026-07-26 (`fcf4459`).** Verified live: blank contact refused on
      the ambulance form, and `tbl_service_request.description` for SR-31 came
      back carrying `Condition:` and `Contact:`.
      **Severity:** Critical
      **Category:** Correctness
      **Location:** `Mobile/lib/screens/services_screen.dart:166-208` (builds
      `metaLines`) vs `:359-372`, `:387-392`, `:402-419` (collects the fields)
      **Problem:** The forms collect fields that are never written into the
      request. The ambulance form asks for **condition/notes** (`amb_notes`,
      `:363`) and a **contact number** (`amb_contact`, `:371`); `metaLines` at
      `:170-175` uses only patient, pickup and destination. The road form
      collects a **description** (`road_description`, `:391`) and drops it. The
      relief form collects **household size** (`relief_size`) and a **contact
      number** (`relief_contact`) and drops both. `description` is
      `metaLines.join('\n')`, so those five values never leave the device.
      A dispatcher receiving an ambulance request therefore has no patient
      condition and **no callback number**. Still present on `mobile-c1-c6-c7`.
      **Fix:** Add the missing entries to each `metaLines` list:
      ambulance → `'Condition: ${_orFallback(_text('amb_notes'), 'Not described')}'`
      and `'Contact: ${_orFallback(_text('amb_contact'), 'Not specified')}'`;
      road → the `road_description` value; relief → `'Household size: …'` and
      `'Contact: …'`. Then add a validator so the contact-number field cannot be
      left empty on the ambulance and relief forms — a callback number is not
      optional in a dispatch. Confirm the admin request-detail view renders
      multi-line `description` without truncating.
      **Effort:** S
      **Blocks:** —

---

- [x] **M3 — Don't show the success sheet when the submit failed**
      **Done 2026-07-26 (`982ca1c`).** Verified live with the backend stopped
      mid-flow: no success sheet, persistent error card with Retry, entered
      values and the ID photo kept, and no phantom row left in Track.
      **Severity:** Critical
      **Category:** Error Handling & Observability
      **Location:** `Mobile/lib/screens/services_screen.dart:227-248`
      **Problem:** `await widget.appState.addRequest(...)` returns `void` and
      swallows its own failure, then `_ConfirmationSheet` is shown
      unconditionally — green check, "Request submitted", reference number,
      "View tracking" button. On `update-admin-vue` the failure is silent, so a
      resident whose ambulance request 422'd or timed out walks away believing
      help is dispatched. On `mobile-c1-c6-c7` it is *worse in a different way*:
      the optimistic row is now rolled back and a red snackbar is queued, so the
      resident sees a success sheet, a red error behind it, and an empty Track
      screen — three contradictory signals at once.
      **Fix:** Have `AppState.addRequest` return `Future<ServiceRequest?>`
      (`null` on failure) or rethrow. In `_submit`, only call
      `showModalBottomSheet` on a non-null result; on failure show a persistent
      error card in the form with a **Retry** button that resubmits the same
      payload, and keep the entered values so nothing has to be retyped.
      **Effort:** S
      **Blocks:** M4

---

- [x] **M4 — Use the server's reference number, not a client-side counter**
      **Done 2026-07-26 (`982ca1c`).** Verified live: the sheet read
      `Reference #SR-31` against DB `request_id=31`. There is no reference
      column on the server — `request_id` is the only identity it returns.
      **Severity:** Critical
      **Category:** Correctness
      **Location:** `Mobile/lib/state/request_store.dart:27,48-52`;
      consumed at `services_screen.dart:161`, `shared_widgets.dart:511-512`,
      `track_screen.dart:218`, `dashboard_screen.dart:105`
      **Problem:** `nextRefNo()` returns `'QR-2026-${_refCounter++}'` starting at
      101, held in memory only. It resets to `QR-2026-101` on every app launch,
      is identical across all residents' devices, and has no relationship to the
      server, which keys requests on `request_id` and which the app renders as
      `SR-$id` (`request_models.dart:363`). So the number printed on the
      confirmation sheet and read out to MDRRMO over the phone matches no record
      in `tbl_service_request`, and two residents can quote the same one.
      **Fix:** Delete `nextRefNo()` and `_refCounter`. Until the server responds,
      show "Reference number pending" instead of a fabricated one — the optimistic
      row can carry `refNo: ''`. Once `addRequest` returns the confirmed row, use
      its `SR-$request_id`. Then fix the two places that key off `refNo` as an
      identity: `AppState.cancelRequest(String refNo)` (`request_store.dart:96`)
      and `_expanded` in `track_screen.dart:26` should both key on the request
      id, since `refNo` is empty for unconfirmed rows and collides across them.
      **Effort:** M
      **Blocks:** M3 (land together)

---

- [x] **M5 — Roll back optimistic updates when the server rejects them**
      **Done — landed with the `mobile-c1-c6-c7` merge (`0ce1c00`); box ticked
      2026-07-29.** `request_store.dart` now routes both failure paths through
      `_fail(e)` and removes the optimistic row: `requests.remove(request)` on
      the submit path (`:245`) and on the cancel path (`:271`). No bare
      `catch (_) {}` remains on either mutation. **Confirmed by reading the
      merged code, not by a live 422** — the entry asked for a live rejection
      and that has not been run.
      **Severity:** Critical
      **Category:** Correctness
      **Location:** `Mobile/lib/state/request_store.dart:71-93` (insert, then
      `catch (_) {}` at `:93`); `:108-129` (cancel, then `catch (_) {}` at `:129`)
      **Problem:** Both mutations write local state first and discard the
      exception. A 422 "No available vehicles at this time." leaves a Pending
      request on the resident's Track screen that MDRRMO never received, and it
      survives until the next `loadRequests()`. Cancel is the same in reverse:
      the row shows Cancelled while the server still has it open and a crew may
      be dispatched.
      **Fix:** Already implemented on the branch — `requests.remove(request)` on
      submit failure, restore `current` on cancel failure, both routed through
      `_fail(e)`. **Merge the branch; do not reimplement.** Verify with a live
      422 before closing.
      **Effort:** S (merge only)
      **Blocks:** M3, M8

---

- [x] **M6 — Inspect the HTTP status code; a 401 is not an empty list**
      **Done — landed with the `mobile-c1-c6-c7` merge (`0ce1c00`); box ticked
      2026-07-29.** `api_service.dart:11` defines `ApiException(message,
      {statusCode})` with `isUnauthorized` (`:19`) and `isNetwork`
      (`statusCode == null`, `:22`); `:219` fires `onUnauthorized`, wired to
      `_onSessionExpired` at `main.dart:59`. The entry's specific check passes:
      the `data.values.first` fallback is now guarded by `data.isEmpty ? null :`
      (`:382`), so an empty map no longer throws `Bad state: No element`.
      **Confirmed by reading the merged code, not against a live 401.**
      **Severity:** Critical
      **Category:** Error Handling & Observability
      **Location:** `Mobile/lib/state/api_service.dart:83-93` (`_decode` drops
      `response.statusCode` entirely and returns `{}` on non-JSON);
      `:160-167` (`getRequests`)
      **Problem:** `_decode` never looks at the status. On a 401 the body is
      `{"message":"Unauthenticated."}`, so `data['data'] ?? data['requests'] ??
      data.values.first` yields the *string* `"Unauthenticated."`, which is not a
      `List`, so `getRequests()` returns `[]`. An expired token, a revoked token
      and a resident with genuinely no requests are indistinguishable — the app
      shows the friendly "No requests yet" empty state to a logged-out user.
      Separately, if the body is not JSON at all (proxy error page, captive
      portal), `_decode` returns `{}` and `data.values.first` throws
      `Bad state: No element` — which the caller's `catch (_) {}` also eats.
      Note `getServices` (`:171`) guards this case and `getRequests` does not.
      **Fix:** Branch adds `ApiException(message, statusCode)` thrown from
      `_decode`, an `isUnauthorized` / `isNetwork` split, and an
      `onUnauthorized` callback wired to a forced logout in `main.dart`.
      **Merge the branch.** While verifying, confirm `getRequests` no longer
      reaches `data.values.first` on an empty map.
      **Effort:** S (merge only)
      **Blocks:** M3, M5, M8, M17

---

# High

---

- [x] **M7 — Move the auth token out of plain SharedPreferences**
      **Done 2026-07-28 (`fa778d2`).** `flutter_secure_storage: ^9.2.0`
      (`pubspec.yaml:19`) with `AndroidOptions(encryptedSharedPreferences: true)`
      — Keychain on iOS, EncryptedSharedPreferences on Android. The one-time
      legacy migration is in place: `loadToken` still reads the old
      `serbis_token_v1` prefs key, writes it to secure storage and deletes it,
      so existing installs are not logged out. Carries the project's first
      tests — `test/api_service_token_test.dart`, 5 cases over `loadToken`.
      **Never run on a device**; the token TTL decision for audit #30 is still
      open.
      **Severity:** High
      **Category:** Auth & Session
      **Location:** `Mobile/lib/state/api_service.dart:12,16-33`
      **Problem:** The Sanctum token is stored under `serbis_token_v1` in
      `SharedPreferences` — an unencrypted XML file on Android
      (`/data/data/<pkg>/shared_prefs/`) and a plain `NSUserDefaults` plist on
      iOS. It is readable by any process with the app's data directory on a
      rooted/jailbroken device, and survives in device backups. That matters more
      here than usual because audit **#30** is still open: Sanctum tokens have
      **no expiry**, so a token lifted from a backup is a permanent credential to
      a resident account holding a government ID scan. Unchanged on the branch.
      **Fix:** Add `flutter_secure_storage: ^9.2.0` (Keychain on iOS, EncryptedSharedPreferences
      on Android). On first run, read the legacy `serbis_token_v1` key, write it
      to secure storage, then delete it from prefs — otherwise every existing
      install is logged out. Keep the prefs read path for exactly one release,
      then remove it. Pair with a decision on #30's token TTL.
      **Effort:** M
      **Blocks:** —

---

- [x] **M8 — Stop announcing "cancelled" before the server confirms it**
      **Done 2026-07-26 (`04524aa`).** Verified live both ways: with the server
      stopped, spinner then no green message and the row reverted to Under
      review; with it running, green message and DB `status=Cancelled`.
      Signature is `Future<bool>`, not the `Future<void>` this entry specified —
      `cancelRequest` catches its own exception for the M5 rollback, so it never
      throws and "resolved without throwing" cannot distinguish the two.
      **Severity:** High
      **Category:** Error Handling & Observability
      **Location:** `Mobile/lib/widgets/shared_widgets.dart:524-532`
      **Problem:** The confirm button calls `onConfirmed()` — a `VoidCallback`
      wrapping a `Future` nobody awaits — and then immediately shows
      "Request #… has been cancelled." The message is emitted before any HTTP
      call completes and regardless of its outcome. On the branch the row is
      correctly rolled back and a red error follows, but the green claim still
      appears first; on a slow connection the resident sees "cancelled" for
      several seconds before it is contradicted.
      **Fix:** Change `showCancelDialog`'s parameter to
      `Future<void> Function() onConfirmed`. Await it, show a spinner on the
      dialog's confirm button while it runs, and emit the success snackbar only
      after it resolves without throwing. Let the store's `lastError` drain
      handle the failure path.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M9 — Fix the release build: debug signing key and `com.example` app ID**
      **Severity:** High
      **Category:** Build & Release Readiness
      **Location:** `Mobile/android/app/build.gradle.kts:19,30-33`
      **Problem:** `applicationId = "com.example.mobileapp"` and
      `release { signingConfig = signingConfigs.getByName("debug") }`. Both are
      the unmodified Flutter template TODOs. The Play Store rejects any
      `com.example.*` package outright, and an APK signed with the shared debug
      keystore can be re-signed by anyone and cannot be updated once a real key
      is used. `namespace` is the same placeholder. This is a hard blocker on
      distributing the app at all.
      **Fix:** Set `applicationId`/`namespace` to something like
      `ph.gov.echague.serbis`. Generate a release keystore, store it outside the
      repo, reference it from `key.properties` (gitignored), and add a real
      `signingConfigs.release`. Do this **before** the first install goes to any
      resident — changing the application ID afterwards forces a full uninstall.
      **Effort:** M
      **Blocks:** M20

---

- [x] **M10 — The offline "Download / Saved" pill saves nothing**
      **Done 2026-07-28.** `state/material_cache.dart` (+ `_io` / `_unsupported`
      halves behind a conditional import, because importing `dart:io` at all
      breaks the web build) writes materials to
      `<app documents>/materials/<id>.<ext>` and keeps an index in
      `SharedPreferences`. `OfflinePill` is now stateless and only draws
      `AppState.savedMaterials`; the 700 ms `Future.delayed` is gone. Downloads
      write to a `.part` sibling and rename, so a killed download cannot leave a
      truncated file the index calls complete, and `loadIndex` prunes any entry
      whose file has vanished. Profile's "5 saved · 4.2 MB used" is computed
      from the index and its list offers a real Remove. Bundled articles show a
      non-tappable "Saved" — they ship in the binary, which is the one honest
      claim in the old UI. Covered by 8 tests in `test/material_cache_test.dart`
      asserting against a real temp directory. **Not verified on a device**: no
      Chrome on the XAMPP box, and web has no documents directory by design.
      **Severity:** High
      **Category:** UX Gaps (also Correctness)
      **Location:** `Mobile/lib/widgets/shared_widgets.dart:264-279`
      **Problem:** `OfflinePill._handleTap` runs
      `await Future.delayed(const Duration(milliseconds: 700))`, flips a local
      bool, and shows "…saved for offline use." No file is written, no cache
      exists, and the state resets the moment the widget is rebuilt. The initial
      `saved:` value is a hardcoded literal per row
      (`library_screen.dart:68-103`). Offline access to safety materials is
      **scope item #4** of the five the app is meant to have, and the user called
      it crucial; today the feature is a 700 ms animation. `path_provider` is not
      in `pubspec.yaml`.
      **Fix:** Build a real cache layer before touching the pill: add
      `path_provider`, write fetched materials to
      `getApplicationDocumentsDirectory()/materials/<id>`, and keep an index
      (id → local path, bytes, fetched-at) in `shared_preferences` or a small
      SQLite table. Drive `saved` from that index, show real progress during the
      download, and surface a failure state. Land with M11 — one fetch path, not two.
      **Effort:** L
      **Blocks:** M11

---

- [x] **M11 — Library content is 694 lines of hardcoded Dart; `/info-materials` is never called**
      **Done 2026-07-28.** `ApiService.getInfoMaterials()` + `InfoMaterial`
      model + `AppState.loadMaterials()`, rendered by a new "MDRRMO Documents"
      section in the Library. The index loads before the network so the list is
      on screen with no signal; a failed refresh renders the saved copies with a
      "showing your saved copies" notice and a Retry, never an empty Library.
      The duplicated list in `_OfflineMaterialsPage` is gone. The bundled
      articles stay as the fallback for a fresh install with no signal, as the
      fix note asked. Covered by 8 tests in `test/materials_store_test.dart`.
      **Left open — a downloaded file cannot be opened yet** (see M33); this
      change makes the cache real, not the viewer.
      **Severity:** High
      **Category:** Correctness (hardcoded values that should come from the API)
      **Location:** `Mobile/lib/data/safety_files.dart` (688 lines);
      `Mobile/lib/screens/library_screen.dart:68-103`;
      `Mobile/lib/screens/profile_screen.dart:397-406`
      **Problem:** Every article, its title, subtitle, page count and
      "saved" flag are literals compiled into the binary. The admin panel has a
      full publish flow (`InfoMaterialController@store`, admin UI, and now
      `InfoMaterialSeeder`), and `GET /api/info-materials` exists and is
      resident-facing — but nothing in `Mobile/lib` calls it (gaps report **A1**).
      An advisory published during a live disaster cannot reach residents without
      an app store release. The `_OfflineMaterialsPage` list at
      `profile_screen.dart:397-406` is a second hardcoded copy of the same eight
      keys, so they must be kept in sync by hand.
      **Fix:** Add `getInfoMaterials()` to `ApiService`, a `InfoMaterial` model,
      and a store method mirroring `loadServices`. Render the Library from the
      response through M10's cache, falling back to the bundled articles when the
      cache is empty and the network is down (that fallback is genuinely worth
      keeping — it is the only content available on a fresh install with no
      signal). Delete the duplicated list in `_OfflineMaterialsPage` and read the
      same store.
      **Effort:** L
      **Blocks:** —

---

- [x] **M12 — Registration password rule is wrong in both directions**
      **Done — landed with the `mobile-c1-c6-c7` merge (`0ce1c00`); box ticked
      2026-07-29.** `register_screen.dart` now validates `length < 8` (`:95`)
      plus `_upper` / `_lower` / `_digit` (`:45-47`), matching the backend's
      `Password::min(8)->mixedCase()->numbers()`. Both `maxLength: 8` caps are
      gone — no `maxLength` remains in the file. The C1 gaps are closed too: a
      barangay picker fed by `GET /barangays` (`:40-43`, `:112-124`) and a
      `_phoneCtrl` mobile-number field (`:198-204`). `GET barangays` sits
      outside the `auth:sanctum` group at `routes/api.php:26`, so a
      signing-up resident can reach it. **Route confirmed by reading the route
      file, not exercised live.**
      **Severity:** High
      **Category:** Correctness
      **Location:** `Mobile/lib/screens/auth/register_screen.dart:51-59`,
      and `maxLength: 8` at `:168` and `:188`
      **Problem:** `_validatePassword` requires **exactly** 8 characters
      (`value.length != 8`) plus a special character, and never checks for a
      lowercase letter. The backend requires
      `Password::min(8)->mixedCase()->numbers()`. So a strong 14-character
      password is rejected on the device, while `PASADA1!` passes every client
      check and is refused by the server for failing `mixedCase()` — with the
      server's raw validation message as the only explanation. `maxLength: 8` on
      both fields physically prevents typing a longer one. The screen also
      collects no `barangay_id` and no `phone_number`, both required by the
      rebuilt `POST /api/register` (gaps report **C1**).
      **Fix:** Branch replaces the rule with `min(8)` + upper + lower + digit,
      drops both `maxLength: 8` caps, and adds a barangay picker fed by
      `GET /barangays` plus a mobile-number field. **Merge the branch.**
      Note the branch also moved `GET /barangays` out of the `auth:sanctum`
      group — a signing-up resident has no token, so the picker could never have
      loaded. Exercise that route live before closing this.
      **Effort:** S (merge only)
      **Blocks:** —

---

- [ ] **M13 — Profile screen shows invented identity and its "save" is local-only**
      **Severity:** High
      **Category:** Correctness (also UX Gaps)
      **Location:** `Mobile/lib/screens/profile_screen.dart:40-42,82,157,190-239`
      **Problem:** Three separate fictions on one screen. (1) The defaults are
      hardcoded fake data — `'Juan Delacruz'` and `'Echague, Isabela'` — so a
      resident whose profile failed to load sees a plausible-looking name that is
      not theirs (`:40-42`). (2) "Update information" writes to three local
      `String`s and shows "Profile information updated." — no API call exists,
      and the values are gone on the next launch (`:232-239`). (3) The offline
      row's subtitle is the literal `'5 saved · 4.2 MB used'` (`:157`) and the
      avatar edit button says "Photo picker would open here." (`:82`). In a
      system where the profile determines which barangay an emergency request is
      attributed to, an invented default address is a triage risk.
      **Fix:** Drop the fake defaults — render an em dash or a "Couldn't load
      your profile — tap to retry" row instead. Either wire the edit sheet to a
      real `PATCH` (needs a resident-scoped profile-update route on the backend,
      which does not exist yet — file it) or remove the Save button and make the
      sheet read-only with "Contact MDRRMO to update your details," matching the
      existing "Forgot password?" copy. Compute the offline figure from M10's
      index. Remove or implement the photo picker.
      **Effort:** M
      **Blocks:** —

---

- [x] **M14 — API base URL defaults to `127.0.0.1`**
      **Done 2026-07-29.** The default is gone rather than replaced: no
      production domain exists yet (the cloud plan still writes `api.<domain>`),
      and the panel made the same call in `Web/serbis-admin-vue/src/config/api.ts`
      — a fallback is how a build ships pointing at nothing. `ApiService.baseUrl`
      is now `String.fromEnvironment('API_BASE_URL')` with no `defaultValue`,
      trailing slashes stripped, and `main()` refuses to start an unconfigured
      build, showing a red screen naming the missing define. When the domain is
      picked it goes in the build environment, not in the source.
      Verified live in a real `flutter build web --release`: built with no
      define and served, the app shows *"This build has no API address"* and
      the `flutter run --dart-define=...` command; rebuilt with
      `--dart-define=API_BASE_URL=http://localhost:8000/api`, the login screen
      renders as before. `test/api_base_url_test.dart` passes under both
      `flutter test` and `flutter test --dart-define=API_BASE_URL=https://api.example.test/api//`
      (the second also proves the trailing slashes are stripped); restoring the
      old `defaultValue` fails 2 of its 3 tests, so they are not vacuous.
      `flutter analyze` is back to its 25-issue baseline and all 24 tests pass.
      Cleartext: the main manifest now sets `android:usesCleartextTraffic="false"`
      and `android/app/src/debug/res/xml/network_security_config.xml` re-permits
      it for `10.0.2.2`, `localhost` and `127.0.0.1` only — under `src/debug/`,
      so it is never merged into profile or release. Both commands and the
      per-target addresses are documented in the new `Mobile/README.md`.
      **Not verified:** the Android manifest merge. This box has no Android SDK
      (`flutter build apk` exits with "No Android SDK found"), so the XML is
      only checked for well-formedness. Confirm on a machine with the SDK.
      **Severity:** High
      **Category:** Build & Release Readiness
      **Location:** `Mobile/lib/state/api_service.dart:8-11`
      **Problem:** `String.fromEnvironment('API_BASE_URL', defaultValue:
      'http://127.0.0.1:8000/api')`. The override mechanism is right, the default
      is backwards: a release build produced without `--dart-define` ships
      pointing at the handset itself, and every call fails with a socket error
      the resident can't interpret. The failure mode is silent-at-build-time and
      total-at-runtime. Also `http://` — Android 9+ and iOS ATS both block
      cleartext by default, so this URL cannot work in release even on a LAN
      without an explicit exemption. Unchanged on the branch. (Gaps report **D2**.)
      **Fix:** Make the default the production HTTPS URL and pass
      `--dart-define=API_BASE_URL=http://10.0.2.2:8000/api` for local work — then
      a forgotten flag breaks a developer's build loudly instead of a resident's
      install silently. Add a debug-only `networkSecurityConfig` for LAN HTTP
      testing; never enable cleartext in release. Document both commands in a
      `Mobile/README.md`.
      **Effort:** S
      **Blocks:** —

---

- [x] **M15 — The submit button has no in-flight guard**
      **Done 2026-07-26 (`69fdbdb`).** Verified live: with the POST delayed 6 s,
      three taps on Submit produced one row (32 → 33) and the button showed its
      spinner disabled throughout.
      **Severity:** High
      **Category:** UX Gaps
      **Location:** `Mobile/lib/screens/services_screen.dart:145,293`
      **Problem:** `AppButton(label: …, onPressed: _submit)` passes no `loading`
      flag, and `_submit` sets no state while the multipart upload runs. The
      upload includes a photo and has a 30-second timeout, so on a weak
      connection there is a long window with no visible feedback — exactly when a
      user taps again. Each tap inserts another optimistic row and fires another
      `POST /service-requests`, producing duplicate live requests in the
      dispatcher's queue. `AppButton` already supports `loading:` (used correctly
      by both auth screens), so the mechanism exists and just isn't used here.
      **Fix:** Add `bool _submitting = false`; set it at the top of `_submit`,
      clear it in a `finally`; pass `loading: _submitting` to the button and
      return early if already true. Do the same for the cancel path in M8.
      **Effort:** S
      **Blocks:** —

---

- [x] **M16 — Every server-loaded request renders as "Information Inquiry"**
      **Done 2026-07-26 (`6b2a77b`, `df5cf17`).** Label now comes from the
      catalogue's `service_name`, resolved from the eager-loaded relation on
      GET and from the catalogue on POST's 201, which carries no `service`.
      Verified on screen for six services — Ambulance, Road Clearing, Fire
      Rescue, Search and Rescue, Animal Rescue, Sandbagging — each cross-checked
      against its stored `service_id`. Rendering the four keyword-only ones
      exposed a second bug, fixed in `df5cf17`: "Animal Rescue" was caught by the
      generic `rescue` branch and drew the Search-and-Rescue icon.
      **Known regression:** `displayTitle` returns the raw English
      `service_name`, so the six enum types lost their Filipino titles.
      **Severity:** High
      **Category:** Correctness
      **Location:** `Mobile/lib/models/request_models.dart:362`
      **Problem:** `ServiceRequest.fromJson` hardcodes
      `type: ServiceType.inquiry` for every row it parses, because the
      `ServiceType` enum has no mapping back from a `service_id`. So after any
      `loadRequests()` — i.e. on every app launch — an ambulance request displays
      with the grey question-mark icon and the title "Information Inquiry" on
      both the Track screen (`track_screen.dart:210-216`) and the Home screen's
      active-request card (`dashboard_screen.dart:94-103`). The resident's own
      record of their emergency is mislabelled. Note this is the *display* twin
      of gaps-report **C5**, which fixed the outbound `service_id`; the inbound
      direction was never addressed and is still wrong on the branch.
      **Fix:** `AppState` already holds the real catalogue (`services`, from
      `GET /services`). Resolve `serviceId` against it in `fromJson`'s caller —
      or move parsing into the store — and store the resolved
      `ServiceCatalogItem` on `ServiceRequest` so `name` and
      `iconForServiceName` drive the card. Keep `ServiceType` only for the two
      dashboard shortcut tiles, or delete it once nothing reads it.
      **Effort:** M
      **Blocks:** M21

---

- [ ] **M17 — No logging on any error path**
      **Severity:** High
      **Category:** Error Handling & Observability
      **Location:** `Mobile/lib/state/request_store.dart:36,62,93,129`;
      `Mobile/lib/state/api_service.dart:90,124,147,155`; whole app
      **Problem:** There is not one `debugPrint`, logger, or crash reporter in
      `Mobile/lib` (grep for `print(`/`debugPrint`: zero hits outside generated
      files). Every failure is either swallowed by `catch (_) {}` or, post-merge,
      converted into a user-facing sentence and then dropped. When a resident
      reports "it didn't work," there is no artefact anywhere — no local log, no
      server-side client error record — to diagnose from. For an LGU system with
      no test devices and no staging environment, that makes field bugs
      effectively uninvestigable.
      **Fix:** Add a thin `AppLog` wrapper over `dart:developer`'s `log()` —
      `debugPrint` is throttled and drops lines. Log at every `catch`: endpoint,
      status code, and a short reason. **Never log the token, the password, the
      request body, or the valid-ID bytes** — see M18. Then decide whether a
      crash reporter is acceptable for this data class; if resident PII rules it
      out, at minimum add a "Report a problem" action that copies the last N log
      lines to the clipboard for the resident to send to MDRRMO.
      **Effort:** M
      **Blocks:** —

---

- [ ] **M18 — No tests of any kind**
      **Severity:** High
      **Category:** Code Structure
      **Location:** `Mobile/` — no `test/` directory exists
      **Problem:** Zero unit, widget or integration tests. `flutter analyze` is
      the only automated check, and the project's own history shows exactly what
      that misses: a green analyze coexisted with a service-id map that filed
      ambulances as floods, a 404 signup route, and a 403 cancel. Every fix in
      this document is currently verified by reading code or by hand-running the
      app on one Windows box with no emulator.
      **Fix:** Start with the parsing and state layer, where the bugs actually
      live and no device is needed: `ServiceRequest.fromJson` /
      `ServiceCatalogItem.fromJson` / `getStatusFromText` (table-driven, using
      real captured response bodies including the 401 and 422 shapes), and
      `AppState` submit/cancel against a mocked `ApiService` asserting the
      rollback in M5. Add `mocktail` or `http`'s `MockClient`. Wire
      `flutter test` into whatever CI exists before adding widget tests.
      **Effort:** M
      **Blocks:** —

---

# Medium

---

- [ ] **M19 — Announcements and notifications are hardcoded placeholder copy**
      **Severity:** Medium
      **Category:** Correctness
      **Location:** `Mobile/lib/screens/dashboard_screen.dart:209-225`;
      `Mobile/lib/widgets/shared_widgets.dart:603-638`
      **Problem:** The Home screen's "Announcements" section renders two fixed
      tiles from translation keys (`home.ann1.*`, `home.ann2.*`), one flagged
      **NEW** in red permanently. The notifications sheet contains a single
      hardcoded welcome message and nothing else, while the header bell carries a
      permanent unread dot (`shared_widgets.dart:110-123`). A resident who taps
      the bell during a flood gets a welcome message. The backend has an SMS
      blast feature but no advisory-feed endpoint, so there is nothing to bind to yet.
      **Fix:** Short term, remove the permanent NEW badge and the permanent
      unread dot — a notification indicator that is always on trains residents to
      ignore it. Medium term, expose the SMS blasts (or a new advisories table)
      as `GET /api/advisories`, resident-scoped by barangay, and bind both
      surfaces to it.
      **Effort:** M
      **Blocks:** —

---

- [ ] **M20 — App is named "mobileapp" on the home screen**
      **Severity:** Medium
      **Category:** Build & Release Readiness (also UX Gaps)
      **Location:** `Mobile/android/app/src/main/AndroidManifest.xml:5`;
      `Mobile/ios/Runner/Info.plist:10,18`
      **Problem:** `android:label="mobileapp"`, `CFBundleDisplayName` =
      `Mobileapp`, `CFBundleName` = `mobileapp`. The launcher icon a resident
      taps during an emergency is labelled "mobileapp", while every in-app header
      says SERBIS. For the target audience — barangay residents with low digital
      literacy, under stress — an unrecognisable launcher label is a real
      failure to find the app.
      **Fix:** Set both to `SERBIS`. Do it in the same change as M9's
      `applicationId` rename, since both touch identity and both must happen
      before first distribution.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M21 — Request list is fetched once per launch and never refreshed**
      **Severity:** Medium
      **Category:** Correctness (state desync)
      **Location:** `Mobile/lib/main.dart:177-181`;
      `Mobile/lib/screens/track_screen.dart` (no refresh affordance)
      **Problem:** `_appState.loadRequests()` is called once in
      `_RootShellState.initState` and nowhere else. Screens live inside an
      `IndexedStack` (`main.dart:252`), so switching tabs never remounts them and
      never refetches. A resident watching the Track screen for a status change
      will see the same "Under review" indefinitely — the dispatcher can mark it
      Responding and then Resolved and the app will not notice until the process
      is killed and relaunched. There is no pull-to-refresh anywhere.
      **Fix:** Wrap the Track and Home lists in `RefreshIndicator` calling
      `loadRequests()`. Refetch when the Track tab becomes visible, and on
      `AppLifecycleState.resumed`. A 30–60 s poll while the Track tab is
      foregrounded is a reasonable interim before push notifications exist.
      **Effort:** M
      **Blocks:** —

---

- [ ] **M22 — `getRequests` crashes on an empty or non-JSON response body**
      **Severity:** Medium
      **Category:** Correctness (unchecked cast)
      **Location:** `Mobile/lib/state/api_service.dart:162`
      **Problem:** `data['data'] ?? data['requests'] ?? data.values.first` —
      when `data` is `{}` (which `_decode` returns for any non-JSON body: an
      nginx error page, a captive-portal interstitial), `.values.first` throws
      `Bad state: No element`. `raw.cast<Map<String, dynamic>>()` is likewise
      unchecked and throws lazily on iteration if any element is not a map.
      `getServices` (`:171`) already guards the empty case with
      `data.isEmpty ? null : data.values.first`; `getRequests` was not updated
      to match. The branch's `ApiException` work does not remove this line.
      **Fix:** Mirror the `getServices` guard, and replace the blind `cast` with
      `raw.whereType<Map<String, dynamic>>().toList()` so one malformed row
      doesn't discard the whole list. Better: drop the `values.first` fallback
      entirely — the backend response shape is known and documented in the gaps
      report's Appendix 1, so guessing at it hides contract drift.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M23 — No offline handling and no connectivity awareness**
      **Severity:** Medium
      **Category:** UX Gaps
      **Location:** app-wide; nearest thing is
      `Mobile/lib/screens/services_screen.dart:309-332`
      **Problem:** The app has no concept of being offline. There is no
      connectivity check, no queue for a request composed without signal, and no
      persistence of the request list — `AppState.requests` is in-memory only, so
      an offline launch shows an empty Track screen with the cheerful "No
      requests yet" card. The one honest offline message in the whole app is the
      services grid's "Couldn't load services" panel. This is a disaster-response
      app: the moments it matters most are exactly when the tower is congested or
      down.
      **Fix:** Add `connectivity_plus` and show a persistent offline banner.
      Persist the last-loaded request list (M10's cache layer) and render it with
      a "last updated <time>" label rather than an empty state. Consider queueing
      a composed request for retry on reconnect — decide deliberately, because a
      silently-queued ambulance request is more dangerous than a rejected one;
      if you queue, the UI must say "not yet sent" in unmissable terms and the
      SOS dial path (M1) must be offered alongside.
      **Effort:** L
      **Blocks:** —

---

- [ ] **M24 — Road form's "Attach photo" upload box does nothing**
      **Severity:** Medium
      **Category:** UX Gaps
      **Location:** `Mobile/lib/screens/services_screen.dart:393`, widget defined
      at `:725-757`
      **Problem:** `const _UploadField(label: 'Attach photo (optional)')` renders
      a dashed upload box reading "Tap to upload a photo of the site" and has no
      `onTap`, no controller, and no state — it is decoration. A resident
      reporting a blocked road taps it, nothing happens, and there is no
      indication whether the tap registered. `_ValidIdUploadField` right below it
      *is* functional, so the two look identical and behave differently.
      **Fix:** Either delete `_UploadField` and its usage, or wire it to the same
      `fp.FilePicker` call `_pickValidId` uses and send it as a second multipart
      file. The backend's `store()` accepts only `valid_id` today, so shipping the
      real version needs a backend change — file it or remove the widget. Do not
      leave a control that looks tappable and isn't.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M25 — SMS and push notification toggles are decorative**
      **Severity:** Medium
      **Category:** UX Gaps
      **Location:** `Mobile/lib/screens/profile_screen.dart:116-137`
      **Problem:** Both switches default to on and write to local `bool`s that
      persist nowhere and are read by nothing. Turning "SMS Alerts" off has no
      effect — `SmsController` blasts every `Active` resident in the selected
      barangays regardless — and the setting resets to on when the screen is
      rebuilt. A resident who deliberately opts out still receives paid SMS, and
      the app told them they had opted out.
      **Fix:** Either remove both rows until there is a backend preference to
      bind to, or add a resident-scoped preferences endpoint and honour it in
      `SmsController`'s recipient query. Removing is the honest interim — a
      consent control that does nothing is worse than no control. Push
      notifications don't exist at all (no FCM, no `firebase_messaging`), so that
      row should go regardless.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M26 — Server-loaded requests have no timeline, so the tracking UI vanishes**
      **Severity:** Medium
      **Category:** UX Gaps
      **Location:** `Mobile/lib/models/request_models.dart:366`;
      rendered at `track_screen.dart:244-261`
      **Problem:** `fromJson` sets `timeline: const []`, and the Track card only
      renders the timeline block `if (request.timeline.isNotEmpty)`. So the
      progress timeline — the app's main answer to "what is happening with my
      request?" — appears only for rows created in the current session and
      disappears on relaunch. The timeline that *is* built locally
      (`services_screen.dart:220-224`) is fabricated: fixed steps with the
      literal time "Pending", not real status-change timestamps.
      **Fix:** This needs the request-history contract decided (already an open
      item in the handoff note): either derive a timeline from `status` +
      `created_at` + `updated_at` on the row you already receive — honest, cheap,
      no backend work — or add a resident-scoped status-change log endpoint for
      real timestamps. Pick the first unless the LGU needs an audit trail. Until
      then, don't hide the block; render the current status as a single step.
      **Effort:** M
      **Blocks:** —

---

- [ ] **M27 — `services_screen.dart` is 746 lines doing six jobs**
      **Severity:** Medium
      **Category:** Code Structure
      **Location:** `Mobile/lib/screens/services_screen.dart` (746 lines);
      also `widgets/shared_widgets.dart` (607), `screens/profile_screen.dart` (535)
      **Problem:** One file holds the catalogue fetch, the four guided-form
      variants, the form-state map (`Map<String, TextEditingController>` keyed by
      magic strings like `'amb_patient'`), the submit orchestration, file
      picking, the safety notice with its own copy of the hotline list, the
      confirmation sheet, and five private widgets. The stringly-typed controller
      map is what let M2 happen: `_ctrl('amb_notes')` compiles fine whether or not
      anything ever reads it, so a dropped field is invisible to the analyzer.
      **Fix:** Not a rewrite — targeted extraction. Move `_Field`, `_Dropdown`,
      `_UploadField`, `_TypeCard`, `_SafetyNotice` and `_ConfirmationSheet` into
      `widgets/`. Replace the controller map with a per-form model class
      (`AmbulanceFormData` etc.) exposing typed fields and a
      `toDescriptionLines()` method — then a dropped field is a compile error,
      not a silent omission. Do this **after** M2, so the extraction isn't
      carrying the bug forward.
      **Effort:** L
      **Blocks:** —

---

- [ ] **M28 — MDRRMO hotline numbers are hardcoded in three files and disagree**
      **Severity:** Medium
      **Category:** Correctness (hardcoded values that should come from config)
      **Location:** `Mobile/lib/widgets/sos_button.dart:55-61`;
      `Mobile/lib/screens/library_screen.dart:54-57`;
      `Mobile/lib/screens/services_screen.dart:535-538`
      **Problem:** Three independent literal copies. The SOS sheet lists MDRRMO
      as `0917-123-4567 / 0943-132-0604`; the Library and the Services safety
      notice list only `0917-123-4567`. The SOS sheet also carries PNP and BFP
      mobile numbers the other two omit. If the MDRRMO duty number changes,
      three files must be edited and an app release shipped — during which the
      app confidently displays a number nobody answers.
      **Fix:** Consolidate into one `const` list in a single file now (cheap,
      immediate), and add a backend `GET /api/hotlines` cached through M10's
      offline layer so numbers can be corrected without a release. Cached
      hotlines must be readable with no network — that is the whole point.
      **Effort:** S
      **Blocks:** —

---

# Low

---

- [ ] **M29 — `google_fonts` fetches typefaces over the network at runtime**
      **Severity:** Low
      **Category:** Build & Release Readiness
      **Location:** `Mobile/lib/theme/app_theme.dart:49,63,75`;
      `Mobile/pubspec.yaml:16`
      **Problem:** `GoogleFonts.lexend()` / `.inter()` download from
      `fonts.gstatic.com` on first use and cache to disk. No font files are
      bundled (`pubspec.yaml` declares no `fonts:` section). A first launch with
      no connectivity — plausible for an app installed during a disaster —
      renders in the platform default, silently changing every size and metric
      the layout was tuned against. It also means the app makes an
      unadvertised third-party network request on startup.
      **Fix:** Download the two families, put the `.ttf` files in
      `Mobile/assets/fonts/`, declare them under `flutter: fonts:`, and use
      `GoogleFonts.lexend(textStyle: …)`'s bundled equivalent — or plain
      `TextStyle(fontFamily: 'Lexend')` and drop the dependency. Adds roughly
      1–2 MB to the binary and removes a startup network dependency.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M30 — `_services` aliases the store's mutable list**
      **Severity:** Low
      **Category:** Correctness
      **Location:** `Mobile/lib/screens/services_screen.dart:54`
      **Problem:** `_services = widget.appState.services` copies the reference,
      not the contents. `AppState.loadServices` mutates that same list in place
      (`..clear()..addAll()`), so any later reload silently rewrites the screen's
      list without a `setState` — and on the branch, a failed reload calls
      `services.clear()`, emptying the grid mid-interaction while `_selected`
      still points at a removed row. Only one call site exists today, which is
      why it hasn't bitten.
      **Fix:** `_services = List.of(widget.appState.services)`, or drop the local
      field and read `widget.appState.services` directly in `build` since the
      screen already rebuilds on store notifications.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M31 — Dead compatibility fallbacks in JSON parsing**
      **Severity:** Low
      **Category:** Code Structure
      **Location:** `Mobile/lib/state/account_store.dart:33` (`json['email']`);
      `:34` (`json['address']`); `request_models.dart:342` (`json['id']`);
      `api_service.dart:162` (`data['requests']`)
      **Problem:** Each of these is a `??` fallback to a key the backend has
      never emitted — leftovers from the pre-audit guesses that C2 and C3 fixed.
      They cost nothing at runtime but they make the parsing code read as though
      the response shape is uncertain, which is exactly the ambiguity that let
      the original `id`/`request_id` mismatch survive. `address` in particular
      implies a column that does not exist on `tbl_residents`.
      **Fix:** Remove them. The contract is documented in the gaps report's
      Appendix 1; parse against it and let a missing key be a visible failure.
      Do this together with M18's parser tests so the removal is covered.
      **Effort:** S
      **Blocks:** —

---

- [ ] **M32 — Avatar photo picker is a placeholder message**
      **Severity:** Low
      **Category:** UX Gaps
      **Location:** `Mobile/lib/screens/profile_screen.dart:82`
      **Problem:** The edit-pencil badge on the profile avatar shows
      "Photo picker would open here." — developer copy visible to residents.
      `tbl_residents` does have a `photo` column, so the feature is anticipated
      on both sides but built on neither.
      **Fix:** Remove the badge until there is an upload route, or implement it
      with the `file_picker` dependency already present plus a resident-scoped
      `PATCH` (same missing endpoint as M13). Either way, no build should ship
      the words "would open here."
      **Effort:** S
      **Blocks:** M13

---

- [x] **M33 — A downloaded material cannot be opened**
      **Done 2026-07-29.** Not landed with M1 — hotlines were deferred, and this
      needed no part of them. `_MaterialRow` is now an `InkWell` calling the new
      `AppState.openMaterial`, which tries the saved copy first
      (`lib/state/file_opener_io.dart`, `open_filex`, which carries the Android
      `FileProvider`) and falls back to the server copy in a browser
      (`url_launcher`). Saved-first because the saved copy is the one that works
      with no signal, which is why it was saved. A saved file no viewer can
      display still tries the server rather than dead-ending, so the "no PDF
      viewer installed" message is only shown once both routes have failed;
      a row that exists only because of the offline index has an empty `url`
      and says so instead of pretending. `open_filex` is behind the same
      conditional import as `MaterialCache`, so the web build is untouched —
      it has no saved copies and takes the `url_launcher` route, which works
      there.
      Verified: `test/material_open_test.dart` — 5 store tests over every
      route, plus a widget test that scrolls to the row and taps it. Replacing
      the row's `onTap` with `null` fails that widget test, so it tests the
      wiring and not the store. 30/30 tests pass, `flutter analyze` stays at its
      25-issue baseline, `flutter build web --release` still compiles.
      **Not verified:** the handoff itself — that Android/iOS actually opens the
      cached PDF. That needs a device; this box has no Android SDK.
      **Severity:** High
      **Category:** UX Gaps
      **Location:** `Mobile/lib/screens/library_screen.dart` (`_MaterialRow`),
      `Mobile/lib/state/material_cache.dart`
      **Problem:** Added by M10/M11 and recorded rather than faked. The cache
      writes the real file and the row honestly reports "Saved", but tapping the
      row does nothing — there is no viewer and no handoff to one, so a resident
      who downloads the Evacuation Center Map before a storm cannot look at it.
      The rich in-app articles are unaffected; this is only the server-published
      PDFs and images.
      **Fix:** Open the cached file through `open_filex` (or `url_launcher` with
      a `file://` URI plus an Android `FileProvider`), falling back to the
      material's `full_url` in the browser while online and it is not yet saved.
      Land it with M1, which adds `url_launcher` anyway.
      **Effort:** M
      **Blocks:** —

---

## Not findings — checked and correct

Recorded so the next audit doesn't re-derive them.

- **All HTTP calls are centralised** in `state/api_service.dart`. No screen
  imports `package:http` or constructs a `Uri`. The service-layer boundary the
  brief asks about is already intact — the problems are inside the layer, not
  around it.
- **Auth headers are handled correctly** — `Accept: application/json` on every
  call (which is what keeps Laravel returning JSON 401s instead of redirecting),
  and the bearer token is re-attached manually for the multipart request
  (`api_service.dart:188-191`). Confirmed in the gaps report as **C9**.
- **No credentials, tokens or PII are written to logs** — because nothing is
  logged at all. Passing this check is a side effect of M17, not a design
  choice; keep it true when M17 is implemented.
- **No test endpoints, debug flags or dummy credentials** exist in the Dart
  source. `debugShowCheckedModeBanner: false` is the only debug-adjacent flag
  and it is set correctly. The only build-config placeholders are M9's two.
- **Timeouts are set on every call** — 15 s for JSON, 30 s for the multipart
  upload (`api_service.dart:58,66,78,207`). Reasonable for the target network.
- **Controllers are disposed** in every `StatefulWidget` that owns them
  (`services_screen.dart:111-116`, both auth screens).
- **`Mobile/` may now be edited.** The "teammate's area" rule in the gaps report
  and in `mobile-integration.md` was voided on 2026-07-25 when the user took
  over the Flutter side. This audit is read-only by instruction, not by policy.

---

## Coverage against the six requested dimensions

| Dimension | Task IDs |
|---|---|
| 1. Correctness | M2, M4, M5, M11, M12, M13, M16, M19, M21, M22, M28, M30 |
| 2. Error handling & observability | M3, M6, M8, M17 |
| 3. Auth & session | M7 (+ M6, M12 via the branch) |
| 4. Build & release readiness | M9, M14, M20, M29 |
| 5. Code structure | M18, M27, M31 |
| 6. UX gaps | M1, M10, M15, M23, M24, M25, M26, M32, M33 |

Gaps-report items deliberately **not** repeated here: C1–C7 (closed on
`mobile-c1-c6-c7`), C8 `required_vehicle_type` (dropped by the five-feature
scope), D1 Android `INTERNET` (already in the main manifest on disk), D3–D5
(backend/deploy). D2 base URL is restated as **M14** because the default value
is an in-app decision, not a deploy one. D6 token TTL (audit #30) is referenced
from **M7** because it changes what secure storage is protecting against.
