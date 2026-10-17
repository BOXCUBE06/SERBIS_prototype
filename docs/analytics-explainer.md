# Analytics page explainer

Source: `Web/serbis-admin-vue/src/views/AnalyticsView.vue`, `BarangayDemand.vue`, and backend `AnalyticsController::report`, `App\Support\AnalyticsReport`, `BarangayRequestCounts`, `AnalyticsCache`. Endpoint: `GET /admin/analytics`.

## Read this first

- **Descriptive analytics.** Every number says what already happened (counts, shares, medians). There are no predictions, forecasts or scores.
- **Timezone: Asia/Manila (UTC+8, no daylight saving).** Records are stored in UTC. Day boundaries, weekdays, time-of-day blocks and months are all converted to Manila before counting. Date ranges start at 00:00 Manila and end at 23:59 Manila of the last day.
- **Cache: 5 minutes (300 s) on the server**, one saved copy per combination of date range + barangay + service filters. The copy is thrown away early whenever staff change a request, loan, resident, service, equipment item, vehicle, trip log or barangay, so the next page load shows the new numbers. The browser always re-asks the server on each filter change or page load (it may show its last copy for a moment first).
- **Default range: "This quarter".** Presets: This month / This quarter / This year / All time (starts on the Manila day of the earliest request) / Custom (needs both dates).
- **Filters on the page:** date range, Barangay (multi-select, includes "Walk-in (no barangay)"), Service (multi-select). Which filters reach which card is listed per row below.
- **Terms.** "Request" = a row in `tbl_service_request` (an ambulance booking is a request with status `Booked`). "Closed" = status `Resolved`, `Cancelled` or `Disapproved`. "Open" = any other status (`Pending`, `Booked`, `Responding`). "Walk-in" = a request with no barangay (filed at the counter by staff).
- **Medians, not averages**, so one request left open for a month does not distort the figure. Each median shows how many records it came from.
- Every chart has a matching hidden data table for screen readers, same numbers.

## Every tab: "What stands out" banner and KPI cards

The banner and the four cards under the filter bar change with the tab. They are built in the browser from the same report, nothing extra is queried.

| Item | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| Header line "from to (Asia/Manila) • N requests in range • N walk-in" | The window being shown and how many requests fall in it | count of requests with `created_at` in the window; walk-in = those with no barangay | `tbl_service_request` (`created_at`, `barangay_id`) | date range, barangay, service | "This is exactly which dates and how many requests every figure below is built from." | The "to" date is shown inclusive. |
| What stands out | One or two plain sentences picking the biggest items on that tab | top value ÷ total × 100 (ties are named together; nothing is said if everything is equal or all zero) | same data as the cards/charts of that tab | same as the tab | "It is just the largest bar written as a sentence, not a judgement." | Never estimates a figure the report does not carry. |

## Tab 1: Demand

KPI cards (top of tab)

| Name on screen | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| Requests filed | How many requests were filed in the period | count of requests where `created_at` is in the window | `tbl_service_request`: `created_at`, `barangay_id`, `service_id`. All statuses counted. | date, barangay, service | "Total requests received in the chosen period, whatever their outcome." | Service requests only; equipment loans are not in this number. |
| Closed | Share of those requests that have reached an end | (Resolved + Cancelled + Disapproved) ÷ all requests filed in the window × 100 | `tbl_service_request.status` (grouped by month in the Outcomes data) | date, barangay, service | "Of what came in during this period, how much is finished one way or another." | "Closed" includes cancelled and disapproved, not only successfully resolved. Pending/Booked/Responding count as not closed. |
| Median time to resolve | The middle waiting time from filing to a real resolution | median of (`resolved_at` − `created_at`) in days, only for requests with status `Resolved`. Under 1 day is shown in hours. | `tbl_service_request`: `created_at`, `resolved_at`, `status = Resolved` | date, barangay, service | "Half of resolved requests were finished faster than this, half slower." | Cancelled and disapproved are excluded (not a real resolution). Requests with no `resolved_at` are skipped; the card states how many requests the figure came from. Old rows were backfilled from the audit log by `serbis:backfill-request-timestamps`, so the sample can be smaller than the request count. |
| Open now | How many requests are still unfinished today | count of requests whose status is not Resolved/Cancelled/Disapproved | `tbl_service_request.status`, `created_at` | **Ignores the date range on purpose** (an old unfinished request must not hide). Barangay and service filters apply. | "This is today's backlog, regardless of the dates picked." | Part of a report cached up to 5 minutes (cleared when a request changes). |

Charts

| Name on screen | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| When requests are filed: "Most requests: [day] [time block]" | The single busiest day-and-time combination | highest count among the 7 weekdays × 4 time blocks | `tbl_service_request.created_at`, converted to Manila | date, barangay, service | "The one slot where most requests were filed." | Based on when the request was filed, not when it was handled. |
| Busiest days (bar chart) | Requests filed on each weekday | count of requests per weekday (Mon–Sun, Manila date) | `tbl_service_request.created_at` | date, barangay, service | "Which days of the week people file the most." | All statuses counted. Monday-first. |
| Busiest time of day (bar chart) | Requests filed in each part of the day | count per block: Morning 6 AM–12 PM, Afternoon 12–6 PM, Evening 6 PM–12 AM, Late night 12–6 AM | `tbl_service_request.created_at` | date, barangay, service | "Which part of the day the requests come in, useful for staffing the desk." | Manila hours. Without the Manila conversion, results would be 8 hours out. |
| Requests by month and service (stacked bar, with Chart/Table toggle) | Monthly request counts, split by which service was asked for | count of requests per (Manila month, service). A request with no service is shown as "Others". | `tbl_service_request.created_at`, `service_id` joined to `tbl_services.service_name` | date, barangay, service | "Monthly volume and what share each service takes." | Month is the Manila month of filing. Up to 8 services get their own colour; extra small ones are folded into one grey segment in the chart (the Table view still lists each). Table view has a Total column. |

## Tab 2: Barangays

| Name on screen | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| What stands out (this tab) | Which barangay files the most requests | top barangay requests ÷ total requests × 100; "X of Y barangays filed at least one"; walk-in count | `barangayCoverage` below | date, barangay, service | "A plain-words summary of the table beside it." | Service requests only. Loans are counted separately. |
| Map of Echague ("Requests in range") | Each barangay shaded by how many requests it filed. Darker = more. Grey = none. Hover/tap/tab shows Residents and Requests. | shade strength = barangay requests ÷ the busiest barangay's requests | requests: `tbl_service_request.barangay_id`; boundaries: bundled `echague-barangays.json`, matched to `tbl_barangay.psgc_code` (not by name) | date, barangay, service | "A heat map of where requests come from." | Plain SVG, no online map or tiles. Shows counts, not per-person rates (barangay population is not in the data). |
| Table column: Barangay | Name of each barangay | every row of `tbl_barangay`, A–Z in the data, then shown busiest first | `tbl_barangay.barangay_name` | barangay filter limits which are "selected" | "Every barangay is listed, even with zero requests." | Built from the full roster outward so a barangay with no requests still appears at zero. |
| Table column: Residents | Registered Head of the Family accounts in the barangay | count of `tbl_residents` where `account_type = 'head_of_family'` and barangay set, per barangay | `tbl_residents.barangay_id`, `account_type` | **Not date-limited** (an account does not expire). Follows the barangay filter (unselected barangays show "—"). Service filter does not apply. | "How many households have registered in the app, per barangay." | Current registration, not as of the period. Counts Heads of the Family only, not members. |
| Table column: Requests (with bar) | Service requests filed from that barangay | count of requests by the request's own `barangay_id` | `tbl_service_request.barangay_id`, `created_at`, `service_id` | date, barangay, service | "How many requests each barangay filed in the period; the bar is relative to the busiest one." | Uses the barangay recorded on the request when filed, not the resident's current barangay. All statuses. |
| Table column: Loans | Equipment loans by residents of that barangay | count of equipment borrowings by borrowers living in the barangay | `tbl_equipment_borrowing` (`created_at`) joined to `tbl_residents.barangay_id` | date, barangay. Service filter leaves loans at 0 (loans have no service). | "Equipment borrowing activity per barangay." | Uses the borrower's **current** barangay (a loan stores none). All loan statuses counted here, unlike the Equipment tab. |
| Row "Walk-in (no barangay)" | Requests filed at the counter, which carry no barangay | count of requests where `barangay_id` is empty | `tbl_service_request.barangay_id IS NULL` | date, service. A barangay-only filter excludes them; choose "Walk-in" in the filter to include. | "Counter requests cannot be placed on the map, so they are shown on their own line so the total still adds up." | Residents and Loans show "—" for walk-ins. |
| Footer line "N registered residents • N requests • N loans in this range" | Totals for the table | sum of the columns (requests = barangays + walk-in) | same as above | same as above | "These totals reconcile with the header count." | Residents total hidden when only Walk-in is selected. |

## Tab 3: Operations

KPI cards

| Name on screen | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| Closed | Share of requests filed in the period that have ended | (Resolved + Cancelled + Disapproved) ÷ all requests filed × 100 | `tbl_service_request.status` | date, barangay, service | "How much of the period's work is finished." | Same as the Demand card. |
| Resolved | Number of requests successfully completed, and their share | count with status `Resolved`; shown with count ÷ all requests filed × 100 | `tbl_service_request.status` | date, barangay, service | "The ones that were actually carried out to completion." | Requests are grouped by when they were filed, not when they were resolved. |
| Open now | Requests still unfinished today | count of status Pending, Booked or Responding | `tbl_service_request.status` | **Ignores date range.** Barangay and service apply. | "Current backlog." | Same as Demand card. |
| Oldest open | Age of the longest-waiting open request | max of (now − `created_at`) in whole days, among open requests | `tbl_service_request.created_at`, `status` | **Ignores date range.** Barangay and service apply. | "The request that has waited the longest." | Whole days, rounded down. Shows "—" if nothing is open. |

Charts

| Name on screen | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| Outcomes by month | For each month, what share of that month's requests ended up in each status | per Manila month: count per status ÷ month total × 100 (bars add to 100%; tooltip also shows the real count) | `tbl_service_request.created_at`, `status` | date, barangay, service | "Are requests being completed or piling up, month by month." | Each request sits in the month it was **filed**, with its **current** status; a recent month will show more Pending/Booked simply because it has had less time. Status colours are the app's fixed status colours. |
| How Long Requests Have Been Waiting | Open requests grouped by how long they have waited | count of open requests per age bucket: Under a day (<1), 1-3 days, 3-7 days, 7+ days; age = now − `created_at` | `tbl_service_request.created_at`, `status` not in Resolved/Cancelled/Disapproved | **Ignores date range on purpose.** Barangay and service apply. | "Shows if anything is getting stale. The Dashboard shows the same buckets." | Under the chart: "Oldest open request: N days". Shared code with the Dashboard so both always agree. Age is computed when the report is built (cache up to 5 min). |

## Tab 4: Equipment & Vehicles

KPI cards

| Name on screen | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| Equipment loans | Number of loans where the item actually went out | count of borrowings with status `Released` or `Returned` | `tbl_equipment_borrowing.status`, `created_at` | date only. Barangay and service filters do **not** apply here. | "Real loans, not requests that were pending or refused." | Excludes Pending, Denied, Cancelled. Note: the Barangays tab "Loans" column counts all statuses, so the two can differ. Note reads "Across X of Y catalogue items". |
| Returned late | Share of returned loans that came back after the due date | loans returned after due date ÷ loans returned that have a due date × 100, rounded | `tbl_equipment_borrowing`: `returned_at`, `due_date` | date (by loan creation). Barangay applies (via borrower). Service does not. | "Of the items given back, how many were past the due date." | Compares the Manila date of `returned_at` with `due_date`. Loans still out are neither on time nor late yet. Loans with no due date are left out of both numerator and denominator. Shows "—" if none. |
| Items never borrowed | Catalogue items with zero loans in the period | count of equipment items with 0 loans; first 3 names listed | `tbl_equipments` compared to loans above | date only | "Possible unused stock." | Not borrowed in this period, not necessarily never. |
| Vehicle trips | Total ambulance trips made in the period | sum of trips over all vehicles | `tbl_conduction_requests.departed_office_at` | date only | "Trips actually dispatched." | A trip counts only if a departure time was recorded. |

Tables / tiles

| Name on screen | Plain meaning | Formula | Data source | Range / filters | Say it like this | Caveat |
|---|---|---|---|---|---|---|
| Equipment utilization (table: Item, Times borrowed, Quantity borrowed) | How often, and how many units, of each item were borrowed | Times borrowed = number of loans; Quantity borrowed = sum of `quantity` over those loans; sorted most borrowed first | `tbl_equipments` (full catalogue) + `tbl_equipment_borrowing` (`status` Released/Returned, `created_at`, `equipment_id`, `quantity`) | date only. No barangay or service filter, since item use is a fact about the item. | "Which items are in demand and which sit unused; every catalogue item is listed even at zero." | Footnote: "N of M items were not borrowed in this range". No icons or categories (the catalogue has none). |
| Equipment returns tile "Returned late" | Same figure as the Returned late card, with the counts | late ÷ returned-with-due-date × 100; caption "X of Y returned with a due date in this range" | as above | as above | "On-time versus late returns." | Section hides to an empty message when no loan was returned in the range. |
| Most used vehicles (horizontal bar) | Trips made by each ambulance/vehicle | count of trip-log rows per vehicle | `tbl_conduction_requests` (`vehicle_id`, `departed_office_at`) with `tbl_vehicles.unit_identifier` | date only. No barangay/service filter (trip logs hold no resident and are all the one dispatch service). | "Which units are working most." | Counted on the departure time, so a trip logged a day late lands on its real day. Every vehicle is listed even at zero, but the whole card is hidden when no vehicle has a trip in the range. |

## Computed by the server but not shown on screen

If the validator asks: the report also holds these, not displayed as cards: first-response median hours (`first_responded_at` − `created_at`, statuses Booked/Responding/Disapproved), a resolution-time histogram (Same day / 1-3 / 3-7 / 7+ days, all closed requests), median days a loan was out, loans currently overdue, and a walk-in vs app-filed split by month. Do not present them as on-screen features.

## Likely validator questions

- **Why can numbers differ between tabs?** Open now / Oldest open / waiting chart ignore the date range by design. Equipment tab ignores barangay/service. Loans on the Barangays tab count all statuses; on the Equipment tab only Released/Returned.
- **Why does "Closed" look low for the latest month?** Requests are grouped by filing month with their status today; recent ones have had less time.
- **Is the data fresh?** Up to 5 minutes old at worst, and refreshed immediately after staff change a record.
- **What is excluded?** Anything outside the chosen window (except the "open now" figures), and records missing the timestamp a calculation needs (counted in the sample size shown).
