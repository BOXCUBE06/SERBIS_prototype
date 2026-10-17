# SERBIS — IT Validator Checklist

Coordination software for MDRRMO Echague. Not an emergency-response or dispatch tool. Paths are relative to the repo root. No secret values appear here; env names only.

Checked: ☐ Show it &nbsp;|&nbsp; Presenter: ____________ &nbsp;|&nbsp; Date: ____________

## 1. Architecture and hosting

| ☐ | Item | Where to show it |
| - | --- | --- |
| ☐ | **API:** Laravel 13 / PHP 8.4, Sanctum, on **Railway** (Docker, auto-deploy from `main`) | `https://serbis-api-production.up.railway.app/api`; `Backend/SERBIS-Backend/Dockerfile`, `docs/deploy-railway.md` |
| ☐ | **Database:** MySQL 8 on Railway, private network (no public DB port) | Railway dashboard; env `DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD` in `.env.example` |
| ☐ | **Admin panel:** Vue 3 + Vuetify + TypeScript on **Vercel**; API root baked in at build via `VITE_API_BASE` | Vercel dashboard (project URL); `Web/serbis-admin-vue/src/config/api.ts`, `vercel.json` |
| ☐ | **Mobile:** Flutter, sideloaded release **APK** (~53 MB); API address is a build flag `API_BASE_URL` | `Mobile/README.md`; `flutter build apk --release --dart-define=API_BASE_URL=https://…/api` |
| ☐ | **Storage:** Supabase Storage via S3 gateway; private disk (IDs, photos) vs public disk (info materials) | env `AWS_*`, `UPLOADS_PRIVATE_DISK`, `UPLOADS_PUBLIC_DISK`; `app/Traits/ResolvesUploadDisks.php` |
| ☐ | **Scheduled jobs:** Railway Cron daily 00:00 UTC: `serbis:send-return-reminders`, `sanctum:prune-expired --hours=24` | Railway Cron service; `app/Console/Commands/SendReturnDueReminders.php` |
| ☐ | **Boot guards:** refuses to start on missing `APP_KEY`/`DB_*`/`ADMIN_SEED_PASSWORD`/`SMS_BLAST_CODE_SEED`; throws if `APP_DEBUG` true in production | `Backend/SERBIS-Backend/docker-entrypoint.sh`, `app/Providers/AppServiceProvider.php` |
| ☐ | **Landing page:** none found in the repo (see §7) | — |

## 2. Security

| ☐ | Control | Where to show it |
| - | --- | --- |
| ☐ | **Admin login by username** + password (old email clients rejected) | `AuthController::adminLogin` (`app/Http/Controllers/AuthController.php` ~L614-680) |
| ☐ | **Residents log in by phone number** (canonical `+639…`, unique) + SMS code | `app/Support/PhoneNumber.php`; tests `ResidentPhoneLoginTest`, `PhoneCanonicalTest` |
| ☐ | **Sanctum bearer tokens**, two audiences. Expiry: admin 8 h, resident 30 days; expired tokens pruned daily | `config/sanctum.php` (`SANCTUM_ADMIN_EXPIRATION`, `SANCTUM_RESIDENT_EXPIRATION`) |
| ☐ | **Admin MFA by SMS code** (5-minute challenge, resend throttle). **Switch `ADMIN_MFA_ENABLED`, default off** — confirm it is on in Railway before the demo | `config/serbis.php:75`; `AuthController` `adminLoginVerify`; test `AdminSmsMfaTest` |
| ☐ | **Forced password change** after a staff reset (403 `password_change_required` everywhere else) | `POST /admin/change-password`; `routes/api.php` L83 |
| ☐ | **Roles:** `is.admin` gate + per-section permissions (`section:` middleware); NULL = unrestricted; Staff page is super-admin only | `app/Http/Middleware/IsAdmin.php`, `EnsureSection.php`, `app/Support/AdminSections.php`; tests `SectionMiddlewareTest`, `AdminSectionAccessTest` |
| ☐ | **Rate limiting**, split by purpose: `api` 60/min, `admin-api` 300/min, `login` 5/min per account+IP, `register`, `password-reset`, `phone-change`, `password-change`, `mfa` (keyed on challenge), `sms-blast` 3/hour, `export-log` | `app/Providers/AppServiceProvider.php` L42-155; test `RateLimitTest` |
| ☐ | **Input validation** on every write; bounds on text fields | controllers' `validate()`; test `InputBoundsTest` |
| ☐ | **Password hashing:** `Hash::make` (bcrypt, `BCRYPT_ROUNDS`); OTP codes stored hashed, never on the resident model | `AuthController` L137, L357, L445 |
| ☐ | **Uploads private:** government IDs, site photos, resident photos stored on a private disk; paths hidden, clients get booleans; file served only after ownership/section check | models' `#[Hidden]`; tests `PrivateFileAccessTest`, `OwnershipScopeTest` |
| ☐ | **Mobile token storage:** Keychain / EncryptedSharedPreferences via `flutter_secure_storage`; logs never contain bodies or tokens | `Mobile/pubspec.yaml` L18; `Mobile/lib/state/api_service.dart`, `app_log.dart` |
| ☐ | **Admin token in browser:** `localStorage` with client-side expiry (server cap 8 h) | `Web/serbis-admin-vue/src/composables/authToken.ts` |
| ☐ | **CORS:** allowlist = `ADMIN_FRONTEND_URL` + Vercel preview pattern; headers limited; dev origin only when `APP_ENV=local` | `config/cors.php` |
| ☐ | **HTTPS:** Railway/Vercel terminate TLS; release mobile builds must use `https://`, cleartext exempt in debug only | `bootstrap/app.php` (proxy/scheme comment); `Mobile/README.md` |
| ☐ | **Dev bypasses blocked in prod:** `SERBIS_OTP_BYPASS_CODE` / `SERBIS_SMS_FAKE` — app refuses to boot with bypass set outside `local` | `AppServiceProvider`; test `OtpBypassTest`, `ProductionDebugGuardTest` |
| ☐ | **SMS abuse controls:** blast gated by hashed shared code; links/domains refused locally | `tbl_sms_blast_code`; `app/Services/Sms/SmsMessagePolicy` |
| ☐ | Prior audit on file | `Backend/SERBIS-Backend/docs/security-performance-audit-2026-07-15.md` |

## 3. Data

| ☐ | Item | Where to show it |
| - | --- | --- |
| ☐ | **115 migrations**; domain tables prefixed `tbl_`, non-`id` primary keys | `Backend/SERBIS-Backend/database/migrations/` |
| ☐ | **Core tables (30):** `tbl_residents`, `tbl_user` (staff), `tbl_barangay`, `tbl_services`, `tbl_service_translations`, `tbl_service_audience`, `tbl_service_vehicle_types`, `tbl_service_request`, `tbl_service_request_relatives`, `tbl_ambulance_bookings`, `tbl_ambulance_destinations`, `tbl_conduction_requests`, `tbl_conduction_request_people`, `tbl_vehicles`, `tbl_responders`, `tbl_request_responders`, `tbl_equipments`, `tbl_equipment_borrowing`, `tbl_info_materials`, `tbl_emergency_hotlines`, `tbl_disaster`, `tbl_device_tokens`, `tbl_recipients`, `tbl_sms_logs`, `tbl_sms_queue_ids`, `tbl_sms_blast_code`, `tbl_system_logs`; plus Laravel's `personal_access_tokens`, `cache`, `jobs`, `failed_jobs`, `sessions` | `grep "Schema::create" database/migrations` |
| ☐ | **Key relations:** `tbl_service_request` is the spine. Ambulance booking = service request with status `Booked`; details in `tbl_ambulance_bookings` (1:1 on `request_id`). Trip record `tbl_conduction_requests.service_request_id` → request. Borrowing → resident + equipment. Residents → barangay | `CLAUDE.md` "Service requests"; models in `app/Models/` |
| ☐ | **Statuses:** Pending, Booked, Responding, Resolved, Cancelled, Disapproved | `ServiceRequestController::TERMINAL_STATUSES`; test `ServiceRequestTransitionMatrixTest` |
| ☐ | **Audit trail:** `TracksHistory` trait writes every create/update/delete to `tbl_system_logs`; sensitive columns excluded via `$ignoreLogging` | `app/Traits/TracksHistory.php`; admin page **Logs** (`/logs`); test `InventoryAuditLogTest` |
| ☐ | **SMS and export logs:** every text, delivery status, and every data export is logged | **Logs** page, SMS tab; `tbl_sms_logs`; test `ExportLogTest` |
| ☐ | **Backups:** **no backup process is documented or configured in the repo.** Railway MySQL backup/snapshot setting must be checked in the dashboard; Supabase file backup likewise (see §7) | Railway → MySQL service → Backups; Supabase → Storage |

## 4. Testing

| ☐ | Area | Files | Test methods |
| - | --- | --- | --- |
| ☐ | Backend (PHPUnit, Feature) | 160 | 1,348 |
| ☐ | Backend (Unit) | 1 | 1 |
| ☐ | Mobile (Flutter, `Mobile/test`) | 63 | 826 cases |
| ☐ | Admin panel (Playwright e2e, `Web/serbis-admin-vue/e2e`) | 4 | ~17 (+ type-check) |

Counts are by file/method scan (`function test_` / `#[Test]`, `test(`/`testWidgets(`), not a run result. Last recorded full run (2026-09-21): backend 1,118 passing; Flutter 611 passing with 4–5 known pre-existing failures. Re-run before the presentation for current numbers.

Run:
- Backend: `cd Backend/SERBIS-Backend && php artisan test` (needs local MySQL, db `serbis_phpunit`, user `root`; hangs if MySQL is down)
- Mobile: `cd Mobile && flutter test`
- Admin: `cd Web/serbis-admin-vue && npm run type-check`; e2e: `npx playwright test`
- CI: `.github/workflows/tests.yml` runs Flutter + PHP tests on every push/PR

## 5. Integrations

| ☐ | Integration | Used for | Where to show it |
| - | --- | --- | --- |
| ☐ | **SMS — SkySMS** (credits, 30/min, 3/s shared limit; paced sender) | Resident login/registration OTP, password reset, phone change, admin MFA code, ambulance booking reminders, admin text blast | `app/Services/Sms/`; env `SKYSMS_API_KEY`, `SKYSMS_BASE_URL`; admin **Text blast** page (balance, delivery status) |
| ☐ | **Push — Firebase Cloud Messaging** | Equipment due-back reminders and available-again notices (push only, no SMS fallback), borrowing status changes, new info-material alert, ambulance reminders | `app/Services/Fcm.php`; env `FIREBASE_CREDENTIALS`; `tbl_device_tokens`; tests `FcmSendTest`, `SendReturnRemindersPushTest` |
| ☐ | **Email** | **Not used for residents** (removed; SMS is the only channel, no email fallback). Mail driver variables exist (`MAIL_*`) and one test covers an admin-email reminder path | `.env.example`; test `SendReturnRemindersAdminEmailTest` |
| ☐ | **Supabase Storage (S3)** | IDs, site photos, handover photos, resident photos, info materials | env `AWS_*` |

## 6. Demo flows (click paths)

1. **Resident request (mobile → admin):** Mobile: sign in with phone + SMS code → Services → pick a service → fill form, attach ID/photo → Review → Submit → My requests shows "Pending". Admin: **Manage requests** (`/manage-requests`) → open request → Approve. Mobile: status updates, push received. Logs page shows the change.
2. **Ambulance booking + trip log + emergency pull:** Mobile: Services → Ambulance → step-by-step form (patient, destination, date/time, units free per the availability picker) → Submit. Admin: **Manage requests** → Approve (unit assigned, status `Booked`). **Trip logs** (`/conduction-requests`) → open the trip → fill trip log (responders, times) → print. *Walk-in / emergency:* **Trip logs** → New → create a trip with no prior booking (the "walk-up-emergency" case in `ConductionRequestView.vue`); vehicle status flips Available → Dispatched → Available.
3. **Equipment borrowing:** Mobile: Borrow → choose item and quantity → submit. Admin: **Borrowings** (`/borrowings`) → approve → record handover with photo → later mark returned; **Inventory** (`/inventory`) shows stock change. Due-back push reminder from the daily job.
4. **Text blast:** Admin: **Text blast** (`/sms`) → choose barangays → write message (no links) → enter blast code → Send (shows Queued) → Recent blasts → open detail → per-barangay delivery and "Check status". Mobile: Advisories tab lists it.
5. **Analytics:** Admin: **Dashboard** (`/`) live strip, open requests, map; **Analytics** (`/analytics`) → requests by service and barangay, equipment utilization, loan turnaround, vehicle usage, app adoption → Export (logged).

## 7. Known limitations and open items

- **Admin MFA is off by default** (`ADMIN_MFA_ENABLED=false`). Staff need a stored mobile number first (`php artisan staff:usernames --missing`). Confirm the production setting.
- **No documented backup/restore.** Nothing in the repo; relies on whatever Railway and Supabase provide. Restore has never been tested.
- **No landing page in the repo.** If one exists, it is outside this codebase.
- **SMS delivery is unproven at scale.** First real sends (2026-09-20/21) showed bulk messages sitting `pending`; a SkySMS support ticket was open. Blasts are recorded as Queued, never Sent, until a delivery check confirms.
- **Mobile is sideloaded APK only.** No Play Store release; old APKs get 410 `app_update_required`.
- **Production barangay list incomplete:** was 3 barangays; `php artisan barangays:sync` still to be run on Railway (verify).
- **Awaiting MDRRMO sign-off:** Citizen's Charter, drill-type and certification lists, real purok list (1–6 is a placeholder), vehicle-to-service mapping, Echague DRRM Plan 2026 check, medical first-aid article review.
- **Cron unverified from code:** the daily reminder job exists only as a Railway dashboard setting; confirm it ran.
- **Tests:** admin panel has only a thin Playwright suite (no unit tests); backend suite needs MySQL (no sqlite).
- **Admin token in `localStorage`** (XSS-exposed by design; mitigated by 8 h server cap).
- **Stale comments:** some repo comments and `docs/branch-workflow.md` still mention Cloudflare R2 / Render; actual hosting is Railway + Supabase.
- **Not built, by decision:** offline queueing, crash reporter, dispatch/emergency-response features.
