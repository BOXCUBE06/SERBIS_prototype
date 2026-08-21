# Integration evidence — mobile to backend to admin

Captured 2026-08-21 on the home PC against the running stack (backend `:8000`,
admin `:3000`, Flutter web `:5000`).

This is a **cross-system** trace, not a set of same-app screenshots. One service
request is filed from the Flutter app with a photo attached, and the same
request is then shown arriving in the Vue admin panel with that photo rendered.
Every step is the real UI; nothing was inserted through the API or the database.

## What was filed

| | |
|---|---|
| Reference | **SR-39** (`tbl_service_request.request_id = 39`) |
| Service | Road Clearing (`service_id` 5) |
| Filed by | Testa Resident (`resident_id` 21), San Miguel |
| Filed at | 2026-08-21 02:13:19 |
| Status on arrival | Pending |
| Valid ID stored as | `valid-ids/21/0de74312-….png` |
| Site photo stored as | `site-photos/21/d90b65b0-….png` |

## The frames

| File | What it shows |
|---|---|
| `00-source-site-photo.png` | The image attached, before upload. Generated with a diagonal banding pattern and an orange marker block so it is identifiable on sight in later frames. |
| `01-mobile-home.png` | Flutter app, signed in as the resident. |
| `02-mobile-services.png` | Services tab. |
| `03-mobile-request-types.png` | The service picker, ten types. |
| `04-mobile-road-clearing-form.png` | The Road Clearing form, empty. |
| `05-mobile-form-filled.png` | Location, obstruction type and description entered. |
| `06-mobile-attachments-set.png` | Both attachments accepted — Valid ID (required) and Landmark (the site photo). |
| `07-mobile-submitted-SR39.png` | The confirmation naming **Reference #SR-39**. |
| `08-mobile-track-list.png` | The resident's own Track tab. |
| `09-mobile-track-SR39.png` | SR-39 in Track, under review, with the details as filed. |
| `10-admin-SR39-with-photo.png` | **The pairing frame.** The admin panel's Resident Requests: SR-39 at the top of the list, the description as typed on the phone, and both attachments rendering under ATTACHMENTS — the banded pattern and orange block from `00` are visible in each tile. |

## Why the last frame is the one that matters

The admin panel and the mobile app share no code. The photo in frame `10` was
chosen in a file picker in frame `06`, uploaded by the Flutter client, written to
the backend's private disk under the resident's own folder, and served back
through the owner-scoped read route into the Vue panel. Both `<img>` elements
report `naturalWidth` 480 and `naturalHeight` 360 — the exact dimensions of
`00-source-site-photo.png` — so the bytes on screen in the admin panel are
demonstrably the bytes attached on the phone.

Frame `10` is also the first time the two-tile attachment row has rendered
against real data. The seeded database contains no request carrying a site photo
(`idOnly: 8, none: 30, photoOnly: 0, both: 0`), so until this request existed
that row had only ever been checked by cloning a DOM node.

## Reproducing it

The request is real and still in the dev database (`serbis_test_db`). It can be
left as-is, approved, or deleted — nothing else references it. Re-running the
capture needs the three servers up (see `serbis-environment` notes) and a
resident account on this box; the seeded residents have random passwords, so
register a fresh one via `POST /api/register` and verify it with the code that
`MAIL_MAILER=log` writes into `storage/logs/laravel.log`.
