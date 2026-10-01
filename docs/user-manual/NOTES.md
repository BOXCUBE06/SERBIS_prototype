# User manual — working notes

## 1. Audit

Sources: `Web/serbis-admin-vue/src/router/index.ts` (routes + guard), `src/composables/adminSections.ts` (sidebar order, groups, section keys), `src/composables/useCurrentAdmin.ts` (`can()`), `src/components/AppSidebar.vue`, and each view for `v-dialog`s.

**Role model.** One role check: each route has `meta.section`; the guard sends the admin to their first allowed page (or `/no-access`) if they lack it. A super admin holds every section. Other accounts hold the sections a super admin gave them (`permissions` NULL = all except Staff). **Staff Accounts is the only super-admin-only page** and cannot be assigned. The server enforces the same list.

| # | Page (sidebar) | Route | Group | Access | Dialogs/drawers worth capturing |
|---|---|---|---|---|---|
| – | Login | `/login` | – | public | – |
| – | Change password | `/change-password` | – | signed in, temp password | not captured (needs a temp-password account) |
| – | No access | `/no-access` | – | signed in, no sections | not captured (super admin never lands here) |
| 1 | Dashboard | `/` | Overview | section `dashboard` | – |
| 2 | Analytics | `/analytics` | Overview | `analytics` | – |
| 3 | Resident Requests | `/manage-requests` | Requests | `requests` | request detail; Log Service Request |
| 4 | Ambulance Dispatch | `/conduction-requests` | Requests | `ambulance` | booking detail; Log Service Request; Trip Logs tab |
| 5 | Equipment Borrowing | `/borrowings` | Requests | `borrowings` | borrowing detail |
| 6 | Vehicles | `/vehicles` | Resources | `vehicles` | Add Unit |
| 7 | Responders | `/responders` | Resources | `responders` | Add Responder |
| 8 | Resource Management | `/inventory` | Resources | `inventory` | Add Equipment |
| 9 | Procurement Reference | `/procurement` | Resources | `procurement` | – (read-only) |
| 10 | Accounts | `/users` | Community | `residents` | account detail; Add account |
| 11 | Documents | `/files` | Community | `files` | – |
| 12 | Text Blast (SMS) | `/sms` | Community | `sms` | Text blast code |
| 13 | Manage Services | `/services-config` | Configuration | `services` | Edit service |
| 14 | Service Audience | `/service-audience` | Configuration | `service_audience` | – |
| 15 | Service Vehicles | `/service-vehicles` | Configuration | `service_vehicles` | – |
| 16 | Staff Accounts | `/staff` | System | **super admin only** | Add staff account; Access |
| 17 | Activity Logs | `/logs` | System | `logs` | – |
| – | Sidebar profile card | any | – | signed in | Confirm Logout |

Skipped as dialogs on purpose: destructive confirmations (delete, disable, close account, reset password, disapprove reasons), the send-blast confirmation, the trip-log editor, the photo lightbox and the export dialog. Opening them risks a real action and adds little to a manual.

## Decisions

- **Placeholders in the task.** `<EMAIL>`, `<PASSWORD>`, `<VUE_PORT>` were never filled in. Used the local seeder account `admin@serbis.com` / `password123` and port `3000` (fixed in `vite.config.mts`; the API's CORS allows only that origin). `capture.js` reads `SERBIS_ADMIN_EMAIL`, `SERBIS_ADMIN_PASSWORD`, `SERBIS_ADMIN_URL` to override.
- `admin@serbis.com` was already a super admin in `serbis_test_db`; nothing promoted, no DB writes.
- **Playwright not installed.** It was already in `Web/serbis-admin-vue/node_modules`; `capture.js` loads it from there. Uses the installed **Edge** (`channel: 'msedge'`), not bundled Chromium: Windows Application Control on this machine blocks the bundled Chromium binary.
- Started the API (`php artisan serve`, :8000) for the run; the admin panel dev server was already up on :3000.
- Each shot reloads its route before opening a dialog, so no dialog has to be closed (several are `persistent`).
- Detail dialogs open the **first row** of the default list, so which record appears depends on local data. Screenshots show local seed data (names, phone numbers); retake against a demo DB before publishing outside the team.
- Manual steps written from the views' actual button labels, checked against the screenshots.

## Capture log

First run: 32 of 34 ok. Two failed both attempts and were fixed in the script, then re-run ok:
- `10-ambulance-trip-records`: a tab, not a dialog; script waited for an overlay that never opens. Marked `noDialog`.
- `32-staff-access`: the button's `aria-label` ("Choose which sections … can open") overrides its visible "Access" text. Matched on the aria-label.

Final: **34 of 34 captured**, nothing skipped. Per-shot result in `capture-log.json`.

## 4. Conversion

pandoc was missing on the first pass. Installed 2026-09-29 on request: `winget install --id JohnMacFarlane.Pandoc -e --scope user` (3.12, at `%LOCALAPPDATA%\Pandoc`; on PATH only in new shells). Built from the repo root with:

```
pandoc docs/user-manual/manual.md --resource-path=docs/user-manual -o docs/user-manual/SERBIS-Admin-User-Manual.docx
```

## Summary

Files produced (nothing committed):
- `docs/user-manual/NOTES.md`: this file
- `docs/user-manual/capture.js`: screenshot script (`node docs/user-manual/capture.js`)
- `docs/user-manual/capture-log.json`: last run's results
- `docs/user-manual/img/01-login.png` … `34-sign-out.png`: 34 screenshots, 1440x900
- `docs/user-manual/manual.md`: the manual (title block, intro, sign-in, 17 pages)
- `docs/user-manual/SERBIS-Admin-User-Manual.docx`: 8.2 MB, 34 images embedded

Pages captured: all 17 sidebar pages, login, plus 16 dialogs/tabs.
Skipped: `/change-password` and `/no-access` (a super admin can't reach them), destructive dialogs (on purpose).
