# Equipment Borrowing — UX audit

**Date:** 2026-08-03
**Branch:** `jamby-frontend-fixes`
**Surface:** SERBIS admin panel, MDRRMO Echague (Isabela)
**Status:** Phase 1 — audit only, no code written

---

## 0. Skills loaded, and what they constrain

| Skill | Loaded | How |
|---|---|---|
| `ui-ux-pro-max` v2.11.0 | Yes, rules only | `SKILL.md`, `references/quick-reference.md` (all 10 categories), `references/pro-rules.md`, `data/products.csv`, `data/stacks/vue.csv` |
| `caveman` | Active (session-wide) | Prose style only; no bearing on design decisions |

**The skill's `search.py` could not be run.** Python is not installed on this machine (`python`, `python3`, `py` all absent). Per the skill's own "If a search returns 0 results" rule I am not fabricating tool output: every recommendation below is traced to a rule I read directly out of the skill's `data/*.csv` and `references/*.md`, and I cite the rule id. What is lost by not running the script is the generated palette/typography proposal — not the rule set, which is fully readable on disk.

**Constraints the skill imposes on this task:**

1. **`products.csv` row 13 — Government/Public Service** is this product's row: primary style *Accessible & Ethical + Minimalism*, dashboard style *Executive Dashboard*, key consideration **"WCAG AAA mandatory. Trust paramount."** This raises the accessibility bar above what the task specified — see the conflict in §0.1.
2. **Priority order is 1→10**, accessibility first. Horizontal-overflow and colour-only status are category 1/5 failures and therefore outrank every aesthetic change.
3. **`pro-rules.md` is explicitly out of scope here** — its own scope notice says it targets native/mobile (touch targets, safe areas, gestures). This is desktop web, so `quick-reference.md` governs. I am not applying the 44×44pt touch minimum as a hard rule to a mouse-driven admin panel, though I do apply it to the tablet breakpoint.
4. **`stacks/vue.csv`** binds implementation: `<script setup>` (#2), `computed` for derived state (#6), `v-memo` for expensive lists (#36), semantic elements not `div` handlers (#46, severity High), dynamic ARIA binding (#47), `key` on `v-for` (#28, High).
5. **`ux-guidelines` category 5 `horizontal-scroll`** and category 2 `gesture-conflicts` between them condemn the current board outright.

### 0.1 Conflict: skill says AAA, task says AA

The task sets WCAG **AA** (4.5:1) as the bar. The skill's product row for Government/Public Service says **AAA mandatory** (7:1 normal text).

I am **not** silently applying AAA, and I am flagging rather than overriding, because AAA collides with a second constraint the task also states as fixed:

- Primary green `#297A67` on white measures **5.16:1**. That passes AA and **fails AAA**.
- The token was chosen deliberately (see `plugins/vuetify.ts`) — `#2E8B75` was rejected for reaching only 4.15:1.
- Meeting AAA therefore means changing the brand green across the whole panel, not just this page.

**Recommendation:** enforce AA as the hard floor for everything this task touches (no exceptions, verified numerically), and take AAA where it costs nothing. Treat "move the panel to AAA" as a separate decision, because it is a brand-token change with panel-wide blast radius. **This needs your call** — it is the one place I have not simply followed the skill.

---

## 1. Files and data model

### Frontend

| File | Role |
|---|---|
| `Web/serbis-admin-vue/src/views/EquipmentBorrowingView.vue` | The entire feature — board, cards, detail modal, filters, API calls. 471 lines, no child components. |
| `Web/serbis-admin-vue/src/config/api.ts` | `API_BASE`, build-time from `VITE_API_BASE`. **No hardcoded URLs in this view** — it already uses the constant correctly. |
| `Web/serbis-admin-vue/src/composables/authToken.ts` | `getToken()` for the bearer header. |
| `Web/serbis-admin-vue/src/plugins/vuetify.ts` | Theme tokens, both themes. |
| `Web/serbis-admin-vue/src/App.vue` | Global `.subtle-surface` = `rgba(var(--v-theme-on-surface), 0.05)`. |
| `Web/serbis-admin-vue/src/components/AppSidebar.vue` | `v-navigation-drawer permanent width="260"` — **permanent at every breakpoint**, which is load-bearing for the overflow maths below. |

There is **no store and no composable** for this feature. State is local `ref`s; `fetch` is called inline. That is consistent with neighbouring views (`ManageRequestView`, `LogsView` do the same), so it is a codebase convention, not a defect to fix here.

### Backend

| File | Role |
|---|---|
| `app/Http/Controllers/EquipmentBorrowingController.php` | `index`, `store`, `show`, `update`. |
| `app/Models/EquipmentBorrowing.php` | Table `tbl_equipment_borrowing`, PK `borrow_id`. |
| `database/migrations/2026_06_22_042205_create_tbl_equipment_borrowing_table.php` | Schema. |

**There is no API Resource class.** `index()` returns `response()->json($query->get())` — the raw model plus eager-loaded relations. Payload shape is therefore whatever the model exposes.

### Actual fields per borrowing record

| Field | Type | Notes |
|---|---|---|
| `borrow_id` | PK | View falls back `item.borrow_id \|\| item.id` |
| `resident_id` | FK | |
| `equipment_id` | FK | |
| `quantity` | int | |
| `status` | enum | `Pending, Approved, Released, Returned, Denied` — enforced in both the migration and `update()` validation |
| `released_at` | timestamp, nullable | Set only on `Released` |
| `returned_at` | timestamp, nullable | Set only on `Returned` |
| `created_at` / `updated_at` | timestamps | |
| `resident.*` | relation | `first_name`, `last_name`, `phone_number`, `barangay.barangay_name` |
| `equipment.*` | relation | `item_name`, `available_quantity` |

### Fields that do not exist — and what that blocks

- **No `due_date` / `expected_return_date`.** There is no field anywhere that says when an item is due back.
- **No `remarks` / `denial_reason`.** Denying a request captures no reason.
- **No `approved_at` / `denied_at`.** Only release and return are timestamped.

**Consequence for design requirement #2 ("overdue is unmissable"): overdue is not computable from the current API.** Per the task's own constraint I am not inventing a client-side stand-in (e.g. "released_at + 7 days"), because that would be a business rule fabricated in the frontend and shown to a defence panel as fact. Migration proposed in §5.

Aging **is** computable: days-in-Pending = `now - created_at`, and days-since-release = `now - released_at`. Both fields exist. So half of requirement #2 ships now, half needs the migration.

---

## 2. UX audit

Structured by the skill's 10 priority categories. Rule ids are the skill's own.

### Category 1 — Accessibility (CRITICAL)

**1.1 — Status is conveyed by colour alone in three places.** Rule `color-not-only`, `color-not-decorative-only`.
The count badge is a coloured pill containing only a number (line 51). The column top border is a 3px accent stripe (line 397). The terminal-state card marker is a bare icon whose meaning is carried by `col.accent` (lines 125–127). The column header does pair icon + text label — that one is fine. A red/green colour-blind user cannot distinguish Returned from Denied at the card level.

**1.2 — Cards are `div`s with hand-rolled button semantics.** Rule `keyboard-nav`, and `stacks/vue.csv` #46 (severity **High**).
Line 58–62: `<div class="kanban-card" role="button" tabindex="0" @click @keydown.enter>`. Two defects: **Space does not activate it** (only Enter is bound), which breaks the native button contract; and the card has no accessible name, so a screen reader announces "button" with the concatenated inner text.

**1.3 — Focus ring is removed and replaced with a shadow.** Rule `focus-states` (2–4px visible ring).
Lines 429–434: `.kanban-card:focus-visible { transform: translateY(-2px); box-shadow: …; outline: none; }`. `outline: none` with only a soft shadow as replacement is not a visible focus indicator at the required strength, and it is identical to the `:hover` treatment, so a keyboard user cannot tell focus from mouse-over.

**1.4 — The board has no landmark or list semantics.** Rule `aria-labels`, `voiceover-sr`.
`.kanban-board` is an unlabelled `div`; each column is a `<section>` with no `aria-label`. Nothing announces "Pending, 4 items". Column membership — the entire meaning of the board — is invisible to a screen reader.

**1.5 — Status changes are silent.** Rule `aria-live-errors`, `toast-accessibility`.
`updateStatus()` re-fetches and shows a `v-snackbar`. Vuetify's snackbar is not a live region by default, so a screen-reader user gets no announcement that a request moved from Pending to Approved.

**1.6 — Contrast failures, measured.** Rule `color-contrast`, `color-accessible-pairs`, `contrast-feedback`.
Computed from the sRGB relative-luminance formula against the light theme (`surface #FFFFFF`, `background #F8FAFC`):

| Element | Foreground | Background | Ratio | AA |
|---|---|---|---|---|
| `.qty-pill` text (12px bold) | `#297A67` | `rgba(primary,.12)` over white | **4.40:1** | **FAIL** |
| Card avatar initials (12px bold) | `#297A67` | `rgba(primary,.14)` over white | **4.25:1** | **FAIL** |
| `.stock-warn` text (11.5px, 600) | `#D32F2F` | `rgba(error,.1)` over white | **4.28:1** | **FAIL** |
| "Deny" button label | `#D32F2F` | `#FFFFFF` | 4.98:1 | pass |
| "Deny" label, dark theme | `#F16565` | `#131B2E` | 5.55:1 | pass |
| Count badge, Pending | `#FFFFFF` | `#B45309` | 5.02:1 | pass |
| Count badge, Approved | `#FFFFFF` | `#1D4ED8` | 6.70:1 | pass |
| Count badge, Released | `#FFFFFF` | `#0E7490` | 5.36:1 | pass |
| Count badge, Returned | `#FFFFFF` | `#297A67` | 5.16:1 | pass |
| Count badge, Denied | `#FFFFFF` | `#B91C1C` | 6.47:1 | pass |
| Column top border (3px, UI boundary, 3:1 bar) | `#B45309` | column surface | 4.30:1 | pass |
| Secondary card text | medium-emphasis | `#FFFFFF` | 5.74:1 | pass |

**The brief's hypothesis about the Deny button is wrong, and the real failures are elsewhere.** Deny's red-on-white is **4.98:1 and passes AA** in both themes. The three actual failures are the quantity pill, the card avatar initials, and the stock warning.

The avatar-initials failure is a **known, already-solved bug in this codebase that this view was missed by**: `plugins/vuetify.ts` carries a `primary-strong` token (`#1B5B4B` light, 6.61:1 on the same tint) added specifically to fix `.avatar-initials` elsewhere. Applying the existing token here gives **6.78:1**. No new token needed.

**1.7 — Two type sizes sit below the 12px floor.** Rule `readable-font-size`, `font-scale`.
`.stock-warn` is `0.72rem` ≈ 11.5px. Vuetify's `size="x-small"` button is `0.625rem` = 10px by default — that is the Deny/Approve/Release/Confirm-return label size on every card. *(Vuetify default; worth confirming in-app, since I could not render — see §4.)*

### Category 2 — Touch & Interaction (CRITICAL)

**2.1 — Nested interactive regions.** Rule `gesture-conflicts`, `no-precision-required`.
The card is itself a `role="button"` that opens the modal, and it contains action buttons neutralised with `@click.stop` (line 100). A misclick of 4px between "Approve" and card background produces a completely different outcome — modal instead of state change. At `x-small`, those buttons are roughly 20px tall.

**2.2 — Both destructive actions fire immediately.** Rule `confirmation-dialogs`, `undo-support`, `destructive-emphasis`.
`Deny` (line 106) and `Confirm return` (line 124) both call `updateStatus()` on first click. `Confirm return` mutates stock — `increment('available_quantity')` — and there is no undo and no confirmation. On the board these are one accidental click away.

**2.3 — Every card in a column shows a spinner during any update.** Rule `loading-buttons`.
`processingId` is compared with `item.borrow_id || item.id`, which is correct per-card — but `loading` (the modal's flag) is a separate global ref, so a modal action greys the modal only. Minor, but the two flags duplicate one concept.

### Category 3 — Performance (HIGH)

**3.1 — No virtualisation and no pagination.** Rule `virtualize-lists` (50+ items).
`index()` returns every borrowing ever recorded, unpaginated, and `grouped` renders all of them. Returned/Denied grow without bound; after a year of operation this page ships the entire history to the browser on every visit. This is backend audit item **#10** and is out of scope to fix here, but it is the reason terminal states must not stay on the board.

**3.2 — `v-memo` unused on the card list.** `stacks/vue.csv` #36. Low priority until 3.1 is addressed.

### Category 4 — Style Selection (HIGH)

**4.1 — Kanban affordance without kanban behaviour.** Rule `state-clarity`, `standard-gestures`.
Columns plus cards plus a hover lift (`translateY(-2px)`) is the universal visual grammar of drag-and-drop. There is none. Users will attempt to drag on first contact, and the hover lift actively encourages it. The skill's `keyboard-shortcuts` rule also requires a keyboard alternative *to* drag-and-drop — a metaphor that promises a gesture it does not implement fails on both sides.

**4.2 — Terminal states compete with actionable ones.** Rule `primary-action`, `whitespace-balance`.
Returned and Denied occupy 40% of the board's width and carry the same visual weight as the three columns that need action. `kanban-column--muted { opacity: 0.85 }` is a 15% opacity nudge — not a hierarchy.

**4.3 — Status colours are raw hex, outside the token system.** Rule `color-semantic`, `dark-mode-pairing`.
Lines 240–246 hardcode `#B45309`, `#1D4ED8`, `#0E7490`, `#297A67`, `#B91C1C` and use them identically in both themes. The comment argues these are data-viz semantics, which is defensible, but it means dark mode gets light-mode saturation. All five still pass AA as badge backgrounds with white text (measured above), so this is a consistency issue, not a contrast one.

### Category 5 — Layout & Responsive (HIGH)

**5.1 — Horizontal overflow. The headline failure.** Rule `horizontal-scroll`.

Deterministic from the CSS (`.kanban-board { display:flex; gap:16px }`, `.kanban-column { min-width:280px }`, `v-container` `pa-6` = 24px per side, sidebar `permanent width="260"`):

```
board width required = 5 × 280 + 4 × 16          = 1464px
board width available = viewport − 260 − 48      = viewport − 308px
all five columns fit only when viewport ≥ 1772px
```

| Viewport | Available | Result |
|---|---|---|
| 1920 | 1612px | **Fits.** Columns 309.6px each |
| 1600 | 1292px | Overflow 172px |
| 1440 | 1132px | Overflow 332px |
| **1366** | **1058px** | **Overflow 406px.** Denied (starts at x=1184) is entirely off-screen; Returned is clipped, 170px of 280 visible |
| 1024 | 716px | Pending + Approved visible, Released clipped at 124px of 280 |
| 768 (tablet) | 460px | 1.6 columns. Sidebar is `permanent`, so it still consumes 260px |

**This is why the bug shipped:** it renders correctly at 1920px, which is the development machine's resolution. Every laptop below 1772px — including the 1366px class that is the most common projector and school-laptop resolution — loses at least one column. For a defence shown on a projector this is the single highest-risk item on the page.

**5.2 — Vertical space is wasted by construction.** Rule `content-priority`, `whitespace-balance`.
`.kanban-board { align-items: flex-start }` makes every column shrink to its content height. `max-height: calc(100vh - 180px)` is only a ceiling. With a handful of records the board occupies the top third of the viewport while content is being clipped horizontally — the exact inversion of the space that is available.

**5.3 — No breakpoint behaviour at all.** Rule `mobile-first`, `breakpoint-consistency`.
The only media query in the file is `prefers-reduced-motion`. There is no layout change at any width; the board simply scrolls.

### Category 6 — Typography & Colour (MEDIUM)

**6.1 — Not scannable at projector distance.** Rule `visual-hierarchy`, `weight-hierarchy`.
The resident name — the primary identifier — is `text-body-2` (14px). Equipment name is also 14px. Barangay, date and the entire action row are 12px or smaller. Nothing on a card exceeds 14px. From the back of a defence room, a card is a grey block.

**6.2 — Counts are not tabular.** Rule `number-tabular`. Minor: badge widths shift between 1- and 2-digit counts.

### Category 7 — Animation (MEDIUM)

**7.1 — Handled correctly.** `prefers-reduced-motion` is respected (lines 467–470), transitions are 0.15s (inside the 150–300ms band), and only `transform`/`box-shadow` are animated. **No findings.** This is the one category the current implementation passes cleanly.

### Category 8 — Forms & Feedback (MEDIUM)

**8.1 — Empty state is a shrug.** Rule `empty-states`.
`"Nothing here"` in a dashed box (line 132–134) — no icon, no guidance, identical in all five columns. A column empty because work is done and one empty because a filter excluded everything look the same.

**8.2 — Filters have no visible labels and no reset.** Rule `input-labels`, `state-preservation`.
The item `v-select` and search field are `hide-details` with placeholder-only labelling. There is no indication when a filter is active and no clear-all, so an empty board caused by a stale filter reads as an empty board.

**8.3 — Errors surface in two different places inconsistently.** Rule `error-placement`, `error-recovery`.
`apiError` renders inside the modal (line 178); board actions route the same error only to a transient snackbar with no retry. A failed action from the board leaves no trace after 3.5s.

**8.4 — No error state for the initial load.** Rule `error-state-chart`/`timeout-feedback`.
If `fetchData()` throws, `initialLoad` flips false and the user sees five empty columns with "Nothing here" — a load failure is indistinguishable from an empty database.

### Category 9 — Navigation (HIGH)

**9.1 — No deep linking, no state preservation.** Rule `deep-linking`, `state-preservation`.
Filters and the open record are local state only. A specific request cannot be linked to, and re-entering the page resets everything.

### Category 10 — Data (LOW)

**10.1 — No sort control, no bulk selection, no export.** Rule `sortable-table`, `export-option`. Sort is hardcoded newest-first (line 289).

### Found in passing — not UX, flagged not fixed

- **`update()` enforces no state machine.** Any status can move to any other; `Returned → Pending` is accepted. Only `Released` and `Returned` have side effects, so a `Returned → Released` transition would decrement stock a second time for an item already back on the shelf.
- **`Denied` from `Released` returns stock but never sets `returned_at`** (lines 98–101), so the item has no return timestamp despite being physically back.
- **`store()` does not check `available_quantity`**, so residents can queue requests for stock that does not exist. The check happens only at release.

---

## 3. Two layout directions

### Direction A — Actionable board + History tab

Three columns (Pending, Approved, Released) sized to always fit without horizontal scroll. Returned and Denied move behind a segmented control into a filterable `v-data-table`.

```
required = 3 × 280 + 2 × 16 = 872px   →  fits from viewport 1180px up
at 1366px: 1058 available, 3 columns × 342px each — comfortable
```

- **Fixes:** 5.1 (headline), 5.2, 4.2, 3.1 (history is paginated and virtualised, board stays bounded), 8.1.
- **Keeps:** at-a-glance pipeline sense, which is the reason a board was chosen and the thing a defence panel reads instantly.
- **Costs:** two surfaces to build and maintain. Terminal records are one click away rather than visible.

### Direction B — Dense data table with status grouping

One sortable, filterable `v-data-table`. Status becomes a column with icon + label + colour; actions become row actions; "overdue" becomes a sort.

- **Fixes:** 5.1, 5.2, 3.1, 10.1, and 4.1 outright (no false drag affordance).
- **Costs:** loses pipeline-at-a-glance entirely. "How many are waiting?" becomes a filter operation instead of a glance. Weaker on a projector — a table of 12px rows is the least readable thing at distance, working directly against requirement #5.

### Recommendation: **A**

Three reasons, in order:

1. **Volume does not justify B.** Three barangays, one municipal office. The pipeline is tens of records, not thousands. B optimises for volume this deployment will not see, and pays for it in glanceability.
2. **The defence context rewards A.** Requirement #5 asks for readability across a room. Three wide columns with large counts read as a pipeline from a projector. A dense table does not.
3. **A matches the operator's actual mental model.** The page's own subtitle already describes a pipeline: *"Move each request through the pipeline — approve, release, then confirm its return."* The board is the right metaphor; it is simply carrying two states that do not belong on it.

**One correction to A as specified:** the brief's phrasing keeps the kanban columns. I recommend also **removing the drag affordance** — the hover lift and `role="button"` card — and replacing it with an explicit, keyboard-reachable action row. Direction A fixes the overflow but leaves finding 4.1 (a metaphor promising a gesture that does not exist) untouched otherwise. Rule `standard-gestures` and `keyboard-shortcuts`.

---

## 4. What I could not verify, and why

- **No browser rendering.** Playwright's Chromium is not installed (`Chrome distribution not found`), and this machine is Edge-only. The responsive table in §5.1 is exact arithmetic over the CSS, not a guess — flexbox with a fixed `min-width` is deterministic — but it has not been visually confirmed. **You have the app open at `localhost:3000`; narrowing the window to 1366px is a 10-second check of the headline finding.**
- **Vuetify's `x-small` button font-size (10px)** is quoted from the framework default, not measured in the DOM.
- **Dark theme** contrast was computed for the Deny label only; the three failing pairs were measured in light theme, where they fail. Dark theme needs its own pass during implementation.

---

## 5. Backend changes recommended (not made)

Requirement #2 ("overdue is unmissable") **cannot be built** against the current schema. Proposed, for your approval as a separate change:

```php
// Migration: add_due_date_to_tbl_equipment_borrowing
$table->date('due_date')->nullable()->after('quantity');
$table->string('denial_reason', 255)->nullable()->after('status');
```

- **`due_date`** — set when a request is Approved or Released. Without it, "overdue" has no definition. Nullable so existing rows remain valid; the UI shows "No due date" rather than inventing one.
- **`denial_reason`** — currently a denial is recorded with no explanation, which the resident-facing mobile app cannot surface either.
- `update()` would need `due_date` added to its validation rules, and `EquipmentBorrowing::$fillable` extended. Both are small.
- **Until this lands, the redesign ships days-in-Pending and days-since-release aging** (both computable from existing fields) and omits true overdue detection rather than faking it.

---

## 6. Decisions needed before Phase 2

1. **AA or AAA?** (§0.1) — AAA requires changing the brand green panel-wide.
2. **Direction A or B?** — recommendation is A.
3. **Approve the `due_date` migration?** — if no, requirement #2 ships partially and that limitation is permanent.
