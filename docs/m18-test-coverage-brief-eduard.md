# M18 — Mobile test coverage

**For:** Eduard Joseph B. De Belen
**Branch:** `eduard-mobile-tasks`
**Written:** 2026-08-05
**Week 3 deadline:** Fri 2026-08-07

---

## Why this task

M18 is the only open mobile item that is genuinely self-contained and needs no
Android SDK, no emulator and no C++ toolchain. Flutter unit and widget tests run
on the Dart VM — `flutter test` is the whole toolchain. That matters because the
other commit on your branch (`260641e`) renames the application identifier to
**`ph.gov.echague.serbis`** — note the `ph.` prefix, it is part of the string —
across Android, iOS, macOS, Linux and Windows. It touches Gradle and CMake and
**has never been compiled by anyone**, so it cannot be verified on the machine it
was written on. This task can be verified completely on yours.

**If you have an Android SDK, please also run this once and report the result:**

```
flutter clean
flutter build apk --debug
```

It was attempted on 2026-08-05 and got as far as
`[!] No Android SDK found. Try setting the ANDROID_HOME environment variable.`
— the build stopped before Gradle started, so it never read `build.gradle.kts`
and proved nothing either way. One successful run on your machine closes the
last unverified thing in the mobile tree. It is a separate job from the tests
below; do it first, because it is two commands and it is currently blocking
nobody but everybody.

Push it yourself, from your own machine. Nothing has ever been pushed under your
own credential — every push to this repo so far has gone out on BOXCUBE06's
cached token — so this is also the first real test of your collaborator access.
If the push fails, that failure is useful information; report it rather than
working around it.

---

## Baseline — measured 2026-08-05, not quoted

```
cd Mobile
flutter test
00:09 +166: All tests passed!
```

**166 tests, all green.** 18 test files under `Mobile/test/`. `flutter analyze`
reports 22 issues, all pre-existing `withOpacity`/lint info — not yours, don't
fix them here.

There is **no CI**. No `.github/workflows/` directory exists. `flutter test` has
only ever been run by hand.

> The M18 entry in `docs/mobile-improvement-tasks.md` is **stale** — it says "39
> passing tests" and lists `getStatusFromText` as uncovered. Both were true when
> it was written and neither is true now (`getStatusFromText` is covered by
> `test/disapproved_status_test.dart`). Work from this brief, not from that
> entry.

---

## Setup

```
git fetch origin
git checkout eduard-mobile-tasks
git pull
cd Mobile
flutter pub get
flutter test
```

Confirm you get 166 passing before you write anything. If you don't, stop and
say so — a different starting number means something else is wrong and nothing
you add on top of it will mean much.

**Set your own git identity in this clone before committing:**

```
git config user.name "Eduard Joseph B. De Belen"
git config user.email "eduardjoseph.debelen1@gmail.com"
```

Note the domain: `@gmail.com`. There are nine older commits in this repo under
`hakarii1 <eduardjoseph.debelen1@email.com>` — `.com`, no `gmail` — which is
almost certainly an old config or a typo at setup time. Don't reproduce it.

**Do not touch `Mobile/pubspec.lock`.** It shows as modified on most machines
from transitive test-package bumps that `flutter pub get` picks up on its own.
`pubspec.yaml` is untouched and should stay that way. Leave the lockfile out of
every commit.

---

## The four gaps, in the order worth doing them

Each one below was checked against the code on 2026-08-05, not carried over from
the older doc.

### 1. `ServiceCatalogItem.fromJson` — zero coverage

`Mobile/lib/models/request_models.dart:221`

Grep the whole test directory for `ServiceCatalogItem` and you get **no matches**.
This is the parser for the service catalogue — the list of services a resident
picks from — and nothing exercises it.

Sibling parsers are already covered in `test/api_response_parsing_test.dart`
(`ServiceRequest.fromJson`, `AppUser.fromJson`, the list unwrapper). Read that
file first and follow its shape: same structure, same naming, same style of
assertion. Cover the real response keys, a missing optional key, and a null
where a value is expected.

### 2. The submit rollback — the M5 path

`Mobile/lib/state/request_store.dart:428–475`

Submitting optimistically inserts the request at the top of the list, then
removes it again if the call throws. The comment on line 468 says what this is
protecting against, and it is worth reading before you write the test:

> The optimistic row never reached the server, so drop it. Leaving it in place
> is what made a 422 "No available vehicles at this time." look like a filed
> request that MDRRMO would never see.

**Cancel is already covered; submit is not.** `test/request_timeline_test.dart`
has a fake `ApiService` (line 44) and drives `AppState.cancelRequest` through it
(line 154). Reuse that fake — extend it, don't write a second one — and drive
the submit path: one test where the call succeeds and the optimistic row is
replaced by the confirmed one, one where it throws and the row is gone again.

This is the highest-value test in the list. It is the one that catches a
regression a resident would actually experience.

### 3. The 401 / 422 body shapes

`Mobile/lib/state/api_service.dart:187–269`

`_messageFrom` (line 264) unwraps Laravel's validation shape — `errors` is a map
of field to list of messages, and it takes the first — falling back to `message`
for everything else.

What exists today only touches this indirectly: `test/app_log_test.dart` and
`test/problem_report_test.dart` assert on formatted log lines containing
"status 422", and `test/profile_edit_test.dart` covers a 422 surfacing in the
profile sheet. **Nothing parses a raw error body.** No test anywhere mentions
401.

Cover: a 422 with a populated `errors` map, a 422 with `errors` empty or absent
(must fall through to `message`), a 401, and a body that is not JSON at all.
That last one is not hypothetical — see the trap list below.

### 4. CI

There is no `.github/workflows/`. A workflow that runs `flutter test` on push
would mean the suite stops depending on someone remembering.

**Do this one last, and only if the first three are done and pushed.** It is the
least valuable of the four for a defence, it is the easiest to get subtly wrong,
and a red CI badge on the repo during submission week is worse than no badge.
If you get to it: `subosito/flutter-action`, `flutter pub get`, `flutter test`,
nothing else. No build step — the build needs an Android SDK and will fail.

---

## What "done" means here

**A passing test proves nothing on its own.** For every test you add, break the
code it covers and watch it go red, then put the code back. If it still passes
with the logic broken, the test is decorative — delete it or fix it.

Say so explicitly in the commit message: which line you broke, and which test
went red. That sentence is the whole point of the exercise. Example from an
earlier commit in this repo:

> Mutation-checked: dropping the `vehicle_id` rule turns 4 tests red
> (`null is identical to 1` — the original bug reproduced).

Then:

```
flutter test          # all green, and the number is higher than 166
flutter analyze       # still 22, no new issues
```

---

## Traps that have already faked a pass in this repo

These each cost real time. Read them before you start, not after.

- **The default test viewport is 800x600.** It clips tall screens, so an
  offscreen row never builds and the value under test is unfindable for the
  wrong reason. Set `tester.view.physicalSize` to a phone shape.
- **An unmocked plugin channel hangs rather than throws.**
  `SharedPreferences.getInstance()` without `setMockInitialValues` sat until a
  10-minute timeout. **If a widget test hangs, look for a plugin call before you
  look at the widget.**
- **`scrollUntilVisible` stops as soon as the finder matches**, and a `ListView`
  builds a screenful past the viewport — a row can be found while still below
  the fold, so the tap lands on nothing. Follow it with `ensureVisible`.
- **The widget-test fallback font is much wider than the real typeface**, so a
  `Row` that fits on a device overflows under test.
- **`flutter_test` does not load bundled fonts.** No test can prove a glyph
  reaches a screen. Don't try.
- **A dead fallback branch is not free.** Four unwrapper branches that could
  never run — until one did, and crashed with `Bad state: No element` on a
  captive-portal response that decoded to `{}`.

---

## Scope — do not go past this

- **Tests only.** If you find a bug, write the failing test, then say so and
  stop. Do not fix production code in this task; the fix is a separate decision
  and probably a separate commit.
- **Do not touch `pubspec.yaml`** or add a package. Everything needed is already
  a dependency.
- **Do not reformat, rename, or tidy** files you are testing. A diff that mixes
  new tests with formatting churn cannot be reviewed.
- **Do not start M1 (the SOS dialer) or M9's release signing.** Both are out of
  scope by instruction.

## If you get stuck

Report what you actually saw — the command, the output, the file. A partial push
with two real tests beats an unpushed branch with four half-written ones. The
deadline is Aug 7; push whatever is green before then.
