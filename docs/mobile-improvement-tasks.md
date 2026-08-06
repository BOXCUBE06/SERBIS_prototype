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

## ❓ OPEN QUESTIONS FOR MDRRMO — unresolved, needed before defense

Not findings, and not for the developer to decide. Each needs an answer from
the office. **Nothing below has been resolved.**

- **`sms_opt_in` currently silences ALL SMS blasts including emergency
  evacuation alerts, with no exemption. Needs MDRRMO confirmation before
  defense: is full opt-out acceptable, or should emergency blasts be exempt
  from this preference?**
  Where it stands today: `tbl_residents.sms_opt_in` (default true) is filtered
  in `SmsController::sendBlast()`'s recipient query, and the resident sets it
  from the profile screen. There is exactly one kind of blast in the system —
  the backend has no notion of an emergency tier versus a routine one — so the
  switch is all-or-nothing by construction, not by choice. **The mobile copy
  states that plainly and must not be softened while it is true.** An exemption
  would mean a new field on `tbl_sms_logs` (or a second endpoint), a way for the
  sender to mark a blast as exempt, and a decision about who is allowed to mark
  one.

- **The ten Tagalog service descriptions have never been reviewed by a
  Filipino speaker at the office.** Names were approved 2026-07-26; the blurbs
  were not. `ServiceTranslationSeeder::FILIPINO` upserts, so a correction is an
  edit and a re-run. The same applies to the three strings added for the SMS
  switch above.

- **M1's hotline numbers are still unconfirmed** — see the M1 entry below. It is
  the same class of question: the office has to answer it before the code can be
  finished, and shipping a guess is worse than shipping nothing.

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
      **Interim fix landed 2026-08-01. Still open on the third fiction only:**
      there is no way for a resident to change their own details, because the
      backend has no resident-scoped update route. **Filed: `PATCH /me`** —
      resident-scoped, must not accept `status` or `role`, and whether
      `barangay_id` is self-service at all is a product decision, not a coding
      one: it is the field every request is dispatched on, and a resident who
      can move themselves between barangays can redirect their own dispatch.
      Until it exists the screen states the limit instead of faking a write.
      What landed:
      - The `'Juan Delacruz'` / `'Echague, Isabela'` defaults are gone. The three
        values come straight off the signed-in resident; a value the server did
        not send renders as **"Not on file"** in a muted colour, on the card and
        in the sheet. Never a plausible substitute — `AnalyticsController.php:116`
        and every request read locate a request through
        `tbl_residents.barangay_id`, so a made-up location is a triage risk.
      - The edit sheet is **read-only**: three boxed values, no `TextField`, no
        "Save changes", and a line reading *"Contact MDRRMO to update your
        details."* — matching the existing "Forgot password?" copy. The button
        that opens it now says **"Account details"**, not "Update information".
      - The avatar's edit badge is **removed**, which also closes **M32** (it
        was a snackbar reading "Photo picker would open here." and nothing else).
        M32 becomes *implement a picker*, and its Location line is now stale.
      - The offline row's `'5 saved · 4.2 MB used'` was already computed from
        M10's index; nothing to do there.
      - New strings are translated (`profile.account_details`, `profile.full_name`,
        `profile.email`, `profile.barangay`, `profile.contact_to_update`,
        `profile.value_missing`, `common.close`); `profile.update_info` is gone.
      **Verified by test, not by driving the app.** `test/profile_identity_test.dart`,
      5 widget tests (55 total, `flutter analyze` back to its 25-issue baseline).
      Both halves were mutation-checked: restoring the two fake defaults fails
      "a profile with no values shows no invented identity", and putting a
      `TextField` + "Save changes" back in the sheet fails "the details sheet is
      read-only and offers no save". **Not exercised live** — reaching this
      screen needs a resident login, and the seeded residents' passwords are
      random per `ResidentSeeder` and unknown here.
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

- [x] **M17 — No logging on any error path**
      **Done 2026-08-02.** `lib/state/app_log.dart` — `AppLog` over
      `dart:developer`'s `log()`, plus an 80-entry ring buffer, wired into all
      29 `catch` sites across `api_service`, `request_store`, `request_cache`,
      `material_cache_io`, `file_opener`, `file_opener_io`, both auth screens
      and `main`. Every HTTP line names its method and path; `_send` and
      `_decode` now take the endpoint so a failure says which call it was.
      **A crash reporter was rejected, not deferred.** This app holds
      government ID scans, and shipping failures to a third party is not a
      trade to make on a resident's behalf. The doc's fallback was built
      instead: Profile → **Report a problem** shows the buffer and copies it to
      the clipboard. It sits *above* Log out deliberately — logout clears the
      buffer, so a resident who signs out to "start fresh" before reporting
      would otherwise destroy the evidence.
      **The no-PII rule is enforced in one place, not at 29 call sites.**
      `AppLog.describeError` reduces any non-`ApiException` to its **type
      only**, because `jsonDecode`'s `FormatException` stringifies the source it
      choked on — logging it verbatim writes a slice of the response body.
      `ApiException` is the sole exception and is logged in full: its message
      was written to be shown and is already on the resident's screen.
      Mutation-checked — swapping `runtimeType.toString()` for `toString()`
      fails three tests.
      **The lines are shown, not just copied.** A resident about to send this to
      an LGU can read it first, which is also what keeps the no-PII rule honest.
      23 new tests (`app_log_test.dart`, `problem_report_test.dart`); suite
      116 → **139**; `flutter analyze` unchanged at 22.
      **Not exercised on a device** — no Android SDK on this box, so the
      clipboard path is proven by a mocked `SystemChannels.platform`, not by a
      real paste.
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
      **Partly addressed, still open. Re-measured 2026-08-06 with
      `flutter test --coverage`; the numbers below are that report, not an
      estimate.** `Mobile/test/` holds **20 files and 184 passing tests** —
      this entry said 39, which was true when the sentence was written and has
      been wrong through every feature that landed after it. Line coverage over
      `lib/` is
      **63.7%, 1919 of 3012 instrumented lines**, across 30 of the 35 files.
      Tests were added alongside the fixes that needed them: M7 (token
      storage), M10/M11 and M33 (cache, store, open routes), M14 (base URL),
      M22/M31 (`api_response_parsing_test.dart` — the list unwrapper,
      `ServiceRequest.fromJson`, `AppUser.fromJson`), M21
      (`requests_refresh_test.dart` — the refresh guards, and the first widget
      tests of a main screen), and since then the `Disapproved` status,
      advisories, `site_photo` and the profile edit.
      **Read the coverage figures with one caveat:** `flutter test --coverage`
      reports only files some test loaded. A file absent from `lcov.info` is at
      zero, not unmeasured — it is the worse case, not a missing one.
      **The four gaps this entry named, each checked against the report rather
      than assumed:**
      • `ServiceCatalogItem.fromJson` — **still uncovered**, 0 of 13 lines
      (`models/request_models.dart:247-271`). The service catalogue is parsed on
      every launch and nothing tests it.
      • `getStatusFromText` — **now covered**, 7 of 7 lines
      (`models/request_models.dart:713-733`), closed by
      `disapproved_status_test.dart`. This entry listing it as a gap was stale.
      • **401/422 body shapes — still uncovered**, 0 of 22 lines
      (`state/api_service.dart:253-290`). That range is both the token-rejected
      sign-out and all of `_errorMessage`, so nothing exercises the Laravel
      `errors`-vs-`message` unwrapping that decides every sentence a resident
      reads on a failure.
      • `AppState` submit/cancel against a mocked `ApiService` — **mostly
      covered; the M5 rollback specifically is closed.** The submit rollback
      (`state/request_store.dart:534-537`) is hit. What is left uncovered is the
      incomplete-request guard (`:493-499`, unreachable from the form by
      design), the null-id cancel guard (`:548-549`), and **the cancel rollback
      (`:584-585`), which is a real gap** — it is the path that puts a request
      back when the server refuses the cancellation, and cancel's happy path is
      tested while its failure path is not.
      **CI is still nothing.** There is no `.github/workflows/` anywhere in the
      repo; `flutter test` is run by hand on one machine.
      **Largest untested surfaces, by file:**
      `screens/library/article_reader_screen.dart` 0/93 · `state/api_service.dart`
      35/204 (17.2%) · `widgets/service_widgets.dart` 22/111 (19.8%) ·
      `screens/services_screen.dart` 46/160 (28.8%) · `state/account_store.dart`
      22/57 (38.6%) · `widgets/sos_button.dart` 40/83 (48.2%) ·
      `screens/profile_screen.dart` 333/547 (60.9%). **Absent from the report
      entirely — no test loads them at all:** `main.dart`,
      `screens/auth/login_screen.dart`, `screens/auth/register_screen.dart`. The
      two other absent files, `state/file_opener_unsupported.dart` and
      `state/material_cache_unsupported.dart`, are conditional-import fallbacks
      for platforms this test run is not; their absence is correct.
      **Severity:** High
      **Category:** Code Structure
      **Location:** `Mobile/test/` — 20 files, 184 tests, 63.7% line coverage.
      The original line here read "no `test/` directory exists", which was true
      when this was filed and has not been since `fa778d2`, 2026-07-28.
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

- [x] **M19 — Announcements and notifications are hardcoded placeholder copy**
      **Done 2026-08-01.** Every invented string is gone, and both surfaces now
      render data the app actually has rather than nothing:
      • **Home "Announcements"** lists what MDRRMO has published — the two most
      recent rows of `GET /info-materials`, already fetched at launch and cached
      offline by M10/M11 — with each material's real `created_at`. The tile taps
      through to the Library. `InfoMaterial` gained `publishedAt` (parsed
      `toLocal()`, as in M26) and the offline index carries it, so a saved copy
      is still dated from when MDRRMO published it and not from when this device
      downloaded it. An index written before this change still loads; it just
      has no date, and an undated material sorts last and says "Date not
      recorded" instead of borrowing a neighbour's date. Empty, failed and
      served-from-cache each get their own line.
      • **The permanent NEW badge** is gone — it was hardcoded `true`, so it was
      on for every resident on every launch forever.
      • **The permanent unread dot** is gone from the bell for the same reason.
      • **The bell sheet** held one hardcoded welcome message, so a resident who
      tapped it during a flood read "We're glad to have you with Echague
      MDRRMO". It now lists the resident's own requests, newest movement first,
      each with the real timestamp off the row and its status; with none it says
      "No updates yet" rather than greeting them.
      Verified: `test/announcements_test.dart` — 17 tests (the date on the model
      and through the offline index round-trip, the ordering, the undated
      material, all three Home states, the absent NEW badge, the absent bell
      dot, the sheet's list/ordering/empty state/translation). Mutation-checked:
      neutralising the sort comparator fails the ordering tests. 90/90 tests
      pass, `flutter analyze` steady at 23.
      **Not done — and this is the part that matters:** there is still no
      advisory feed. The backend has SMS blasts and no `GET /api/advisories`, so
      a weather warning MDRRMO sends by SMS does not reach this app at all. What
      landed replaces fiction with the truth the app holds; it does not make the
      bell an emergency channel. The sheet says so in as many words — "MDRRMO
      advisories are not sent here yet" — so nobody reads the absence of a flood
      warning there as the absence of a flood. **Filed: `GET /api/advisories`,
      resident-scoped by barangay**, plus the decision of whether an SMS blast
      is the same object as an in-app advisory.
      **Note:** a `Responding` row shows as "Scheduled" in the sheet, because
      that is the label `status.scheduled` carries app-wide. Wrong vocabulary,
      but it is the same wrong vocabulary as every other screen — worth one
      pass over `ReqStatusX.labelFor` rather than a local fix here.
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

- [x] **M20 — App is named "mobileapp" on the home screen**
      **Done 2026-08-01.** Every user-visible label now reads `SERBIS`:
      `AndroidManifest.xml` `android:label`, iOS `CFBundleDisplayName` and
      `CFBundleName`, the Linux window/header-bar title, and the Windows
      `FileDescription`/`ProductName`. Went wider than the task's two files
      because the same placeholder was in six — the M28 "hardcoded in three
      files" class again.
      **The web build was included and had two more placeholders the task did
      not name:** `web/index.html` `<title>` and `web/manifest.json`
      `name`/`short_name` said `mobileapp`, and both `description` fields said
      "A new Flutter project.". Title and manifest name now carry the same
      string `main.dart:87` gives `MaterialApp.title` — `SERBIS — Echague
      MDRRMO` — with `short_name` kept to `SERBIS` for the launcher.
      **Identifiers were deliberately not touched**, so this is a display-label
      change only: `applicationId`/`namespace` `com.example.mobileapp`, the
      Windows `InternalName`/`OriginalFilename`, the CMake `BINARY_NAME`s and
      the macOS `PRODUCT_NAME` still say `mobileapp`. The app ID rename is M9,
      which is deferred (not shipping to the Play Store), and on Android it also
      means moving the Kotlin package directory. macOS's `PRODUCT_NAME` is both
      the display name and the bundle name, so it was left rather than renamed
      blind on a box with no mac.
      **Not verified live on Android or iOS** — no Android SDK and no mac on
      this box (see the environment note). The web half was verified by running
      `flutter build web` and reading the emitted `build/web/index.html` and
      `manifest.json` — both carry the new strings. `flutter analyze` unchanged
      at 23; no Dart file was touched.
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

- [x] **M21 — Request list is fetched once per launch and never refreshed**
      **Done 2026-07-29.** All four routes the task asked for: `RefreshIndicator`
      on Track and Home, a refetch when either becomes the visible tab, a
      refetch on `AppLifecycleState.resumed`, and a 45 s poll that runs only
      while one of those two tabs is foregrounded. The poll lives in
      `_RootShellState`, not in the screens: they sit in an `IndexedStack` and
      are never unmounted, so a screen cannot tell whether it is on top.
      Repeated fetching needed three guards in `loadRequests`, each covering a
      way the old once-per-launch call could not fail:
      • **single-flight** — the poll, a tab switch and a resume can all land
      within a second, and two fetches both `clear()`ing the same list drops
      rows.
      • **pending rows are carried across** — a request submitted seconds ago
      has no server id and the server does not know about it, so a refresh
      landing mid-POST would wipe it off the screen *and* strand `addRequest`,
      which holds that exact object to swap for the confirmed row.
      • **`silent`** — a background poll with no signal must not stack a
      snackbar over the screen every 45 s. A pull-to-refresh is not silent.
      Plus a `maxAge` so tapping between Home and Track is not a request each
      way, not stamped on failure so a retry is never skipped.
      Verified: `test/requests_refresh_test.dart` — 9 store tests over all of
      the above, plus 2 widget tests that fling the Track screen and assert the
      refetch. Mutation-checked: replacing `onRefresh` with a no-op fails both
      widget tests, and so does `ClampingScrollPhysics`. 50/50 tests pass,
      `flutter analyze` stays at its 25-issue baseline,
      `flutter build web --release` still compiles.
      **Not verified:** the lifecycle and poll paths themselves — no widget test
      drives `didChangeAppLifecycleState` or waits out a 45 s timer, and neither
      has been exercised on a device.
      **Note:** the explicit `AlwaysScrollableScrollPhysics` on both lists is
      redundant today — a `ListView` with no controller is `primary` and gets it
      free — and is stated so the pull survives either list being given a
      controller. Removing it does not fail the tests; changing the physics to
      one that cannot overscroll does.
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

- [x] **M22 — `getRequests` crashes on an empty or non-JSON response body**
      **Done 2026-07-29.** Took the "better" option: the shared unwrapper (by
      then `_listFrom`, one helper behind all four list endpoints) now reads
      `data['data']` and nothing else. That is the shape all four actually send
      — `ServiceRequestController::index` emits `{"data": [...]}` itself,
      `ServiceResource::collection` wraps in it, and `_decode` wraps the bare
      arrays from `/info-materials` and `/barangays` in it too — so the named-key
      and `values.first` guesses were unreachable as well as unsafe. A body that
      decoded to `{}` now yields an empty list instead of throwing
      `Bad state: No element`; the `whereType` that replaced the blind `cast`
      was already in place from the `ApiException` work. Renamed to
      `ApiService.listFrom`, `@visibleForTesting`, since it had no test.
      Verified: `test/api_response_parsing_test.dart` — 5 unwrapper tests
      covering `{}`, a missing `data`, a non-list `data`, and a list with junk
      elements. 39/39 tests pass; `flutter analyze` stays at its 25-issue
      baseline. Landed with M30 and M31.
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

- [x] **M23 — No offline handling and no connectivity awareness**
      **Done 2026-08-02.** Two of the task's three parts landed; the third was
      declined on purpose, see below.
      • **The request list is persisted.** `state/request_cache.dart` writes the
      rows and the fetch time to `shared_preferences` after every successful
      load, and `hydrateRequests()` reads them at launch before the first fetch.
      An offline launch now opens onto what MDRRMO last said, labelled
      **"Saved copy · Last updated \<time\>"**, instead of the cheerful
      "No requests yet" card. A fetch that lands first wins — it is newer by
      definition. Cached rows keep status, ref and timestamps, so M26's timeline
      works with no signal.
      • **A persistent offline banner** sits above every screen, not inside one:
      being unable to reach MDRRMO is true of the whole app.
      • **Rows are cleared on logout.** They name this resident's requests, and
      the next person to use the phone must not open onto them.
      **Connectivity is derived from request outcomes, not from
      `connectivity_plus`** — a deliberate departure from the task's wording.
      A phone showing full bars on a congested tower is exactly the case this
      app exists for, and the OS flag calls that phone online; a request that
      timed out is evidence, a radio link is not. So `isOffline` is set by a
      fetch that got no response (`ApiException.isNetwork`) and cleared by any
      answer. A 401 or a 500 is the server *answering*: no banner, because
      telling a resident to check their signal over a server fault sends them to
      fix the wrong thing. The cost is stated plainly: nothing knows the network
      is gone until something has tried, so the banner appears on the first
      failed poll (≤45 s, M21) rather than the instant the signal drops.
      **Queueing was declined, not forgotten.** The task says to decide
      deliberately, and this is the decision: nothing is queued. A silently
      queued ambulance request is more dangerous than a rejected one, the SOS
      dial path (M1) that would have to be offered alongside it does not exist
      yet, and the banner therefore states in as many words that requests cannot
      be sent. Revisit **after M1**.
      Verified: `test/offline_test.dart` — 16 tests (write-through, rehydration,
      a fetch beating the cache, logout clearing it, a corrupt cache, a cache
      with no fetch time, the four `isOffline` transitions including the silent
      poll, and the three surfaces). Mutation-checked: removing the id filter in
      `RequestCache.save` fails "an unsent row is never persisted" — and that
      test asserts on the *written* JSON, because the reader drops id-less rows
      too and checking only `load()` passed against the mutation. 116/116 tests
      pass, `flutter analyze` steady at 22, `flutter build web --release`
      compiles.
      **Not verified:** never run on a device, and no test kills the network
      mid-session — the transitions are driven through a fake API, not a real
      radio. The composed-but-unsent form is still lost if the app is killed
      while the resident is typing; only submitted-and-confirmed rows are cached.
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

- [x] **M24 — Road form's "Attach photo" upload box does nothing**
      **Done 2026-08-01 by removal.** Took the delete option, not the wire-it-up
      one: `store()` accepts only `valid_id`, so the functional version is a
      backend change, and until that exists a second upload box can only ever be
      decoration. Both the usage and the `_UploadField` class are gone; nothing
      else referenced it. The road form now ends at Description, and the only
      upload control left on the screen — `_ValidIdUploadField` — is the one that
      works, so two identical-looking boxes no longer behave differently.
      **Filed for the backend: a second multipart file on
      `POST /service-requests`** (site photo, optional, road obstruction only).
      Wiring the widget back is then a `fp.FilePicker` call alongside
      `_pickValidId`.
      **Not verified live** — deletion of an inert widget, covered by
      `flutter analyze` (25 issues, unchanged baseline) and 55/55 tests passing.
      No test asserted the box's absence; the widget had no behaviour to assert
      on either side of the change.
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

- [x] **M25 — SMS and push notification toggles are decorative**
      **Done 2026-08-01 by removal.** Took the honest interim the task named:
      a consent control that does nothing is worse than no control, and there
      is no resident-scoped preference on the backend to bind either switch to.
      The whole Notifications section is gone rather than emptied — header, both
      `_SettingsRow`s, the `_smsAlerts` / `_pushNotifications` fields, and the
      five now-unreferenced `profile.*` translation keys. Account settings is
      the first section under the profile card now. Push went regardless of any
      future preference endpoint: there is no FCM and no `firebase_messaging`
      anywhere in the app, so that row was never going to work.
      **Filed for the backend: resident-scoped notification preferences**, which
      `SmsController` must then honour in its recipient query — the switch is
      only worth restoring once opting out actually stops the paid SMS.
      Verified: `test/profile_identity_test.dart` gains a fifth test asserting
      no `Switch` and none of the three labels survive on the screen (there is
      no other `Switch` on it, so `byType` is the whole guard). Mutation-checked
      — putting a single switch row back fails it. 56/56 tests pass;
      `flutter analyze` drops 25 → **23**, the two lost issues being the
      `activeColor` deprecations on the deleted switches.
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

- [x] **M26 — Server-loaded requests have no timeline, so the tracking UI vanishes**
      **Done 2026-08-01.** Took the first option — derive the timeline from
      `status` + `created_at` + `updated_at`, no backend work. The stored
      `timeline` field is gone from `ServiceRequest` entirely, replaced by
      `timelineFor(filipino)`, so there is no list left to be empty and the
      Track card renders the block unconditionally. Two bugs died with the
      field: the steps can no longer disagree with `status` (the old
      hand-assembled cancel path appended "Request cancelled" after whatever
      was already there, so a cancelled request could still show "Completed"
      ahead of it), and the steps are now translated — they used to be English
      literals built at submit time, so a Filipino-speaking resident read
      English.
      Every time shown is a real timestamp, never a placeholder:
      • **`toLocal()` on parse** — Laravel serialises UTC, so without it every
      entry read eight hours early in the Philippines.
      • **no invented times** — a null or unparseable timestamp renders
      "Time not recorded", and `updated_at == created_at` (nothing has happened
      to the row since it was filed) is treated as no second timestamp rather
      than as the moment the request moved.
      • the locally-submitted row stamps `createdAt: DateTime.now()` and the
      local cancel stamps `updatedAt`, so a row still has a real time before
      the server's copy comes back.
      Verified: `test/request_timeline_test.dart` — 11 model/store tests (one
      per status, the UTC conversion, the missing and the malformed timestamp,
      the untouched row, the translation, the cancel stamp) plus 1 widget test
      that pumps the Track screen with a row from `fromJson`, taps
      "View timeline" and reads the steps. Mutation-checked: putting the block
      back behind a guard that hides it fails the widget test. 73/73 tests pass,
      `flutter analyze` is at 23 issues (down from 25 — the removed literals),
      `flutter build web --release` compiles.
      **Not verified:** never run on a device.
      **Known gap:** a `Disapproved` row reads "Cancelled" in its last step.
      `getStatusFromText` folds `disapproved` into `ReqStatus.cancelled`, so the
      timeline cannot tell a resident whose request the MDRRMO refused apart
      from one who withdrew it themselves. That is the enum's limit, not the
      timeline's — fixing it means a sixth status, and it is worth doing.
      **Note:** `updated_at` is the last time *any* column changed, not
      specifically the status, so the middle step presents it as when the
      request last moved rather than claiming a precise status-change time.
      Real per-status timestamps still need the resident-scoped history endpoint
      the task describes; this is the honest version of what the row already
      carries. Month abbreviations stay English — the rest of the app and the
      LGU's forms use them, and inventing Filipino ones here would be a guess.
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

- [x] **M27 — `services_screen.dart` is 746 lines doing six jobs**
      **Done 2026-08-01.** Targeted extraction as the task specifies, no
      rewrite. The file was 929 lines by the time this was picked up; it is now
      **349**, and does the three jobs only it can do: fetch the catalogue,
      track the selection, submit.
      What moved:
      • `models/service_forms.dart` — `AmbulanceFormData`, `RoadFormData`,
      `ReliefFormData`, `GenericFormData` under a sealed `ServiceFormData`.
      Each owns its controllers as named properties and exposes
      `metaLines(serviceName:, submittedLabel:)` and `requiredContactNumber`.
      The `Map<String, TextEditingController>` and every `'amb_patient'`-style
      key are gone.
      • `widgets/form_inputs.dart` — `AppTextField` (was `_Field`) and
      `AppDropdown` (was `_Dropdown`), plus an `AppTextField.phone` factory: the
      digits-only formatter and the 11-cap were repeated at all four phone
      fields and are now spelled once.
      • `widgets/service_widgets.dart` — `SafetyNotice`, `ServiceTypeCard`,
      `ValidIdUploadField`, `SubmitErrorCard`, `ConfirmationSheet`, `ServiceGrid`.
      • `widgets/service_form_fields.dart` — the four form layouts, switching on
      the sealed type.
      **Why this kills the M2 bug class:** the old `_ctrl('amb_notes')` created
      a controller on demand, so a field could be rendered, typed into and never
      read, and the analyzer had nothing to say. The screen and the description
      now read the same typed object, and `test/service_forms_test.dart` pumps
      each form, fills **every** input on screen with a distinct value and
      asserts each one reaches the description. A field dropped from
      `metaLines` fails that test — verified by deleting the ambulance
      "Condition" line, which does.
      Also folded in: `_nowLabel()` was a fourth private copy of the 12-hour
      clock formatter and is now `formatTimelineTime(..., false)` — English on
      purpose, because that string is read by a dispatcher in the admin panel.
      Verified: `test/service_forms_test.dart` — 10 tests (the four
      every-field-reaches-the-dispatcher checks, the description's shape, blank
      and whitespace-only values, which forms require a callback number, the
      dropdown writing through to the model, the phone field's formatter).
      100/100 tests pass — including the pre-existing Services-screen tests,
      which the refactor had to leave untouched to be worth anything —
      `flutter analyze` 22 issues (down from 23), `flutter build web --release`
      compiles.
      **Not verified:** never run on a device. No test drives the screen's own
      submit path end to end; the guards there (in-flight, missing ID, missing
      contact) are unchanged code and still uncovered, which is M18's problem.
      **Left alone deliberately:** `shared_widgets.dart` (755 lines) and
      `profile_screen.dart` (689) are named in this task too. Splitting them is
      the same exercise with none of the same urgency — neither holds a
      stringly-typed data path — and doing it in the same change would have
      buried the part that matters.
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

- [x] **M28 — MDRRMO hotline numbers are hardcoded in three files and disagree**
      **Done 2026-08-01 — the cheap half. `Mobile/lib/data/hotlines.dart`** holds
      one `const kHotlines`, and the SOS sheet, the Library card and the Services
      safety notice all render it. The three no longer *can* disagree.
      **Merged to the superset, so two surfaces gained numbers:** MDRRMO's second
      duty line `0943-132-0604` and the PNP/BFP mobiles existed only on the SOS
      sheet, and the Library and safety notice showed the short list without
      saying it was short. Every surface now renders `numbersLine` — all numbers,
      joined — rather than picking one.
      **Someone still has to confirm the numbers themselves.** `0917-123-4567`
      reads like placeholder digits; consolidating turned three wrong numbers
      into one wrong number, which is progress but not correctness.
      **Still open, filed rather than done: `GET /api/hotlines`** cached through
      M10's offline layer, with `kHotlines` as the fallback when the cache is
      empty. Until that exists a duty-number change needs a new release, and the
      app displays a number nobody answers until it ships. Cached hotlines must
      be readable with no network — that is the whole point of the endpoint.
      **Two layout changes fell out of it,** because the joined string is roughly
      twice as wide as the single number these rows used to carry:
      • the Library row is now two deliberate lines (label, then numbers in
      green, call icon right) instead of label-and-number opposed on one, which
      wrapped on any narrow phone;
      • the SOS sheet's header `Text` is wrapped in `Expanded`. That one is
      hardening, not a confirmed device bug — the overflow appeared under the
      widget test's fallback font, which is far wider than the real typeface.
      **The SOS sheet stays English**, including these labels. Its title and its
      instruction line are hardcoded English too, and Filipino labels under an
      English heading read worse than either; localising the sheet is its own
      task.
      Verified: `test/hotlines_test.dart`, 5 tests — every surface renders every
      hotline's label and full `numbersLine`, at a 360px-wide viewport so a
      RenderFlex overflow fails the run. Mutation-checked: rendering
      `numbers.first` in the Library instead of `numbersLine` fails it.
      61/61 tests pass, `flutter analyze` steady at 23.
      **Not exercised live** — every surface here is behind a resident login and
      `ResidentSeeder` issues random per-resident passwords, so no seeded login
      is known on this box (same blocker as M13).
      **One existing test needed repairing, not the app:**
      `material_open_test.dart`'s tap missed once the hotline card above it grew
      a line per contact. `scrollUntilVisible` stops as soon as the finder
      matches, and a `ListView` builds a screenful past the viewport — so the row
      was found while still below the fold. Added `ensureVisible`. Worth
      remembering: that test passed for the wrong reason before.
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

- [x] **M29 — `google_fonts` fetches typefaces over the network at runtime**
      **Done 2026-08-02.** Dependency dropped entirely rather than kept and
      configured — leaving it in place would let one `GoogleFonts.lexend()` call
      reintroduce the startup request with nothing else changing. Eight static
      TTFs in `assets/fonts/` (Lexend and Inter, weights 400/500/600/700, the
      four the UI actually uses), declared under `flutter: fonts:`.
      `AppText.display`/`body` are plain `TextStyle(fontFamily: …)` and
      `GoogleFonts.interTextTheme` became `base.textTheme.apply(fontFamily:)`.
      **~400 KB, not the 1–2 MB the task estimated** — these are Latin subsets
      (~230 glyphs). Coverage was verified by parsing each file's `cmap` rather
      than assumed: `ñ`, the accented vowels and `·` (which this UI uses as a
      separator throughout) are all present. **A third language will need them
      re-cut.**
      **Static instances, not the variable fonts.** `google/fonts` ships Lexend
      and Inter as variable only; reaching a variable font's `wght` axis needs
      `fontVariations` on every `TextStyle`, which `ThemeData.textTheme` would
      not carry. Static per-weight files were pulled from the CSS API, which
      only serves TTF (rather than WOFF, which Flutter cannot load) to a legacy
      user agent.
      **This whole failure class is silent**: a missing asset, a renamed file or
      a misspelled family does not fail the build, it falls back to the platform
      font and reads as a styling slip. `app_fonts_test.dart` (8 tests) asserts
      every declared asset exists on disk and every UI weight is declared.
      **What the tests cannot prove:** `flutter_test` does not load bundled
      fonts, so nothing here shows a glyph reaching a screen. Verified in a real
      browser instead: served the web build, confirmed all eight files load from
      `/assets/assets/fonts/`, and read `FontManifest.json` back as
      `Lexend 4 weights, Inter 4 weights`. Headings render Lexend and body
      renders Inter.
      **One gstatic request survives, and it is not this app's.** Flutter web's
      CanvasKit still pulls **Roboto** from `fonts.gstatic.com` as the engine's
      own fallback — it is not `google_fonts` and not reachable from app code.
      It does not occur on Android or iOS, where the platform supplies the
      fallback, and the handset is the delivery target; web is the dev harness.
      So "no startup font request" is true of the app's own typefaces on device,
      **not** of the web build as a whole.
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

- [x] **M30 — `_services` aliases the store's mutable list**
      **Done 2026-07-29.** `_services = List.of(widget.appState.services)`.
      Kept the local field rather than reading the store in `build`: the screen
      does not listen to the store, so reading it directly would have been the
      same aliasing bug wearing a different shape, and `_selected` has to stay
      consistent with whatever list the grid is drawing.
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

- [x] **M31 — Dead compatibility fallbacks in JSON parsing**
      **Done 2026-07-29.** All four removed: `json['email']` and
      `json['address']` in `account_store.dart`, `json['id']` in
      `ServiceRequest.fromJson`, and `data['requests']` in the list unwrapper
      (the last one as part of M22's rewrite). Checked each against the
      controllers first — `/me` and `residentLogin` emit `email_address` and a
      `barangay` relation with no `address` column, and
      `ServiceRequestController` emits `request_id`.
      Verified: `test/api_response_parsing_test.dart` covers the real shapes and
      asserts that a row without `request_id` now parses to a null id and an
      empty ref instead of quietly borrowing `id`.
      **Still open (deliberately):** the sibling `?? json['id']` fallbacks this
      task did not name — `account_store.dart` (`resident_id`),
      `request_models.dart:232` (`service_id`), `info_material.dart`
      (`files_id`), `BarangayOption` (`barangay_id`/`name`). Same class of dead
      guess, left alone rather than widening a Low-severity cleanup unasked.
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

- [x] **M32 — Avatar photo picker is a placeholder message**
      **Closed 2026-08-01 by removal, alongside M13's interim fix.** The badge
      is gone, so no build ships the words "would open here." Implementing a
      real picker is now new work, not a fix: it needs the `photo` column
      written through the same resident-scoped `PATCH /me` M13 filed. The
      Location line below is stale — the `Stack` it named no longer exists.
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
