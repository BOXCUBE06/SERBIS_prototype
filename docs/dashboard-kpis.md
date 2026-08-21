# Dashboard KPI cards

**Date:** 2026-08-21
**Scope:** `AnalyticsController::index()` → `kpiStats`, rendered by `Web/serbis-admin-vue/src/views/DashboardView.vue`

The strip is built server-side. The panel renders whatever cards the response
contains and lays them out with `repeat(N, 1fr)`, so adding or removing one is a
change to this controller alone.

| Card | Source |
|---|---|
| Total Residents | `Resident::count()` |
| Pending Service Requests | `tbl_service_request.status = 'Pending'` |
| Pending Borrow Requests | `tbl_equipment_borrowing.status = 'Pending'` |
| Available Vehicles | `tbl_vehicles.status = 'Available'` |
| Pending Ambulance Requests | `tbl_conduction_requests.departed_office_at IS NULL` — **conditional, see below** |

Dropped on 2026-08-21, on adviser feedback: *Borrowed Equipment*
(`total_quantity − available_quantity`) and *Overdue Returns* (released
borrowings past `due_date`). Both are still reachable from Equipment Borrowing;
neither belonged in a four-card headline.

## Known limitation — "Pending Ambulance Requests" never falls

`tbl_conduction_requests` **has no status column.** The MDRRMO conduction form
is filed by staff, and its only lifecycle is the trip log — four nullable
timestamps written after dispatch. `ConductionRequest::getTripStatusAttribute()`
derives *Not dispatched → In transit → Completed* from those, and nothing else.

So "pending" here can only mean `departed_office_at IS NULL`, and that has one
consequence worth stating plainly:

> **There is no cancel or close path on a conduction request.** A trip that was
> filed and then called off, duplicated, or handled off-system is never
> dispatched, so it stays in the pending count indefinitely. The number is a
> count of undispatched forms, not a queue of live work.

The card is therefore only sent when the count is above zero
(`AnalyticsController.php`) — a permanent non-zero badge that no action can
clear would train staff to ignore it, whereas an absent card at least means the
office has dispatched everything it filed.

**Fixing this properly** needs a real status column on
`tbl_conduction_requests` (`Pending / Dispatched / Cancelled / Completed`), a
migration, a cancel action in `ConductionRequestController`, and a control in
`ConductionRequestView.vue`. Deliberately **not** built in this pass — it is a
schema change, not a dashboard change.
