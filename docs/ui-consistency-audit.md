# Admin panel — visual-consistency audit (Phase 1: audit only)

Read-only. Nothing in this file has been applied — this is the Phase 1
inventory + recommendation description for a later, separate implementation
pass. Produced against `update-admin-vue` at commit `654e0bb` by reading
`Web/serbis-admin-vue/src` in full: all 14 files under `src/views/`, the four
named shared components (`AppSidebar.vue`, `DateTimePickerField.vue`,
`ResidentDetailPanel.vue`, `ServiceRequestQueue.vue`), `composables/adminUi.ts`
and `plugins/vuetify.ts`. No screenshots were taken and the app was not run —
every finding below is a source citation, `file:line`. Two existing audits in
this folder cover adjacent ground and are not duplicated here:
`docs/ui-audit/inventory.md` + `findings.md` (UX/interaction audit, screenshot
verified) and `docs/dispatch-audit.md` (ambulance state-machine audit). This
one is about visual *system* consistency — do the same kind of thing (a page
header, a status pill, a table column, a filter row) look and behave the same
way everywhere it appears.

Per the task brief: two layout patterns (split-pane list+detail, and
full-width table) are both intentional and not in question here. Nothing
below argues a view should switch patterns.

---

## 1. Page headers — title, subtitle, actions

Every table-style view opens with a `<h2>`/title + one-line description +
(usually) a top-right action button, but the markup, type scale and spacing
were each written from scratch per view rather than sharing one component,
and it shows:

| View | Title markup | Subtitle | Header wrapper / spacing | Action placement |
|---|---|---|---|---|
| `DashboardView.vue:5-9` | `<h1 class="text-h4 font-weight-black mb-1">` | `text-subtitle-1 text-medium-emphasis` | bare `d-flex justify-space-between … mb-6` | bell menu + static avatar, top-right (no button) |
| `VehiclesView.vue:7-15` | `<h2 class="text-h4 font-weight-bold text-high-emphasis tracking-tight">` | `text-subtitle-2 text-medium-emphasis` | bare `d-flex flex-wrap justify-space-between align-center gap-4 mb-6` | 1 filled button, top-right |
| `EquipmentInventoryView.vue:7-15` | same as Vehicles (`text-h4 font-weight-bold … tracking-tight`) | `text-subtitle-2` | identical wrapper to Vehicles | 1 filled button, top-right |
| `ProcurementReferenceView.vue:7-20` | same `text-h4 font-weight-bold … tracking-tight` | `text-subtitle-2` | identical wrapper | 1 **tonal** button ("Refresh"), top-right |
| `StaffView.vue:7-21` | same `text-h4 font-weight-bold … tracking-tight` | `text-subtitle-2` | identical wrapper | 1 filled button, top-right |
| `ServicesConfigView.vue:7-14` | same `text-h4 font-weight-bold … tracking-tight` | **`text-subtitle-1`** (not `-2`) | identical wrapper | **no button at all** — header is title+subtitle only |
| `LogsView.vue:6-9` | `<h2 class="text-h4 font-weight-black">` (matches Dashboard's weight, not the `-bold` group above) | `text-subtitle-1` | bare `d-flex justify-space-between align-center mb-6` | a **search field**, not a button, top-right |
| `SmsView.vue:8-17` | `<h2 class="text-h4 font-weight-black">` inside a card header row with an icon avatar | `text-subtitle-1 font-weight-medium` | header lives inside `v-card`'s own `pa-8 border-b`, not the page container | account balance figures pushed right (not a button) |
| `EquipmentBorrowingView.vue:4-9` | `<h2 class="text-h5 font-weight-bold text-high-emphasis">` (h5, one size down from the h4 group, no `tracking-tight`) | `text-subtitle-2` | custom `.page-header` class, not the inline `d-flex` others use | none in the header — actions live in the tab bar below |
| `FilesView.vue:9-14` | `<h2 class="text-h5 font-weight-bold text-high-emphasis">` | `text-subtitle-2`, but the text is a live count ("`N` published · …") not a static description | header sits inside a wrapping `v-card class="pa-6"` — the only table view whose whole page is one big card | filter controls occupy the header's right side, no button |
| `UsersView.vue:9-24` | `<h2 class="text-h5 font-weight-bold text-high-emphasis">` | **`text-body-2`**, count text (not any `text-subtitle-*` class) | header is a `.residents-toolbar` with `border-b`, `px-6 py-3` — not the bare-div pattern any other view uses | button **and** search + select share the header's right side |
| `ConductionRequestView.vue:19-25` | `<h2 class="text-h5 font-weight-bold text-high-emphasis">` | `text-subtitle-2` | `.page-header` wrapper (own class, distinct from EquipmentBorrowingView's identically-named one — see below) | 1-3 buttons, top-right, swapped per active tab |
| `ServiceRequestQueue.vue:28-31` (standalone header, used by `ManageRequestView.vue` and reused conceptually by ConductionRequestView's Bookings tab) | `<h2 class="text-h5 font-weight-bold" style="line-height:1; margin-bottom:4px;">` — **inline `style`, not a Vuetify spacing class** | `text-body-2 text-medium-emphasis` with `style="line-height:1;"` — also inline, not `text-subtitle-2` | bare `d-flex justify-space-between … mb-3` (`mb-3`, not the `mb-6` every full-width-table view uses) | up to 3 buttons of 3 different `variant`s (flat / text / text), top-right |
| `LoginView.vue:20-25` | `<h1 class="text-h3 font-weight-black">` | italic custom `.brand-subtitle` | N/A — this is a brand splash, not a data view | N/A |

**What this shows, concretely:**

- **Title size** splits three ways: `text-h4` (Dashboard, Vehicles, Equipment
  Inventory, Procurement, Staff, Manage Services, Logs, Sms — 8 views),
  `text-h5` (Equipment Borrowing, Files, Residents, Ambulance Dispatch, and
  the `ServiceRequestQueue` standalone header — 5 surfaces), `text-h3`
  (Login, a deliberate outlier).
- **Title weight** splits two ways inside the `text-h4` group itself:
  `font-weight-bold` (Vehicles, Equipment Inventory, Procurement, Staff,
  Manage Services) vs. `font-weight-black` (Dashboard, Logs, Sms) — same
  type size, visibly heavier stroke on three of the eight.
- **Subtitle class** splits four ways: `text-subtitle-2` (6 views),
  `text-subtitle-1` (Manage Services, Logs, Sms, Dashboard — 4 views, one
  size larger than the other group), `text-body-2` (Users — smaller again),
  and a hand-written inline `style` in `ServiceRequestQueue.vue:30` that
  matches neither Vuetify class.
- `.tracking-tight` (`letter-spacing: -0.02em`) is applied to every `text-h4`
  title except Dashboard's, Logs', and Sms' (the three `font-weight-black`
  ones) and is absent from every `text-h5` title — so the letter-spacing
  treatment and the font-weight treatment happen to travel together by
  accident of copy-paste, not by rule.
- Header **wrapper spacing** is `mb-6` everywhere except
  `ServiceRequestQueue.vue:27` (`mb-3`) and Users/Files/Sms, which don't use
  a bare header div at all (card- or toolbar-wrapped instead).
- Two files independently define a class literally named `.page-header`
  (`EquipmentBorrowingView.vue` template line 4, CSS rules further down; and
  `ConductionRequestView.vue` template line 19, own CSS rules) — same name,
  two unrelated, differently-scoped definitions. Anyone searching the
  codebase for "the page header component" will find two dead ends, not one
  shared thing.

---

## 2. Filter / counter rows

At least **seven** distinct patterns are in use for "narrow this list" +
"tell me how many," even discounting the split-pane views' own status filter:

1. **Mandatory `v-chip-group` with per-status counts baked into the chip
   label** — `ServiceRequestQueue.vue:159-171` (`{{ status }} <span>{{
   requestCounts[status] }}</span>`, active chip `variant="flat"`, zero-count
   chips demoted to `outlined` via `status-filter-chip--muted`).
2. **Plain `v-select` + `v-text-field`, no counts shown in the controls
   themselves** — `EquipmentInventoryView.vue:36-49`,
   `VehiclesView.vue:54-67`, `EquipmentBorrowingView.vue:27-77` (three
   selects), `ConductionRequestView.vue:90-113` (Trip Logs tab).
3. **Clickable "stat tile" cards that double as the filter** — Fleet
   readiness tiles, `VehiclesView.vue:35-48` (`.stat-tile`, column layout,
   `min-width:104px`, `gap:2px`, value at `1.6rem`) and the borrowing status
   strip, `EquipmentBorrowingView.vue:146-190` (also `.stat-tile`, but *row*
   layout, `gap:10px`, value at `1.15rem`, active state driven by a
   `--tile-accent` CSS var + `color-mix()` instead of Vehicles' hardcoded
   per-status classes). Same class name, same job, two structurally
   different implementations — see `EquipmentBorrowingView.vue:1575-1604` vs.
   `VehiclesView.vue:498-517`; a comment at
   `EquipmentBorrowingView.vue` calls this "the same mechanic as Fleet
   Management's readiness tiles," which is true of the *idea*, not the CSS.
4. **Closable "active filters" chip row + "Clear all"** —
   `ServicesConfigView.vue:61-84` ("Filters:" label) and
   `EquipmentBorrowingView.vue:80-104` ("Filtered by" label) — same idea,
   different label text, independently implemented.
5. **A single closable summary chip for one filter, no row for the rest** —
   `VehiclesView.vue:68-76` (only the status filter gets a removable chip;
   the type filter and search box do not).
6. **Scrolling `v-slide-group` chip row, mandatory selection, no counts** —
   `FilesView.vue:17-31` (type filter: All/PDF/Images/Documents/Archives).
7. **Underlined text-button "tabs," not chips at all** —
   `UsersView.vue:69-92` (`.tab-btn`/`.active-tab`, `aria-pressed`, a 3px
   bottom border) for the barangay filter — the only place in the app a
   filter row is built from `v-btn` rather than `v-chip` or `v-select`.

Plain count text with no controls attached also differs in wording: "`N` of
`M` services" (`ServicesConfigView.vue:55-58`), "`N` of `M`" bare
(`ProcurementReferenceView.vue:74`), "`N` of `M` residents" only when
filtered, nothing when not (`UsersView.vue:15-24`).

---

## 3. Status chips — every distinct status vocabulary, every rendering

This is the largest source of real inconsistency in the panel. The same
underlying idea — "a colored pill naming this row's state" — is implemented
**eleven** different ways across the app, and in at least four cases the
*same word* gets a *different color* depending which screen it's read on.

### 3a. Service request statuses (Pending / Booked / Responding / Resolved /
Disapproved / Cancelled, plus the derived "Resolved — no arrival")

- **Canonical implementation**: `.status-pill` / `.pill-*`,
  `ServiceRequestQueue.vue:2711-2773`. Colors: Pending → `warning-strong` on
  a 14% `warning` tint; Booked → **literal hardcoded violet** `#5B21B6` /
  `#6D28D9` (no theme token exists for it, comment at line 2733-2738 says so
  explicitly); Responding → `info-strong`; Resolved → `success-strong`;
  Disapproved/Cancelled → `error-strong`; "Resolved — no arrival" → literal
  slate `#334155`/`#94A3B8`. Reused correctly by `ConductionRequestView.vue`
  (trip-log status pills, via `outcomePillClass`/`sharedStatusLabel` from
  `composables/adminUi.ts:114-169`) — this pairing is internally consistent
  and is the one place in the app where a shared status vocabulary was
  actually centralized in a composable rather than copy-pasted.
- **Second, incompatible rendering of the same six words**:
  `DashboardView.vue:418-432`, `getStatusColor()`, feeds a plain `v-chip
  color="…" variant="tonal"` in the activity feed. Mapping: `pending` →
  `warning` (agrees), `responding` → **`primary`** (ServiceRequestQueue says
  `info` — **different color for the same status**), `resolved` → `success`
  (agrees), `cancelled`/`disapproved` → `error` (agrees), and **`booked` has
  no case at all** — it silently falls through to the `default: 'grey'`
  branch, so a Booked request shows as an undifferentiated grey chip on the
  dashboard feed while showing as violet everywhere else in the app.

### 3b. Equipment-borrowing statuses (Pending / Approved / Released /
Returned / Denied / Cancelled)

- **Canonical implementation**: a shared, hardcoded hex+icon table,
  independently defined but byte-identical in spirit in
  `EquipmentBorrowingView.vue:781-789` (`columns`) and
  `ProcurementReferenceView.vue:168-175` (`STATUS_META`) — flat `v-chip
  variant="flat"` with `:style="{ backgroundColor: accent, color:
  '#FFFFFF' }"`. This pairing is deliberately kept in sync (a comment in
  Procurement's file says so) and *is* consistent between the two views —
  good baseline, cited here as the positive example.
- **Third, incompatible rendering of the same word set**:
  `DashboardView.vue:418-432`'s `getStatusColor()` — the *same* function that
  handles service-request statuses above also handles these, mapping
  `approved`/`released` → `primary` (green) and `returned` → `success`
  (green), against Procurement/Borrowing's own `Approved` = blue `#1D4ED8`
  and `Released` = teal `#0E7490`. **"Approved" is a blue flat chip on the
  Borrowing board and a green tonal chip on the Dashboard feed for the exact
  same underlying record.**
- **Naming collision, not a color bug but worth flagging**: "Pending" exists
  as both a service-request status (rendered via `.status-pill`/`warning`
  tint) and a borrowing status (rendered via the hardcoded-hex chip,
  `#B45309`/white). Two different domains, two entirely different visual
  systems, coincidentally the same English word — an operator who has
  learned one "Pending" look will not recognize the other.

### 3c. Resident / SMS-opt-in status (Active / Pending / Deactivated,
Receiving / Opted out)

- `.status-pill` / `.pill-active` / `.pill-pending` / `.pill-inactive`,
  defined identically in `ResidentDetailPanel.vue:207-232` and
  `UsersView.vue:1151-1188`. Both correctly use the `-strong` tokens
  (`primary-strong`, `warning-strong`) per the accessibility rule documented
  in `plugins/vuetify.ts`. **This pairing is internally consistent** and is
  the second positive baseline in the app, alongside 3b.

### 3d. Staff-account status (Active / Deactivated) — same concept as 3c,
different rendering

- `StaffView.vue:73-84`: plain `v-chip :color="isClosed(item) ? 'error' :
  'success'" variant="outlined"`. This is a **fourth** distinct technique,
  and it represents the same idea as 3c ("is this account allowed to sign
  in") one click away in the sidebar (`AppSidebar.vue:146-147` lists
  "Residents" directly above "Staff Accounts"), yet the two use unrelated
  visual languages: Residents' Active/Deactivated is a filled, tinted,
  custom pill; Staff's Active/Deactivated is a stock outlined Vuetify chip.

### 3e. Equipment stock state (Available / Low stock / Depleted)

- `EquipmentInventoryView.vue:423-437`, `.state-pill`/`.pill-available`/
  `.pill-low`/`.pill-depleted`. **Accessibility regression**: unlike every
  pill system in 3a/3c, this one uses the *raw* theme tokens as text color —
  `color: rgb(var(--v-theme-primary))` / `--v-theme-warning` /
  `--v-theme-error` directly on their own light tints — **not** the
  `-strong` variants `plugins/vuetify.ts` was written specifically to
  provide for this exact situation ("For text drawn on a tint of primary
  rather than on the surface," `plugins/vuetify.ts:26-28`). This is the
  identical contrast failure the `-strong` tokens exist to fix elsewhere in
  the app (documented as previously measured at 4.25:1/2.36:1, failing WCAG
  AA), reintroduced here.

### 3f. Vehicle status (Available / Dispatched / Maintenance)

- `VehiclesView.vue:544-563`. **Third independent `.status-pill` class
  definition in the codebase** (after `ServiceRequestQueue.vue` and
  `ResidentDetailPanel.vue`/`UsersView.vue`) — same class name, unrelated CSS,
  scoped separately so there's no runtime collision, but three different
  things now answer to the same name in three different files. Same
  accessibility regression as 3e: `color: rgb(var(--v-theme-primary))` /
  `--warning` / `--error` directly on their own tints, no `-strong` token
  (`VehiclesView.vue:561-563`). Also the *third* reuse of the word
  "Available" in the app (equipment stock, vehicle status, and — loosely —
  service-request availability language), each with its own unrelated
  rendering.

### 3g. Service category (Rescue / Medical / Relief / Infrastructure)

- `ServicesConfigView.vue:539-553`, `.category-pill`/`.pill-rescue` etc. —
  **same accessibility regression a third time**: `color:
  rgb(var(--v-theme-error))` / `--info` / `--primary` / `--warning` directly
  on the pill's own tint, no `-strong` token.
- In the same table row, the **"Inactive" service badge** is a completely
  separate, fourth chip technique on the same page:
  `ServicesConfigView.vue:142`, plain `v-chip size="x-small" variant="flat"
  color="error"` — full-saturation flat red, sitting directly beside the
  soft-tinted `.category-pill` in the same cell. (`docs/ui-audit/findings.md`
  finding #10 already flagged the resulting case-convention mismatch between
  these two badges from a screenshot pass; this audit corroborates the same
  row from source and adds that they are also two structurally different
  chip *implementations*, not just two casing conventions.)

### 3h. Activity-log action / SMS delivery status (Created/Updated/Deleted/
Login; Sent/Unconfirmed/Failed)

- `LogsView.vue:57-61` and `84-97`: plain `v-chip :color="…"
  variant="tonal"`, but the color values are **raw Vuetify/Material color
  names** (`'green'`, `'blue'`, `'red'`, `'purple'`, `'grey'`,
  `'amber-darken-2'`), not the app's semantic tokens
  (`success`/`info`/`error`/`warning`). This is functionally a **sixth**
  chip technique and the only one in the app that does not draw from the
  brand palette in `plugins/vuetify.ts` at all — every other "success" green
  in the app is the brand's `#297A67`; `LogsView`'s "Created" chip is stock
  Material Design green (`#4CAF50`-family), a visibly different hue sitting
  under the same nav item group as everything else.

### 3i. File type badge

- `FilesView.vue:188-195`, a `<span class="type-badge">` with a
  `:style`-computed `rgba(var(--v-theme-…), 0.14)` background — not a
  `v-chip`, not the `.status-pill` class family, its own one-off markup.
  Padding (`1px 8px`, `FilesView.vue:619-627`) is noticeably tighter than
  every `.status-pill`'s `5px 12px` / `.category-pill`'s `4px 12px`, so even
  where the color logic happens to be correct (it draws from real
  `-strong`-free base tokens, same regression class as 3e/3f/3g, though file
  extensions are short enough that the contrast gap is less likely to be
  noticed at a glance) the pill's proportions read as a different, smaller
  control next to everything else on the page.

### Summary — distinct chip/pill implementations found

| # | Technique | Views |
|---|---|---|
| 1 | `.status-pill`/`.pill-*` custom class, `-strong` tokens, shared via composable | ServiceRequestQueue, ConductionRequestView |
| 2 | `.status-pill`/`.pill-active/pending/inactive`, `-strong` tokens, shared | ResidentDetailPanel, UsersView |
| 3 | Hardcoded hex + white text + icon, shared table | EquipmentBorrowingView, ProcurementReferenceView |
| 4 | `v-chip color=token variant="tonal"` | DashboardView (activity feed) |
| 5 | `v-chip color="success"/"error" variant="outlined"` | StaffView |
| 6 | `v-chip color="green"/"red"/… variant="tonal"` (raw color names) | LogsView (both tables) |
| 7 | `.state-pill`/`.pill-available/low/depleted`, raw tokens (AA regression) | EquipmentInventoryView |
| 8 | `.status-pill`/`.pill-available/dispatched/maintenance`, raw tokens (AA regression) | VehiclesView |
| 9 | `.category-pill`/`.pill-rescue/…`, raw tokens (AA regression) | ServicesConfigView |
| 10 | `v-chip size="x-small" variant="flat" color="error"` | ServicesConfigView ("Inactive" badge, same row as #9) |
| 11 | One-off `<span class="type-badge">`, inline computed style | FilesView |

---

## 4. Tables

| View | Headers (file:line) | Column widths | Header style | Row height / density | Overflow handling |
|---|---|---|---|---|---|
| `ProcurementReferenceView.vue:227-234` | `#`(64px), Item requested(–), Qty(90px), Requested by(–), Date filed(150px), Status(170px) | mixed px / unset | default Vuetify | `density="comfortable"` | `.cell-truncate` (max-width 340px) on the *purpose* sub-line only — **the item-name itself has no width cap and no truncation**, see §Layout-shift below |
| `EquipmentInventoryView.vue:254-261` | `#`(64px), Item(30%), Available(19%), Status(17%), In use(14%), actions(14%) | % + px, **no `table-layout:fixed`** | `.inventory-table :deep(thead th)` — uppercase, 0.72rem, no bg override | `density="comfortable"` | `text-truncate` on item name only; relies on the % width holding, which it cannot without `table-layout:fixed` |
| `VehiclesView.vue:330-336` | `#`(64px), Unit(32%), Type(21%), Status(23%), actions(19%) | % + px, **no `table-layout:fixed`** | `.fleet-table :deep(thead th)` — same uppercase/0.72rem recipe as Equipment Inventory (copy-pasted) | `density="comfortable"` | `text-truncate` on unit name + spec; same missing-fixed-layout gap |
| `StaffView.vue:307-312` | `#`(64px), Name(unset), Status(160px), actions(220px) | mixed, **no `table-layout:fixed`**, and `class="elegant-table"` **matches no CSS rule anywhere in this file** (see below) | default Vuetify (no header bg/typography override at all) | **no `density` prop** — default (tallest) row height, unlike every other table in the app | `text-truncate` on name/email |
| `ServicesConfigView.vue:286-303` | `#`(64px), Service(min 260px), Description(min 280px), Category(170px), Date added(150px), Actions(230px) | `minWidth` used instead of `width` for the two flexible columns — a third convention, distinct from everyone else's plain `width` | `.services-table :deep(th)` — bespoke 0.9rem/700, `background: rgba(on-surface,0.04)` header tint (unique to this table) | explicit `:deep(td){height:68px!important}` — a fourth distinct row-height value | `.description-cell` uses `-webkit-line-clamp:2` + `max-width:460px` (wraps to 2 lines, does **not** ellipsize on one line like every other table's truncation) |
| `FilesView.vue:367-375` | `#`(64px), File(32%), Type(12%), Size(9%), Uploaded(13%), Verified(13%), actions(21%) | %, **no `table-layout:fixed`** | `.materials-table :deep(thead th)` — same 0.72rem uppercase recipe as Equipment/Vehicles | `density="comfortable"` | `text-truncate` on title only |
| `LogsView.vue:156-176` (`v-data-table-server` ×2) | System: `#`(64px), Date(19%), User(19%), Module(14%), Action(14%), Description(28%). SMS: `#`(64px), Date(17%), Sender(15%), Barangay(13%), Message(31%), Recipients(10%), Status(9%) | %, **no `table-layout:fixed`** | default Vuetify, no header override at all | **no `density` prop** — default row height, same gap as StaffView | **no truncation of any kind** on Description or Message Content — the two widest, most operator-typed-length columns in the whole app have neither a width guarantee nor an ellipsis fallback |
| `UsersView.vue:517-528` | `#`(64px), photo(76px), Full Name(17%), Barangay(14%), Phone(10%), Email(14%), Status(160px), SMS(142px) | %+px, budget explicitly documented and summed in a code comment (`UsersView.vue:502-516`) | **`background-color:#0f4c3a !important`** (brand green, white text) — the only table in the app with a colored header row | explicit `:deep(td){height:76px!important}` — yet another distinct value | `.cell-truncate` (name, email) + `v-tooltip` showing the full value on hover — the only table that pairs truncation with a tooltip fallback; `table-layout:fixed; min-width:1000px` is set and explicitly commented on |
| `EquipmentBorrowingView.vue:849-866` (2 tables) | Active: `#`(64px), avatar(60px), Head of the Family(20%), Barangay(12%), Equipment(22%), Status(13%), Timeline(13%), actions(11%). History: `#`(64px), Head of the Family(21%), Barangay(15%), Equipment(24%), Requested(17%), Outcome(16%) | % + px | `.borrow-table :deep(thead th)` — 0.72rem uppercase recipe (same family as Equipment/Vehicles/Files) | `density="comfortable"` | `.cell-truncate` + `v-tooltip` (matches UsersView's approach) on resident/equipment/purpose; `table-layout:fixed; min-width:784px` set, but **`.borrow-table :deep(td){white-space:nowrap}` is applied to every cell**, including ones with no `.cell-truncate`/ellipsis of their own (e.g. Timeline) — those cells can only overflow, never wrap or clip, if content runs long |
| `ConductionRequestView.vue:672-678` | `#`(64px), Patient(24%), Status(17%), From → To(28%), Filed(17%) | % | same 0.72rem uppercase recipe | `density="comfortable"` | `.cell-truncate` on patient name/contact and the route line; `table-layout:fixed; min-width:704px` set |

**`class="elegant-table"` divergence** — `StaffView.vue:52` and
`UsersView.vue:149` both apply this class name to their `v-data-table`, and
the name (plus the shared `.avatar-initials`/`.you-chip` styling
conventions nearby) strongly suggests Staff's table was copy-pasted from
Residents'. But `UsersView.vue:1088-1149` defines eleven `.elegant-table
:deep(...)` rules — the brand-green header, the 76px row height, the
`table-layout:fixed` + `min-width:1000px` floor, the themed scrollbar, the
selected-row treatment — and **none of them exist in `StaffView.vue`'s style
block**. Because Vue's `<style scoped>` only applies rules a component
actually defines, `StaffView`'s "elegant table" renders as a completely
unstyled default Vuetify table: grey/white header, default (tallest) row
height, no `table-layout:fixed`. Two tables share a class name that implies
one shared look; only one of them has the CSS.

**Header-row background** is otherwise inconsistent for its own sake:
plain/default (Equipment Inventory, Vehicles, Files, Staff, Procurement,
Logs, Equipment Borrowing, Conduction), a light on-surface tint
(`ServicesConfigView.vue:490`), and solid brand green with white text
(`UsersView.vue:1116`) — three different visual weights for "this is a
table header," in three different views.

---

## 5. Detail rails (split-pane views + ResidentDetailPanel)

| Surface | Field label style | Section-heading style | Inter-field / inter-section spacing |
|---|---|---|---|
| `ServiceRequestQueue.vue:365-424` (ambulance/resident-request detail pane) | inline `text-caption text-uppercase font-weight-bold text-medium-emphasis` per field, no wrapping section card | *(none — fields are grouped by `v-row`, not under a heading)* | `.detail-group{margin-bottom:28px}` (`ServiceRequestQueue.vue:2553`), a documented "label sits 8px from the thing it labels; a group sits 28px from the next one" rhythm; `.detail-rule{margin:40px 0}` for the one break before the action row |
| `ResidentDetailPanel.vue:44-88` | identical inline recipe: `text-caption text-uppercase font-weight-bold text-medium-emphasis mb-1` | `text-subtitle-2 font-weight-bold text-medium-emphasis text-uppercase mb-4` (grey/medium-emphasis heading — "Contact", "Registration") | flat `mb-4` per field block, `mt-6` before a new section heading — a *different*, smaller numeric rhythm than ServiceRequestQueue's documented 8/28/40 scale, arrived at independently |
| `EquipmentBorrowingView.vue:420-535` (detail dialog, not a rail, but the same "read a record" role) | same inline recipe again: `text-caption text-uppercase font-weight-bold text-medium-emphasis` | **`text-subtitle-1 font-weight-bold mb-4 text-high-emphasis text-uppercase`** — one type size larger than ResidentDetailPanel's, and **high-emphasis (near-black/white) instead of medium-emphasis (grey)** — a visibly heavier, darker heading for the same "section label" role | `mb-3`/`mb-6` mixed ad hoc per block, no documented scale |
| `ConductionRequestView.vue:452-485` (detail dialog) | **hand-rolled `.field-label`/`.field-value` classes** (`ConductionRequestView.vue:1217-1229`): `font-size:0.7rem; font-weight:700; color:rgba(on-surface,0.6)` — visually close to but not the same declaration as the `text-caption`/`text-medium-emphasis` utilities used everywhere else (0.75rem vs. 0.7rem; a hardcoded 0.6 alpha vs. Vuetify's own medium-emphasis token) | **`.section-title`** (`ConductionRequestView.vue:1207-1215`): `font-size:0.78rem; font-weight:800; color: rgb(var(--v-theme-primary-strong))` — the section heading is rendered in the **brand green**, unlike either of the other two treatments above (grey in ResidentDetailPanel, near-black in EquipmentBorrowingView) | `margin:20px 0 10px` on `.section-title`, `margin-bottom:8px` on `.field-value` — a third, independently-invented numeric scale |
| `UsersView.vue` (hosts `ResidentDetailPanel` in an animated rail, `.detail-rail`/`.detail-rail--open`, `UsersView.vue:996-1035`) | *(delegates to ResidentDetailPanel — no divergence here)* | *(delegates)* | rail itself: fixed `460px` inner width + `16px` gutter, `280ms` width transition — this part is specific to the rail mechanism, not the field styling, and is not in question per the task brief |

**Net**: the "field label" role has one *near*-identical inline-utility
version (ServiceRequestQueue, ResidentDetailPanel, EquipmentBorrowingView —
all reading from Vuetify's `text-medium-emphasis` token) and one **separate,
hardcoded** version (ConductionRequestView's `.field-label`, `0.6` alpha typed
directly rather than reading the emphasis token). The "section heading" role
has **three unrelated treatments** — grey/medium (ResidentDetailPanel),
black/high-emphasis (EquipmentBorrowingView), brand-green (ConductionRequestView)
— for what is, functionally, the same "this group of fields is about X" job
in three different dialogs/panels a staff member moves between constantly.

---

## 6. Layout-shift problems (highest priority)

Grepped `min-width`, `max-width`, `white-space`, `text-overflow`, `overflow`,
`flex:`, every `headers` array, and every `v-chip`/`.status-pill` usage
across `src/views` and `src/components`, then read the surrounding markup
for each hit. Listed below only the ones that are real risks — a long
resident name, barangay, equipment name, remarks/notes, or log description
can visibly break the row. Confirmed-safe patterns (e.g.
`ServiceRequestQueue.vue`'s row list, which pairs `min-width-0` with
`text-truncate` on every flex child — `ServiceRequestQueue.vue:247-268,
623-640, 687-704`) are not repeated here.

### High-confidence, systemic: `table-layout: fixed` is missing from 7 of the 10 data tables

Vuetify's `v-data-table` does **not** set `table-layout: fixed` on its own.
Three views learned this the hard way and say so directly in their own
comments:

- `ConductionRequestView.vue:1316` + surrounding comment: *"without it,
  `width: 100%` on a fixed table lets a narrow wrapper crush every column
  instead of scrolling."*
- `EquipmentBorrowingView.vue:1606-1612`: *"'waiting' wraps to one letter per
  line rather than the table scrolling sideways"* without this rule.
- `UsersView.vue:1080-1092`: *"measured at 430px the six data columns
  collapsed to 1px each and only the avatars rendered"* without it.

All three fixed it (`ConductionRequestView.vue:1316`,
`EquipmentBorrowingView.vue:1612`, `UsersView.vue:1089-1091`) with an
explicit `table-layout:fixed !important` + a measured `min-width` floor.
**That fix was never carried to the other seven `v-data-table`s that also
declare percentage/px column widths in their `headers` array but have no
`table-layout` rule anywhere in their `<style>` block:**

- `EquipmentInventoryView.vue:254-261` (headers) / no `table-layout` rule in file
- `VehiclesView.vue:330-336` / no rule
- `StaffView.vue:307-312` / no rule (compounded by the missing `.elegant-table` CSS, §4)
- `ServicesConfigView.vue:286-303` / no rule
- `FilesView.vue:367-375` / no rule
- `LogsView.vue:156-176` (both tables) / no rule
- `ProcurementReferenceView.vue:227-234` / no rule

**Failure mode**: without `table-layout:fixed`, a browser's default
`auto` table layout sizes each column to fit its *content*, treating the
declared `width`/`minWidth` as a hint it can override. A `text-truncate` /
`cell-truncate` span inside such a column will not visibly ellipsize —
the column just grows to accommodate the long value instead, which is
exactly the "one letter per line" / "everything collapsed to 1px" failure
the three fixed views' own comments describe hitting. Concretely: a resident
whose barangay name is unusually long in `EquipmentInventoryView`'s Item
column, a long unit spec in `VehiclesView`, a long admin email in
`StaffView`, a long service description in `ServicesConfigView` (partially
mitigated there — see next item), a long filename in `FilesView`, or a long
log description/SMS message body in `LogsView` can each expand their column
and misalign every row below the fold, or — per the documented failure mode
— crush sibling columns to unreadable widths on a narrower viewport.

### Compounding: two columns have no truncation guard at all, on top of the missing `table-layout:fixed`

- **`LogsView.vue:162` (`Description`, 28% width) and `LogsView.vue:173`
  (`Message Content`, 31% width)** — rendered as plain interpolated text
  with **no `cell-truncate`, no `text-truncate`, no line-clamp, nothing**.
  These are also the two widest columns in the table and the two whose
  content (a free-text audit description, an SMS body) is written by staff
  and can run to any length. Combined with the missing `table-layout:fixed`
  above, this is the single highest-risk table in the app for layout shift.
- **`ProcurementReferenceView.vue:104-107`** — the item-name cell
  (`item.other_equipment_text`, a resident-typed free-text field with no
  length limit visible in this view) has **no width and no truncation** at
  all; only its `purpose` sub-line gets `.cell-truncate` (`max-width:340px`,
  `ProcurementReferenceView.vue:263-268`). A long resident-typed equipment
  name — e.g. "collapsible aluminum stretcher with wheeled base and rain
  cover, size large" — has nothing capping it and no `table-layout:fixed` on
  this table either (confirmed absent from its `<style>` block), so it will
  push the Qty/Date filed/Status columns without limit.

### `white-space: nowrap` applied to every cell in one table, not just the truncated ones

- **`EquipmentBorrowingView.vue:1625`**: `.borrow-table :deep(td) {
  white-space: nowrap; }` is a blanket rule on **every** cell in both
  Active-pipeline and History tables. Only specific spans inside specific
  cells (resident name, barangay, equipment name/purpose) additionally carry
  `.cell-truncate` (`overflow:hidden; text-overflow:ellipsis`). Any content
  in a cell that does **not** have `.cell-truncate` — e.g. the Timeline
  column's `dueLabel`/`agingLabel` text
  (`EquipmentBorrowingView.vue:267-272`) — is forced to a single line with
  no wrap **and no clip**, so an unusually long computed timeline string
  (the aging label is built from a resident's `created_at` plus a
  human-readable duration; not bounded in code) would overflow its cell
  visually into the neighboring Actions column rather than either wrapping
  or ellipsizing.

### Filter/status controls sized to their own label, no cap

- **`FilesView.vue:188-195` / `619-627`** — `.type-badge`'s width is
  whatever `(item.file_type || 'file').toUpperCase()` happens to measure;
  extensions are short today (PDF/JPG/PNG/DOCX/ZIP) so this is low-risk in
  practice, but there is no `max-width`/ellipsis fallback if a longer
  extension (e.g. a future `.pptx`) is ever accepted — flagged for
  completeness, not urgency.
- **`VehiclesView.vue:135-146`** — the status-change trigger button
  (`.status-pill`, `min-width:148px`, comment: *"Sized to its own label in a
  table cell"*) is explicitly documented as content-sized rather than
  column-sized; this is safe today only because all three vehicle statuses
  ("Available"/"Dispatched"/"Maintenance") are short, known, fixed strings —
  it is not defensive against a longer value the way `ServiceRequestQueue`'s
  `status-pill--sm` (fixed padding, `white-space:nowrap`, but sitting inside
  a `flex-shrink-0` sibling with a `min-width-0` neighbor,
  `ServiceRequestQueue.vue:264-269`) is.

### Confirmed safe (checked, not a risk — included so the exhaustive pass is verifiably exhaustive)

- `ServiceRequestQueue.vue`'s row list and detail-panel dispatch-state block:
  every flex text child pairs `min-width-0`/`.min-width-0` with
  `text-truncate` (`ServiceRequestQueue.vue:247, 623-640, 687-704`).
- `DashboardView.vue`'s KPI tile labels use a 3-line `-webkit-line-clamp`
  instead of truncating, deliberately (`DashboardView.vue:723-736`, comment
  explains why: "Pending Ambulance Requests" doesn't fit one line at any
  tile width, so the tile grows instead of clipping the one card that only
  appears when it needs acting on) — a genuine, considered design choice,
  not an oversight.
- `DashboardView.vue`'s barangay-ranking rows pair `min-width-0`/`flex-grow-1`
  with `text-truncate` correctly (`DashboardView.vue:203-207`).
- `SmsView.vue`'s credit-balance block explicitly hardens against wrap with
  `white-space:nowrap` on each line and a non-shrinking flex container
  (`SmsView.vue:27-39`, comment explains the exact failure mode it
  prevents).
- `ServicesConfigView.vue`'s `.description-cell` uses an explicit
  `max-width:460px` **on the cell content itself**, not just a column-width
  hint — this one degrades gracefully (2-line clamp) even without
  `table-layout:fixed` on that table, unlike the seven tables flagged above
  whose truncation relies entirely on the column obeying its declared width.

---

## 7. What a consistent version would look like, and what it costs

### Page header

**Consistent version**: one `PageHeader.vue` (or a documented block of
utility classes, see cost note) with `title`/`subtitle`/`actions` slots:
`text-h5 font-weight-bold text-high-emphasis` title (the smaller of the two
sizes currently in use — `text-h4` reads oversized next to the panel's other
type, and `text-h5` is already what 5 of 14 surfaces use), `text-body-2
text-medium-emphasis` subtitle, `mb-4` below the block, action slot
right-aligned. Drop `.tracking-tight` and `font-weight-black` entirely
rather than deciding which views keep them.

**Cost**: touches all 13 data-bearing views (12 table views +
`ServiceRequestQueue`'s standalone header) — a new shared component, not a
utility class, since the current per-view "action button vs. filter vs.
nothing" slot content varies enough that a plain CSS class can't carry it.
`FilesView` and `UsersView` are the awkward cases: both currently embed the
header *inside* a wrapping card/toolbar rather than as a bare block, so
adopting the component means either restructuring those two wrappers or
teaching the component to render inside one. `SmsView` and `LoginView`
are reasonably excluded (non-list pages with their own header role) but
should be a deliberate decision, not a silent omission, when this ships.

### Status chips

**Consistent version**: one `StatusPill.vue` (props: `label`, `tone` —
one of the theme's five semantic tones plus a `neutral` for
cancelled/withdrawn states — and an optional `icon`), always rendering
`-strong`-token text on a themed tint, `.status-pill`'s existing box model
(`5px 12px`, `8px` radius, `0.75rem`/`700`/uppercase). Every status
vocabulary in §3 maps its own words to one of the five tones once, in one
place (ideally extending `composables/adminUi.ts`'s existing
`statusPillClass`/`sharedStatusLabel` pattern rather than reinventing it —
that file already proves the shape works, for service-request and trip
statuses). "Booked" gets a real semantic token (or a documented,
intentional exception) instead of a hardcoded violet nothing else in the
palette uses.

**Cost**: the largest single piece of work in this list — 11 touched views
across all three sections of the app (Dashboard, Ambulance Dispatch,
Equipment Borrowing, Procurement, Vehicles, Resource Management, Manage
Services, Staff Accounts, Residents, Activity Logs, Documents). It is a new
shared component plus a data migration (each status → tone mapping) rather
than a utility class, because the current renderings differ in more than
CSS — some are `v-chip`, some are hand-rolled `<span>`s, some carry an icon
and some don't. `LogsView`'s raw-Material-color chips and
`EquipmentBorrowingView`/`ProcurementReferenceView`'s hardcoded-hex chips
are the two hardest migrations, since both currently encode information
(6+ distinct hues on the borrowing pipeline) that doesn't cleanly fold into
five semantic tones without either compressing some distinctions or growing
the tone palette — a real design decision, not just a refactor, and one this
Phase 1 report deliberately leaves open rather than pre-deciding.

### Table column widths

**Consistent version**: every `v-data-table` gets `table-layout:fixed` +
an explicit `min-width` floor (the fix already proven three times over, in
`ConductionRequestView.vue:1316`, `EquipmentBorrowingView.vue:1612`,
`UsersView.vue:1089-1091`), plus every free-text column gets a real
truncation guard — either the existing `.cell-truncate` class (already
proven, paired with a `v-tooltip` for the full value where the value is
important enough to need one, as `UsersView`/`EquipmentBorrowingView`
already do) or `ServicesConfigView`'s content-level `max-width` + line-clamp
approach for columns where wrapping to 2 lines beats a single truncated
line.

**Cost**: a CSS-only utility fix (a shared `.data-table-fixed` class plus a
per-table `min-width` value, no new component needed) touching 7 tables:
`EquipmentInventoryView`, `VehiclesView`, `StaffView`, `ServicesConfigView`,
`FilesView`, `LogsView` (×2 tables), `ProcurementReferenceView`. Cheapest
item on this list to apply and highest-value, since it's the one category
with a demonstrated, reproducible failure mode already described three
times in this codebase's own comments. `StaffView` additionally needs its
dead `class="elegant-table"` either wired to real CSS (matching `UsersView`)
or renamed to stop implying a shared look that isn't there.

### Filter / counter row

**Consistent version**: two blessed patterns, not one — the task brief is
right that the two *layout* patterns (split-pane vs. full-width table)
should stay distinct, and the same logic extends to the two filter idioms
that already dominate: a mandatory `v-chip-group` with inline counts for
list-of-statuses filtering (already `ServiceRequestQueue`'s pattern, reuse
as-is), and `v-select`/`v-text-field` plus a `FilterChipRow.vue` for the
"active filters, closable, clear-all" summary (already near-identically
implemented twice — `ServicesConfigView.vue:61-84` and
`EquipmentBorrowingView.vue:80-104` — extracting a shared component here is
mostly moving code that already agrees with itself). The clickable
"stat-tile" idiom (Vehicles, Equipment Borrowing) is a third legitimate
pattern for count-driven filtering and is worth keeping, but its two current
implementations should converge on one CSS shape (row layout, `color-mix()`
active state — Equipment Borrowing's version — since it's the more
maintainable of the two).

**Cost**: low-to-medium. The chip-group and stat-tile idioms need no new
component, just consolidating two near-duplicate CSS blocks into one
(`EquipmentBorrowingView.vue:1575-1604` + `VehiclesView.vue:498-517` → one
`.stat-tile` definition). The closable-filter-chip row is worth a small
shared component (`FilterChipRow.vue`) touching 2 views today
(`ServicesConfigView`, `EquipmentBorrowingView`) with room for
`VehiclesView`'s single-chip case to adopt it as a third. `UsersView`'s
underlined-tab barangay filter and `FilesView`'s scrolling-chip type filter
are each single-use today; folding them into one of the blessed patterns
(most naturally the `v-chip-group` idiom, since both are "pick one of a
short list of named options") is a real UX call for whoever owns Phase 2,
not just a mechanical rename.
