# SERBIS admin panel — UI/UX audit inventory

Built by reading `Web/serbis-admin-vue/src` (views, components, composables), not by clicking through the app. Confirms route → page-title → component mapping, then lists every interactive surface per route: dialogs, tables, filters, forms + validation, charts, uploads, and each list's empty/loading/error state. Screenshots (Step 1) land in `docs/ui-audit/screenshots/`, named `<slug>--<viewport>--<theme>.png`; slugs below match those filenames.

**Reconciliation: 64 files in `docs/ui-audit/screenshots/` = 16 surfaces × 2 viewports × 2 themes.** The 16 surfaces (each has a `##` heading below, except the notification menu which is a bullet under Dashboard since it isn't a route): `dashboard`, `dashboard-notification-menu`, `resident-requests`, `ambulance-dispatch`, `equipment-borrowing`, `vehicles`, `resource-management`, `text-blast-sms`, `manage-services`, `residents`, `staff-accounts`, `documents`, `activity-logs`, `login`, `404`, `logout-confirm-dialog`. Spot-checked four of the table-heavy ones (Resident Requests, Residents, Activity Logs, Vehicles) at 1920×1080 light — all show real seeded data (15/22/many/14 rows respectively), not skeleton loaders or empty tables.

Two items on the requested surface list don't exist as named — flagged inline rather than silently matched to something else:

- **Forgot/reset password** — not built. `LoginView.vue` has no such link or route; a code comment states the intent directly: "a forgotten password is a DB operation, not a self-serve flow." Nothing to capture.
- **Profile/account settings** — no dedicated page. The sidebar's profile card (`AppSidebar.vue`) opens a **Confirm Logout** dialog on click; that's the only thing behind it. Captured as `logout-confirm-dialog--*` instead of a settings screen.

Also worth flagging up front: **admin MFA is currently disabled server-side** (`AuthController::adminLogin`, comment dated 2026-08-30 — TOTP code is still in the frontend and backend, just unused). `CLAUDE.md`'s Architecture section still describes MFA as required; that line is stale against current code.

---

## Dashboard (`/`, slug `dashboard`)

`DashboardView.vue`.

- **Notification menu** (slug `dashboard-notification-menu`) — `v-menu` off a bell icon (`aria-label="System notifications"`), badge dot when logs exist, lists up to 5 recent `tbl_system_logs` rows, "No recent logs" text when empty. Not a route.
- Static avatar ("J") next to the bell — no click handler, no menu. Dead-looking affordance, confirmed in code (no `@click`, no `v-menu` wrapping it).
- KPI strip: 5 stat tiles (Total Residents, Pending Service Requests, Pending Borrow Requests, Available Vehicles, Pending Ambulance Requests) — the last three render as `v-btn` (clickable, presumably deep-link), the first as plain text.
- Hero card: headline total + sparkline (`Line`, Chart.js via vue-chartjs), period toggle (Today/Week/Month).
- Trend card: bar chart (`Line` component despite the label "Request Trend" — check whether that's intentional), same period toggle, independent from the hero card's toggle.
- **Map card** ("Requests by Barangay"): Leaflet (`leaflet` + `leaflet/dist/leaflet.css`), fitBounds to barangay polygons, own period toggle (Today/Week/Month/All), external tile attribution footer.
- **Top zones card** ("Barangays with Most Requests"): ranked list with percentage bars, own period toggle, empty state "No zone activity yet".
- **Activity feed card**: tabs (All/Services/Borrowing), own period toggle (Today/Week/Month — no "All"), list of recent events with status chips.
- **Request Volume card** ("Most Requested"): `Bar` chart with a custom `afterDatasetsDraw` plugin drawing value labels on every bar; toggle between Services/Items; empty state "No data yet".
- Loading: `chartDataRaw` starts `null` — every chart/list above is conditionally rendered on it, so the whole dashboard below the KPI strip is either fully there or fully absent. No skeleton loader for this route (unlike every list-based route below).
- Error: `console.error('Failed to load dashboard:', error)` only — **no user-visible error state** on this route.

## Resident Requests (`/manage-requests`, slug `resident-requests`)

`ManageRequestView.vue` renders `ServiceRequestQueue.vue` with `scope="other"` — every service request that isn't ambulance/medical. This component (2826 lines) is shared with the Bookings tab of Ambulance Dispatch below, so its surfaces are described once here and referenced there.

- Search bar ("Search by name, service, barangay...") + status filter `v-chip-group` (mandatory selection).
- List is card-rows, not a `v-data-table` — pagination underneath.
- **Create dialog** (`createDialog`) — walk-in service request form; resident search-by-name autocomplete or manual name entry, service/barangay `v-select`s, per-service dynamic fields (the "one form implementation for road, relief and generic" from recent commit history), inline `v-alert` for submit errors, `:rules="[required]"` validation.
- **Reason dialog** (`reasonDialog`) — shared Approve/Disapprove confirmation, title and body driven by `reasonCopy`, confirm button colored/labelled per action.
- **Resolve dialog** (`resolveDialog`) — "closes it permanently" confirmation.
- **Reschedule dialog** (`rescheduleDialog`).
- **Vehicle/unit picker dialog** (`vehicleModal`) — "Units Free for This Window" (booked) vs "Available Vehicles" (unbooked); list of selectable units with icon-by-type.
- **Day View dialog** (`dayView`) — a custom horizontal timeline/Gantt-style widget per vehicle unit, not a calendar library; own loading skeleton.
- **Attachment lightbox** (`lightbox`) — full-size image viewer for uploaded ID/site photos.
- Row detail panel (inline, not the ResidentDetailPanel drawer — that's Residents-only): shows attachments (valid ID, site photo) with per-attachment loading skeleton and a named error message ("Could not load the attached ID." / "Could not load the landmark photo."), staff note field (`:loading="noteSaving"`), Approve/Disapprove/Resolve actions.
- CSV export button (disabled + labelled "Nothing to export" when the filtered list is empty).
- Loading: `v-skeleton-loader` (`list-item-avatar-two-line@6`) on initial load; separate skeletons for the day-view dialog and for individual attachments.
- Empty: `emptyListMessage` (computed, wording not confirmed from screenshots yet).
- Error: single-line `apiError` `v-alert`, not a full-pane error state.

## Ambulance Dispatch Requests (`/conduction-requests`, slug `ambulance-dispatch`)

`ConductionRequestView.vue`. Two tabs, sharing one header/button row that swaps by active tab.

- **Bookings tab** — `ServiceRequestQueue` with `scope="ambulance"` (same surfaces as Resident Requests above). Tab-specific header buttons: "Log Service Request" (opens create dialog), "Ambulance Day View" (opens day-view dialog), "Export" / "Nothing to export" (CSV).
- **Trip Logs tab** — its own `v-data-table`, independent of the queue component:
  - Search ("Patient, origin or destination") + a `v-select` filter.
  - **Create dialog** (`createDialog`, max-width 720) — trip record form.
  - **Detail dialog** (`detail`, max-width 800).
  - **Trip-log/status dialog** (`tripLog`, max-width 600) — has its own inline `v-alert` for `tripLog.error`.
  - `no-data` slot on the table.
  - Loading: `v-skeleton-loader type="table"` on `initialLoad`.
  - **Error: a dedicated full-pane card** ("Could not load ambulance trip records" + the error message) replacing the table — different pattern from every banner-style error elsewhere in the app.
  - A separate `apiError` banner also exists, suppressed while the create/detail dialogs are open.

## Equipment Borrowing (`/borrowings`, slug `equipment-borrowing`)

`EquipmentBorrowingView.vue`. Two tabs: **Board**, **History**.

- 3 `v-select` filters above the tabs (shared filter bar, not per-tab).
- Board tab: `v-data-table` with a `no-data` slot.
- History tab: second, separate `v-data-table` with its own `no-data` slot.
- **Record/detail dialog** (`modal`, max-width 900) — largest dialog in the app by width; shows a resident avatar built from `initials(selectedRecord?.resident)` (text-h4 avatar-initials, not a photo).
- **Action dialog** (`actionDialog`) — approve/reject/return confirmation, title/body from `actionCopy`.
- Loading: `v-skeleton-loader type="table"` on `initialLoad`.
- **Error: dedicated full-pane card** ("Could not load borrowings") — same distinct pattern as Trip Logs, not used elsewhere.
- Separate `apiError` banner for in-dialog action failures.
- Snackbar (`useSnackbar` pattern, bottom-right, 3500ms timeout).

## Vehicles (`/vehicles`, slug `vehicles`)

`VehiclesView.vue`.

- Search ("Search unit or spec...") + status `v-select` filter.
- `v-data-table`.
- **Status dialog** (`statusDialog`) — "Update status".
- **Add/Edit dialog** (`formDialog`) — title swaps "Add unit" / "Edit unit"; `v-select`s for Type and Status (both flagged `*` required, but no visible `:rules` on those two specifically — worth checking against the text-field rules elsewhere in the same form).
- **Delete dialog** (`deleteDialog`) — "Delete unit?".
- Loading: `v-skeleton-loader type="table-row@6"`.
- Error: `apiError` banner only (e.g. `Error updating {unit}: {message}` on a failed status update — reuses the page-level banner rather than a dialog-scoped one, so it can appear behind an open dialog).
- Snackbar present.

## Resource Management (`/inventory`, slug `resource-management`)

`EquipmentInventoryView.vue` (sidebar label "Resource Management", in-page `<h2>` also reads "Resource Management").

- Search ("Search equipment...") + `v-select` filter.
- `v-data-table`.
- **Add/Edit dialog** (`modal`) — title swaps "Add equipment" / "Edit equipment"; Status `v-select` (`Available`/`Unavailable`).
- **Delete dialog** (`deleteDialog`) — "Delete equipment?".
- Loading: `v-skeleton-loader type="table-row@6"`.
- Error: `apiError` banner (page-level) + separate `modal.error` banner inside the add/edit dialog.
- Snackbar present.

## Text Blast (SMS) (`/sms`, slug `text-blast-sms`)

`SmsView.vue`. No table, no dialog — a single form page.

- Target-audience `v-select` (all residents / by barangay, presumably — confirm from screenshot) + a second `v-select`.
- Message `v-textarea`, `counter="160"`, inline rule rejecting >160 chars with the message "Message exceeds the standard 160 SMS character limit."
- Dismissible result `v-alert` (`alert.show`/`alert.type`/`alert.message`) — success or error, closable.
- No `apiError`/`loadError` pattern here; barangay-load failure is reported through `notify(...)`, i.e. a snackbar, not a page alert — a third distinct error-surfacing convention alongside the banner and full-pane-card patterns used elsewhere.
- No visible recipient-count preview confirmed from code alone — check screenshot before claiming absence.

## Manage Services (`/services-config`, slug `manage-services`)

`ServicesConfigView.vue`.

- `v-select` filter, `v-data-table`.
- **Add/Edit dialog** (`modal`).
- Loading: `v-skeleton-loader type="list-item-two-line"` ×6 — a list-shaped skeleton for a table-shaped result, inconsistent with every other table route's `table`/`table-row` skeleton type.
- Error: page-level `apiError` banner + `modal.error` banner inside the dialog.
- Snackbar present.

## Residents (`/users`, slug `residents`)

`UsersView.vue`. Sidebar/heading label "Residents"; this is the view CLAUDE.md notes serializes `tbl_residents`.

- `v-select` filter, `v-data-table` (`@click:row="selectRow"`).
- **Slide-out resident detail drawer** — `ResidentDetailPanel.vue`, opened by row click (`selectedResident`), closed via `closeDetail`. Shows resident photo (`v-img`, fetched lazily per-resident via `residentPhotoUrl`, only when `has_photo` is true — private-disk photo path never touches the client per `CLAUDE.md`'s upload-column rule), "Edit profile" action, "Deactivate account" / "Activate account" toggle (label swaps on current status; Pending and Deactivated both show "Activate account").
- **Edit/create dialog** (`modal`, max-width 680) — Barangay `v-select` with `:rules="[requiredRule('Barangay')]"` and `:error-messages="fieldErrors.barangay_id"`, i.e. server-side field errors surfaced per-field, not just a banner.
- **Delete dialog** (`deleteDialog`) — "Delete this account?".
- Loading: `v-skeleton-loader type="table"`.
- Error: page-level `apiError` banner.
- Snackbar present (4000ms timeout — inconsistent with Vehicles/Inventory's 3500ms).

## Staff Accounts (`/staff`, slug `staff-accounts`)

`StaffView.vue`.

- `v-data-table`, `no-data` slot.
- **Add/Edit dialog** (`modal`, max-width 520).
- **Close-account dialog** (`closeDialog`) — "Close this account?" (staff use "close", Residents use "deactivate" for the same concept — a wording inconsistency worth flagging in Step 3).
- Loading: `v-skeleton-loader type="table" rounded="xl"`.
- Error: page-level `apiError` banner.
- Snackbar present.

## Documents (`/files`, slug `documents`)

`FilesView.vue`.

- Search ("Search materials...") + `v-select` filter.
- `v-data-table`.
- **File upload** — drag-and-drop zone + hidden `<input type=file accept=".pdf,.jpg,.jpeg,.png">`; a staging card (`v-else` branch) replaces the dropzone once a file is picked, presumably showing filename/size before submit — confirm exact staged-preview content from screenshot.
- **Delete dialog** (`deleteDialog`) — "Delete material?".
- Loading: `v-skeleton-loader type="table-row@6"`.
- Error: `apiError` banner (upload-scoped, inside the upload card) — list-fetch failure instead goes through `notify(..., 'error')`, i.e. a snackbar. Same split convention as SMS.
- Snackbar present.

## Activity Logs (`/logs`, slug `activity-logs`)

`LogsView.vue`.

- Search ("Search logs...") + tabs (System / SMS logs).
- **`v-data-table-server`** (×2, one per tab) — the only server-paginated tables in the app; comment notes it needs the total count on every page change.
- No dialogs, no snackbar found.
- No skeleton loader found (grep turned up none) — check screenshot for what a loading `v-data-table-server` shows here (Vuetify's own built-in loading row, most likely).
- **Error: none visible.** `catch (error) { console.error('Failed to fetch logs:', error) }` — a failed fetch here shows nothing to the admin at all. Flag as a gap, not just an inconsistency.

## Login (`/login`, slug `login`)

`LoginView.vue`. Two-step form in one view (password step, then MFA step — MFA currently unreachable per the note above since the server always succeeds on the first step).

- Password step: email + password fields, "Remember me" checkbox. **No "Forgot password?" affordance anywhere in the markup** — confirmed against the rendered screenshot, not just the earlier grep. Correction from an earlier pass of this file: I initially wrote that a dead link existed here; it doesn't. There is no link, no button, nothing — a forgotten password has no self-serve path in the UI at all, matching the code comment's stated intent.
- Inline lockout state: button label swaps to `LOCKED — {n}s` and disables during a countdown (`lockoutSeconds`), driven by a `setInterval` — the only polling timer in the whole app.
- Error alert: `role="alert"`, closable, red-tinted, fixed dark-panel-safe colors (`#ffcdd2` text) rather than theme tokens — because this half of the page is pinned light/dark regardless of the app theme.
- MFA step (code-inert today, still worth capturing since it's shippable code): first-time enrollment shows a QR code (`qrCodeDataUri`) above a 6-box `v-otp-input`; returning admins skip straight to the code prompt. Separate `mfaError` alert, same styling as the password-step error.

## 404 (unmatched route, slug `404`)

`NotFoundView.vue`. Catch-all (`/:pathMatch(.*)*`), reachable only when authenticated (the router guard sends anonymous visitors to `/login` first). Echoes the attempted path as text (`{{ attemptedPath }}`), one button back to the dashboard. No dialogs, no dynamic states — the simplest surface in the app.

## Profile/account settings → logout confirmation (slug `logout-confirm-dialog`)

Covered under Dashboard above structurally (it's global, reachable from every authenticated route via the sidebar), but captured on its own since the brief asked for it by name. `AppSidebar.vue`'s **Confirm Logout** dialog: "Are you sure you want to log out of the SERBIS admin panel?", Cancel / Logout (`:loading="isLoggingOut"`), `persistent` (no backdrop/Escape dismiss).

---

## Cross-cutting notes for Step 3

Not findings yet — just where the inconsistencies already surfaced while inventorying, so Step 3 doesn't have to re-discover them:

- **Three different error-surfacing conventions** coexist: a page-level `apiError` banner (most routes), a full-pane replacement card (Trip Logs, Borrowing), and silent console-only failure (Activity Logs) or snackbar-only (SMS's barangay load, Documents' list fetch).
- **Snackbar timeouts vary**: 3500ms (Vehicles, Inventory) vs 4000ms (Staff, Residents, Services, Documents) with no apparent reason.
- **Wording drift** for the same action: "Deactivate account" (Residents) vs "Close this account?" (Staff).
- **Skeleton-loader type mismatch**: Manage Services uses a list-shaped skeleton for a table result; every sibling table route uses `table`/`table-row`.
- Dashboard has no loading skeleton and no user-visible error state at all, unlike every list route.
- The sidebar's top-right avatar (dashboard) has no behavior — confirmed on screenshot (`dashboard--1920x1080--dark.png`): plain solid-color circle with an initial, no icon styling suggesting affordance, sitting directly beside the notification bell which *is* interactive. Likely to read as broken/unfinished to a user who clicks it.
- No forgot-password affordance exists on the login page at all (confirmed on screenshot) — not a dead link, just absent. Worth surfacing to the client as a scope confirmation, not a bug: is this the intended permanent state, or a deferred feature?

## Coverage check before Step 3

This inventory is grounded in source only — screenshot capture (`e2e/ui-audit.screenshots.spec.ts`) is running separately and will land in `docs/ui-audit/screenshots/`. A few items above are explicitly marked "confirm from screenshot" where the code alone doesn't settle the visual question. Flagging for your confirmation before critique starts, per the brief.
