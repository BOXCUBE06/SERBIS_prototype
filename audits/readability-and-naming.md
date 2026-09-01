# Readability and Naming — Mobile and Admin Panel

**Scope:** `Mobile/lib` (42 Dart files, 13,923 lines) and `Web/serbis-admin-vue/src`
(31 files, 11,524 lines). The Laravel backend is not covered here — it has its own
two audits already.

**Date:** 2026-08-31. **Read-only. Nothing in this file has been applied.**

**Method.** Every count below came from a script over the actual trees, not from
reading a sample. Comments and string literals were stripped before matching so
that prose inside a `///` block could not be counted as code. Where a claim could
not be established mechanically it is marked **Unable to verify** and says what
would settle it.

**Headline:** naming is in better shape than the file sizes suggest. Constants,
private members and class names are consistent and follow each language's own
guide; there is no snake_case leakage from the API into declared identifiers.
The real problems are three: a two-valued language flag threaded through 54 call
sites as a bare `bool`, two client-side icon tables that still describe a
service and equipment catalogue deleted a session ago, and a piece of
user-facing copy that tells residents the opposite of what the app now does.

---

## Severity summary

| # | Finding | Where | Severity |
|---|---------|-------|----------|
| 1 | `bool filipino` / `bool f` threaded through 54 call sites | Mobile, whole widget tree | **8/10** |
| 2 | Register screen tells residents they can log in immediately — they cannot | `register_screen.dart:184` | **8/10** |
| 3 | Icon lookups describe a catalogue that no longer exists | `ServicesConfigView`, `EquipmentInventoryView` | **7/10** |
| 4 | Substring `if`-chains where a lookup table belongs | same two files | 6/10 |
| 5 | `authHeaders(json = true)` — boolean parameter at the call site | `adminUi.ts:27` | 5/10 |
| 6 | 115 single-letter identifiers, most of them `f` | both | 4/10 |
| 7 | `useAuth()` composable filed under `components/index.ts` | Web | 4/10 |
| 8 | Handover-artifact comment addressed to "you" | `components/index.ts:30` | 3/10 |
| 9 | Stock scaffold README never removed | `components/README.md` | 2/10 |

Findings 1, 3 and 4 overlap with `code-quality-metrics-standards.md` and
`design-pattern-implementation.md`; each is written up once, in the audit where
the fix belongs, and cross-referenced from the others.

---

## Finding 1 — the language flag is a positional `bool`, 54 call sites deep

**Severity 8/10.** Not a style preference: this is the single most-repeated
identifier decision in the mobile codebase and it is load-bearing.

The source of truth is an enum:

```dart
// Mobile/lib/state/request_store.dart:16
enum AppLanguage { english, filipino }
```

Every screen immediately collapses it to a boolean, and the derivation is
written out by hand in **11 places**:

```dart
// Mobile/lib/screens/dashboard_screen.dart:30
final f = appState.language == AppLanguage.filipino;
// Mobile/lib/screens/library_screen.dart:24
final filipino = appState.language == AppLanguage.filipino;
// Mobile/lib/screens/profile_screen.dart:568
final f = current == AppLanguage.filipino;
```

Note the same expression is bound to `f` in some files and `filipino` in others.
It is then passed on at **54 `filipino:` call sites**, and declared as a
positional `bool` on **24 signatures**, including:

```dart
// Mobile/lib/models/request_models.dart:34,136,155,531
String labelFor(bool filipino)
String titleFor(bool filipino)
List<TimelineEntry> timelineFor(bool filipino)
// Mobile/lib/screens/borrow_equipment_screen.dart:118,155
Widget _buildAvailable(bool f)
Widget _buildMine(bool f)
// Mobile/lib/screens/dashboard_screen.dart:238
List<Widget> _announcements(bool f)
```

Three separate problems in one:

1. **`_announcements(true)` at a call site is unreadable.** Nothing at the call
   site says what `true` means. This is exactly the "avoid boolean parameters"
   rule, and Dart already provides the fix for free — named arguments.
2. **`f` is not a name.** It appears in `_buildAvailable(bool f)`,
   `_buildMine(bool f)`, `_announcements(bool f)` and 8 more derivations.
3. **The bool is lossy by construction.** `AppLanguage` has two values today, so
   `bool` is currently equivalent — but the type no longer says "this is a
   language", it says "this is a yes/no". A third language means visiting all 54
   sites, and the compiler will not point at a single one of them.

### Remediation

The smallest honest fix keeps the bool but makes every call site legible, and is
mechanical enough to do in one pass. Make the parameter named and required:

```dart
// before
List<Widget> _announcements(bool f) { ... }
_announcements(f)

// after
List<Widget> _announcements({required bool filipino}) { ... }
_announcements(filipino: filipino)
```

The better fix passes the enum and deletes the 11 hand-written derivations:

```dart
// Mobile/lib/models/request_models.dart
String labelFor(AppLanguage language) =>
    language == AppLanguage.filipino ? _fil : _en;
```

Either way, replace the 11 copies of the derivation with one getter on the store,
so the expression exists once:

```dart
// Mobile/lib/state/request_store.dart, on AppState
bool get isFilipino => language == AppLanguage.filipino;
```

**Do the named-argument pass first.** It is a find-and-replace, it is safe, and
it makes the enum migration reviewable afterwards. See
`design-pattern-implementation.md` Finding 4 for why the two-language assumption
also lives in `translations.dart`'s `(String, String)` tuple.

---

## Finding 2 — the register screen promises something that stopped being true

**Severity 8/10.** This is user-facing copy, not just a comment, and it is now
wrong in a way that will confuse the first resident who reads it.

```dart
// Mobile/lib/screens/auth/register_screen.dart:184-193
// The old copy promised "you will log in afterwards to
// verify your account". No verification step exists, and
// none is being built -- the OTP columns it referred to
// were dead schema and have been dropped. Telling a
// resident to expect one leaves them waiting for a screen
// that never comes.
Text(
  'One account per household, for the head of the family. '
  'You can log in as soon as you have registered.',
```

Both halves are stale. Registration **is** gated on a code now — the resident
goes to `verify_email_screen.dart` and the account does not exist until the code
comes back. The comment states the opposite as settled fact, and the visible
sentence tells the resident they can log in straight away, which they cannot.

A reader who trusts the comment will conclude the verification screen is dead
code.

### Remediation

```dart
// Registration finishes on the code screen: the account is not created until
// the texted code comes back (AuthController::verifyEmail). Say so here, or a
// resident reads the code screen as an error.
Text(
  'One account per household, for the head of the family. '
  'We will text you a 6-digit code to finish signing up.',
```

**Also check the same screen's other copy** — anything else written while the
"no verification" assumption held is suspect. Grep the app for `register` copy
before shipping the next APK.

---

## Finding 3 — two icon tables describe a catalogue that was deleted

**Severity 7/10.** Verified against the seeders, not assumed.

`ServicesConfigView.vue:381` maps a service name to an icon by substring. The
current catalogue is seven services (`ServiceSeeder.php:48-54`): Ambulance/Medical
Response, Relief Goods Distribution, Road Clearing, Power Line Repair, Debris
Removal, Animal Rescue, Sandbagging.

**Five of its twelve branches are unreachable** — `flood`, `fire`, `search` and
`evacuat` name the three services deleted in the 2026-08-30 catalogue pass, and
nothing in the catalogue contains `water`.

`EquipmentInventoryView.vue:278` is worse. The inventory is six items
(`EquipmentSeeder.php:43-48`): Wheelchair, Crutches (pair), Walker, Hospital Bed,
Oxygen Tank, Nebulizer.

- **Seven of nine branches are dead** — `megaphone`, `generator`, `first aid`,
  `rescue`/`tool`, `tent`, `light`/`lamp`, `radio` are all items removed in the
  same pass.
- **Four of the six real items have no branch at all.** Crutches, Walker,
  Hospital Bed and Nebulizer all fall through to the generic
  `mdi-package-variant-closed`. Two thirds of the inventory renders as a
  cardboard box.

This is visible on screen, which is the kind of thing a review panel notices
before it notices anything in this document.

### Remediation

Fix the data first, then the shape (Finding 4). Minimum viable correction:

```js
// EquipmentInventoryView.vue — cover what is actually in the inventory
if (n.includes('wheelchair')) return 'mdi-wheelchair'
if (n.includes('crutch'))     return 'mdi-medical-bag'
if (n.includes('walker'))     return 'mdi-walk'
if (n.includes('bed'))        return 'mdi-bed'
if (n.includes('oxygen'))     return 'mdi-gas-cylinder'
if (n.includes('nebuli'))     return 'mdi-air-humidifier'
return 'mdi-package-variant-closed'
```

**Unable to verify:** whether production's live inventory matches the seeder.
`serbis-status.md` records that production equipment still needs correcting by
hand, so the live list may contain items neither table covers. Reading
`GET /api/equipments` against production would settle it.

---

## Finding 4 — substring `if`-chains where a table belongs

**Severity 6/10.** Same two functions as Finding 3, now as a shape problem.

`serviceIcon` is **cyclomatic complexity 18 in 16 lines**; `itemIcon` is **14 in
13 lines**. They are the two densest decision points in the admin panel and they
are both doing a dictionary lookup by hand. They are also near-identical to each
other — the same algorithm written twice.

### Remediation

One ordered table plus one shared helper replaces both, and drops each function
to complexity 2:

```ts
// composables/iconFor.ts  (new)
/**
 * First matching substring wins, so order matters: put the specific entries
 * above the general ones. Ordered as an array rather than an object because
 * object key order is not something to rely on for matching.
 */
export function iconFor(
  name: string | null | undefined,
  table: ReadonlyArray<readonly [string, string]>,
  fallback: string,
): string {
  const needle = (name || '').toLowerCase()
  return table.find(([key]) => needle.includes(key))?.[1] ?? fallback
}
```

```js
// EquipmentInventoryView.vue
const ITEM_ICONS = [
  ['wheelchair', 'mdi-wheelchair'],
  ['crutch',     'mdi-medical-bag'],
  ['walker',     'mdi-walk'],
  ['bed',        'mdi-bed'],
  ['oxygen',     'mdi-gas-cylinder'],
  ['nebuli',     'mdi-air-humidifier'],
]
const itemIcon = (name) => iconFor(name, ITEM_ICONS, 'mdi-package-variant-closed')
```

The table is then a piece of data sitting next to the catalogue it mirrors, which
is the thing that would have made Finding 3 obvious when the catalogue changed.

---

## Finding 5 — `authHeaders(json = true)` is a boolean parameter

**Severity 5/10.** Worth fixing now, while it has **zero call sites**, rather
than after the eleven-view migration puts `authHeaders(false)` in the codebase.

```ts
// Web/serbis-admin-vue/src/composables/adminUi.ts:27
export function authHeaders(json = true): Record<string, string> {
```

`authHeaders(false)` at a call site says nothing about what is being switched
off. The docblock has to explain it, which is the tell.

### Remediation

Two named functions, no flag:

```ts
export function authHeaders(): Record<string, string> {
  return {
    Authorization: `Bearer ${getToken()}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }
}

/**
 * Headers for a multipart upload. Deliberately omits Content-Type: setting it
 * by hand stops the browser generating the multipart boundary. FilesView and
 * ServiceRequestQueue's walk-in dialog are the callers that need this.
 */
export function uploadHeaders(): Record<string, string> {
  return {
    Authorization: `Bearer ${getToken()}`,
    Accept: 'application/json',
  }
}
```

`uploadHeaders()` reads correctly at the call site with no docblock needed.

---

## Finding 6 — 115 single-letter identifiers

**Severity 4/10.** Lower than it looks, because they are not evenly bad.

Three groups:

- **`f` for the language flag — the largest group.** Covered by Finding 1; fixing
  that removes most of this count.
- **Loop and lambda parameters** — `r`, `o`, `b`, `i`, `m` in `.map`/`.where`
  callbacks, e.g. `borrow_equipment_screen.dart:177`, `track_screen.dart:271`,
  `form_inputs.dart:168`. These are conventional in Dart and idiomatic in short
  closures. **Leave them.** Renaming one-line lambdas is churn, not readability.
- **One genuinely defensible case:**

  ```dart
  // Mobile/lib/models/borrow_models.dart:257-259
  final y = int.tryParse(parts[0]);
  final m = int.tryParse(parts[1]);
  final d = int.tryParse(parts[2].substring(0, 2));
  ```

  `y`/`m`/`d` inside a four-line `_parseDate` are clear from context and the
  function has a good docblock above it explaining why a calendar date is not
  parsed as an instant. **Leave this too.**

### Remediation

Fix Finding 1 and re-measure. Do **not** run a blanket rename — the remaining
hits are the cases where a short name is correct.

---

## Finding 7 — `useAuth()` is filed under `components/`

**Severity 4/10.**

`Web/serbis-admin-vue/src/components/index.ts` contains no component and is not a
barrel file. It exports a composable:

```ts
export function useAuth() { ... }
```

Seven composables live in `src/composables/`. This is the eighth, in the wrong
folder, under a filename that implies re-exports. Anyone looking for the logout
logic will search `composables/` and not find it.

### Remediation

```
git mv src/components/index.ts src/composables/useAuth.ts
```

then update the importers. **Unable to verify** how many importers there are
without resolving Vue's auto-import plugin —
`src/components/README.md` documents `unplugin-vue-components` auto-registering
this directory, so a plain grep may under-count. `npx vue-tsc --noEmit` after
the move is the check that settles it.

---

## Finding 8 — a comment addressed to "you"

**Severity 3/10.** Cosmetic, but it is the kind of thing a panel reads aloud.

```ts
// Web/serbis-admin-vue/src/components/index.ts:30
// Fixed to match your router's path mapping
router.push('/login')
```

"Fixed to match **your** router" is a handover note, not a code comment. It
describes an edit rather than the code, and it is the only comment of its kind in
either tree. Delete it — `router.push('/login')` needs no gloss.

---

## Finding 9 — stock scaffold README still present

**Severity 2/10.**

`Web/serbis-admin-vue/src/components/README.md` is the unmodified Vuetify
scaffold file explaining `unplugin-vue-components` to a first-time user. Delete
it, or replace it with one line naming what actually lives in the folder.

---

## What is already right

Stated plainly because an audit that only lists problems misrepresents the code.

- **Constants are consistent, and correctly different per language.** All 31
  module constants in the panel are `SCREAMING_CASE` (`TOKEN_KEY`, `API_BASE`,
  `CREDENTIAL_ROUTES`, `ROW_HEIGHT`). All 56 Dart constants are lowerCamel, with
  the private ones prefixed `_` (`_logArea`, `_pollInterval`, `_tokenKey`) —
  which is what the Dart style guide asks for. **The generic "constants should be
  UPPER_CASE" rule does not apply to Dart** and the code is right to ignore it.
- **No snake_case leakage.** Zero declared identifiers in either tree use
  snake_case, despite the whole API speaking it. `resident_id` appears only as a
  map key or JSON field, which is correct — that is the wire format, not a
  local name.
- **No debug leftovers.** Zero `print()` or `debugPrint()` in `Mobile/lib`. The
  seven `console.*` calls in the panel are all `console.error` in a `catch`, which
  is deliberate error logging, not a stray trace.
- **One TODO-shaped grep hit and it is a false positive** — `'09XXXXXXXXX'` is a
  phone-number placeholder in `form_inputs.dart:51`.
- **Comment density is healthy and the comments are the right kind.** Mobile
  14.9% (1,658 / 11,092), panel 9.6% (911 / 9,532). Spot-reading shows these
  explain *why* — `authToken.ts` on the two expiry clocks, `config/api.ts` on why
  there is no localhost fallback, `apiSession.ts` on why credential routes are
  exempt. The readability prompt warns that many comments signal a smell; that
  is true of comments restating *what* the code does, and these are not those.
  **Findings 2 and 8 are the exceptions** — one stale, one a handover note.

---

## Naming convention guide

Derived from what the code already does, so adopting it is mostly ratification.
Where the two codebases differ, they differ because the languages do.

### Both codebases

| Thing | Rule | Example in-tree |
|---|---|---|
| Domain terms | Use the MDRRMO's word, not a synonym. `resident`, `barangay`, `borrowing`, `conduction request`, `head of the family` | `ConductionRequestView.vue` |
| Spelling | American, matching the framework APIs (`color`, `canceled` only where an API forces it) | `AppColors` |
| Booleans | Name the true case: `isConfigured`, `hasVerifiedEmail`, `scheduleForLater` | `api_service.dart:41` |
| Wire fields | Keep the API's snake_case **only** as map keys and JSON strings. Never as a declared identifier | `body.append('resident_id', ...)` |
| Abbreviations | Only where the domain already abbreviates: `MDRRMO`, `SMS`, `OTP`, `API`, `ID`. Never invent one | — |

### Dart (`Mobile/lib`)

| Thing | Rule | Example |
|---|---|---|
| Classes, enums | `PascalCase`, noun | `AppState`, `AppLanguage` |
| Methods, variables | `lowerCamelCase`, verb-first for methods | `loadToken()`, `submitRequest()` |
| Constants | `lowerCamelCase` — **not** UPPER_CASE | `_pollInterval` |
| Private | leading `_`, applies to members *and* file-level declarations | `_readSecureToken()` |
| Booleans in signatures | **named and required**, never positional | `({required bool filipino})` |
| Widget build helpers | `_buildX` only when it returns a `Widget` | `_buildAvailable` |

### TypeScript / Vue (`Web/serbis-admin-vue/src`)

| Thing | Rule | Example |
|---|---|---|
| Components | `PascalCase.vue`, noun | `ServiceRequestQueue.vue` |
| Composables | `useX.ts` in `src/composables/`, exporting `useX()` | `useAppTheme.ts` |
| Pure helpers | plain named export, no `use` prefix — `use` means "has reactive state" | `authHeaders()`, `iconFor()` |
| Module constants | `SCREAMING_CASE` | `API_BASE`, `ROW_HEIGHT` |
| Refs and computed | `lowerCamelCase`, noun; no `Ref`/`Computed` suffix | `filteredEquipments` |
| Event names | kebab-case in `defineEmits` | `'dispatch-booking'` |
| Files that are not components | never `index.ts` unless it only re-exports | see Finding 7 |

### One rule that is not about names

A comment must explain **why**, never **what**. A comment that describes an edit
("Fixed to match…") or restates the line below it is deleted, not reworded. A
comment that is no longer true is a bug — Finding 2 is that bug.

---

## Suggested order

1. **Finding 2** — 10 minutes, and it is wrong copy in front of residents.
2. **Finding 3** — 20 minutes, visible on screen, verified against the seeders.
3. **Finding 5** — 10 minutes, and it must land *before* the eleven-view
   `authHeaders()` migration or it multiplies by eleven.
4. **Finding 1, named-argument pass** — half a day, mechanical, big readability
   return. The enum migration can wait.
5. **Findings 4, 7, 8, 9** — an hour together, low risk.
6. **Finding 6** — re-measure after 1; expect little left to do.
