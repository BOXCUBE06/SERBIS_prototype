# Ambulance destination dropdown — data audit (2026-09-19)

Read-only audit of `tbl_ambulance_bookings.destination` on the local dev
database (`serbis_test_db`), run before `AmbulanceDestinationSeeder` was
written, per MDRRMO feedback item 6 ("report what destinations exist before
seeding").

## What was there

4 rows total in `tbl_ambulance_bookings`, 3 distinct `destination` values:

| Value | Count |
|---|---|
| `Echague District Hospital` | 2 |
| `asd` | 1 |
| `asdas` | 1 |

## What was seeded

Only **`Echague District Hospital`** — the one value that is plainly a real
destination rather than a manual-test typo. `asd` / `asdas` are excluded.

This is also the only destination named anywhere else in the codebase: the
ambulance form's own hint text (`service_form_fields.dart`) and the two
non-production seeders (`AmbulanceBookingSeeder`,
`AmbulanceBookingStatusDemoSeeder`) all use it as their example, and nothing
in the repo names a second real facility.

## What this means for the feature

One seeded entry is not a usable list on its own — the dropdown's "Others"
free-text fallback is what makes the feature work for every other
destination a resident might actually need, not a gap to fix later. Growing
the canonical list beyond this one entry currently means editing
`AmbulanceDestinationSeeder` and redeploying; there is no admin-panel UI to
add a destination, since none was asked for in this pass.
