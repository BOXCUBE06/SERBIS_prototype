# SERBIS Cloud Migration Plan

**Date:** 2026-07-15 · **Status:** PLAN ONLY — nothing in this document has been executed.
**Scope:** Laravel backend (`Backend/SERBIS-Backend`) + Vue admin panel (`Web/serbis-admin-vue`). The Flutter mobile app is a teammate's area and is out of scope; steps that need their involvement are flagged `[MOBILE-COORD]` and not designed here.
**Constraint:** low-budget student capstone serving one municipality in the Philippines. Real MDRRMO resident data, including government-ID scans, so audit finding #8 is treated as a first-class migration requirement, not an afterthought.

Every codebase claim below was verified by grep/read on 2026-07-15. Pricing, region availability, and vendor-feature claims that could not be verified from here are marked **[VERIFY]**.

---

## 0. What the codebase actually is (grounding for everything below)

- **Zero raw SQL.** `DB::raw`, `whereRaw`, `selectRaw`, `DB::select`, `DB::statement` — no hits anywhere in `app/`, `database/`, or `routes/` (the only mention is prose in the audit doc). All queries are Eloquent/query-builder.
- **Analytics aggregates in PHP, not SQL.** `AnalyticsController.php:107,120,125,134` group collections with PHP closures after fetching whole tables. Portable across databases by construction (it's also audit finding #11 — slow — but that's orthogonal).
- **Auth is pure Sanctum bearer tokens.** `config/cors.php:34` sets `supports_credentials => false`; the admin panel stores the token in `localStorage`. No cookies, no `SANCTUM_STATEFUL_DOMAINS` concern.
- **The admin panel hardcodes the API origin 31 times across 12 Vue files** (`fetch('http://localhost:8000/...')` — e.g. `DashboardView.vue:288`, `LoginView.vue:127`, `ManageRequestView.vue:142,336-418`, `components/index.ts:14`). There is no API base-url constant. This, not the database, is the largest mechanical change in the whole migration.
- **Files live on the local public disk** (`ServiceRequestController.php:51` — ID scans; `InfoMaterialController.php:32` — info materials), which is both audit #8 and a hard blocker for any PaaS with an ephemeral filesystem.
- **One vendor dependency:** SkySMS, key via `config/services.php:31-33`, endpoint hardcoded at `SmsController.php:41`. No sandbox exists; real sends cost credits.
- Rate limiting is IP-keyed for unauthenticated routes (`bootstrap/app.php:16`, `throttle:login` in `AppServiceProvider`), which matters behind a reverse proxy — see §4.

---

## 1. Hosting the Laravel app

This is the real decision. The DB and storage follow from it.

### Options compared

| | A. VPS in Singapore (DigitalOcean/Vultr) | B. PaaS (Railway / Render) | C. Laravel Cloud (official) |
|---|---|---|---|
| **Cost** | ~US$6/mo for the smallest droplet **[VERIFY]**; can co-host MySQL and files → one bill covers everything | Railway: usage-based, ~US$5/mo hobby **[VERIFY]**. Render: free web-service tier exists but **spins down when idle; cold start can exceed 30 s** **[VERIFY]** — fatal for a live demo | Has a free/sandbox tier **[VERIFY]**; paid tiers likely exceed capstone budget **[VERIFY]** |
| **Deploy workflow** | Manual: git pull + `composer install` + `artisan migrate` + queue/scheduler setup. You own TLS (Caddy/certbot), PHP-FPM, backups | `git push` → build → deploy. TLS, env vars, logs managed. Closest to zero ops | `git push`, first-party Laravel support (queues, scheduler, `config:cache` handled) |
| **Latency from PH** | SGP region: ~30–60 ms Manila→Singapore, the practical floor for this region | Railway and Render both list a Singapore region **[VERIFY]** — if confirmed, same ~30–60 ms; a US-only region would mean 180–250 ms on every API call | Region list **[VERIFY]** |
| **Filesystem** | Persistent — local file storage keeps working (though #8 still forces the private-access redesign) | **Ephemeral — files vanish on every redeploy.** Object storage becomes mandatory, not optional | Ephemeral **[VERIFY]** — same implication |
| **Failure modes** | You are the sysadmin: unattended upgrades, firewall, MySQL backups. Real skill cost for a student team | Free tiers sleep; usage caps can pause the service mid-month | Newest product of the three; least community folklore when stuck |

### Recommendation

**B — a PaaS with a Singapore region, Railway-style always-on hobby tier** (not a sleep-on-idle free tier). Reasons, in order:

1. The team's scarce resource is time and ops experience, not $5/mo. A VPS makes you responsible for TLS renewal, MySQL backups, and security patching on a box holding government-ID scans — that is the wrong place to economize.
2. The ephemeral filesystem is a feature here, not a bug: it *forces* the #8 fix (files must leave the local disk) instead of letting the public-disk pattern survive the migration.
3. Cold-start free tiers (Render free) are disqualified for a capstone whose grade partly depends on a live demo.

A VPS (option A) is the fallback if PaaS Singapore regions don't check out **[VERIFY]** or the budget is genuinely $0/mo forever.

The Vue admin panel is a static build (`npm run build` → `dist/`) and hosts free on Netlify / Cloudflare Pages / Vercel regardless of the API choice. It is not part of this decision.

---

## 2. Database: MySQL → Postgres audit

### What the grep actually found

The honest headline: **this codebase is unusually cheap to port.** Zero raw SQL (see §0), no stored procedures, no MySQL-specific functions (`DATE_FORMAT`, `GROUP_CONCAT`, `IFNULL`, `RAND()`, … — zero hits outside `vendor/`). The complete list of things that need attention:

| # | Item | Location | On Postgres |
|---|------|----------|-------------|
| 1 | `enum('status', [...])` ×2 | `database/migrations/2026_06_22_042126_create_tbl_equipments_table.php:16`, `2026_06_22_042205_create_tbl_equipment_borrowing_table.php:16` | Works on a **fresh `migrate`** — Laravel emits `varchar + CHECK` on pgsql. Only a concern if importing the *existing* MySQL schema with a tool (pgloader maps MySQL enum to text; acceptable) |
| 2 | **Case-insensitive string comparison — the one real behavioral break.** MySQL's default collation matches `Admin@serbis.com` = `admin@serbis.com`; Postgres does not | `AuthController` `adminLogin`/`residentLogin` (`where('email_address', ...)`), `unique()` on `tbl_user` (`..._create_tbl_user_table.php:19`) and `tbl_residents` email columns | Logins typed with different case silently fail; two accounts differing only by case become insertable. **Fix:** normalize emails to lowercase on write and lookup (small code change), or use `citext`. Must be an explicit step, not a hope |
| 3 | `unsignedBigInteger` / `unsigned*` | `2026_07_02_130036_update_tbl_system_logs_for_residents.php:16` and FK columns throughout | Postgres has no unsigned types; Laravel silently maps to `bigint`. Cosmetic |
| 4 | `->change()` column modification | same migration, lines 16, 36 | Native in Laravel 11+ on pgsql; fine on fresh migrate |
| 5 | `lockForUpdate()` | `ServiceRequestController.php:61` | `SELECT ... FOR UPDATE` — identical semantics on Postgres |
| 6 | Seeders | `AdminSeeder.php`, `ResidentSeeder.php` | Already portable: env-guarded (commits `f4a29c5`, `8364705`), no `disableForeignKeyConstraints`, barangay ids drawn from the real table, no explicit-ID inserts that would desync Postgres sequences. One non-Postgres note: `fake()->phoneNumber()` produces US-format numbers — an SMS-blast test against seeded data would waste real SkySMS credits; irrelevant to the port itself since seeders are local/testing-only |
| 7 | `database` driver for session/cache/queue | `.env.example:30,38,40`; `0001_01_01_*` migrations | All three tables work identically on Postgres |

### The honest comparison you asked for

**Is managed MySQL less work than Supabase Postgres?** Less *code* work: the diff above shrinks to zero and item 2's regression risk disappears. But the code work for Postgres is genuinely small — item 2 is the only functional change, everything else is fresh-`migrate` transparent — and the *operational* work (provision, migrate, retest end-to-end) is identical for either engine.

What tips it: **budget and bundling.** Free managed MySQL is nearly extinct (PlanetScale ended its free tier; Railway MySQL bills usage **[VERIFY]**; Aiven's free MySQL is 1 GB with limits **[VERIFY]**), and none of those options solve file storage. Supabase's free tier bundles Postgres + object storage with signed URLs + an `ap-southeast-1` (Singapore) region **[VERIFY all three]** in one account at $0.

**Verdict: Supabase Postgres, used as a plain Postgres + Storage provider.** Explicitly *not* adopting Supabase Auth or Row-Level Security — Laravel remains the only database client, Sanctum remains the auth system. Treating Supabase as infrastructure, not a framework, keeps the migration honest and reversible.

Two Supabase-specific traps to design around (both in §6 risks):

- **Connection pooling mode.** Supabase fronts Postgres with a pooler; transaction-mode pooling breaks prepared statements, which Laravel's pgsql driver uses by default. Must connect via the session-mode port or direct connection **[VERIFY current port/mode scheme]**.
- **Free-tier project pausing** after ~1 week of inactivity **[VERIFY]** — a paused project on demo day is an F. Mitigation: uptime-ping cron or accept the paid tier for demo month.

---

## 3. File storage — where audit #8 gets fixed

### Current state (both directions)

**Resident-uploaded ID scans (must be private):**
- Upload: `ServiceRequestController.php:51` — `store('ids', 'public')` → lands on the public disk.
- Read: `ManageRequestView.vue:142` — `<v-img :src="http://localhost:8000/storage/${valid_id}">` — a **static, unauthenticated URL**. This is #8: anyone with the path fetches the government ID.
- The DB column is a relative path string (`tbl_service_request.valid_id`, varchar — misleading name, it's a path not an id).

**Admin-uploaded info materials (resident-readable by design):**
- Upload: `InfoMaterialController.php:32` — `store('public/info_materials')`, then line 37 bakes `storage/` into the DB path with `str_replace`.
- Read: line 16 — `asset($item->file_path)` builds the URL from the app origin. Breaks the moment files live off-host; must become `Storage::url()`/`temporaryUrl()`.
- Delete: line 55 reverses the string surgery — fragile, works only while the path convention holds.

### Groundwork done 2026-08-02

The host-independent half of this section is now in the code, so choosing a
provider is a configuration change rather than a refactor:

- **`config/filesystems.php` gained `uploads.private` / `uploads.public`**, read
  from `UPLOADS_PRIVATE_DISK` / `UPLOADS_PUBLIC_DISK` and defaulting to today's
  local disks. `ServiceRequestController` and `InfoMaterialController` no longer
  name a disk; they ask the config. Pointing either at `s3` needs no code edit.
- **`InfoMaterialController` stores the bare disk-relative path** instead of
  `'storage/'.$path`, and `full_url` now comes from `Storage::disk(...)->url()`
  rather than `asset()`. The `str_replace` path surgery this section calls out is
  gone; rows written before the change still resolve, via `relativePath()`.
- **A `Procfile`** carries the release-phase sequence (`config:cache`,
  `route:cache`, `migrate --force`, `storage:link`) and a `worker` process for
  the scheduler, which now has a job to run (`sanctum:prune-expired`).

Still open here, because both depend on the provider: the signed-URL read flow
for `resident-ids`, and migrating the existing local files into buckets.

### Target design

Two buckets on S3-compatible storage (Supabase Storage exposes an S3-compatible API **[VERIFY]**; Cloudflare R2 free tier is the fallback **[VERIFY]**). The `s3` disk is already scaffolded at `config/filesystems.php:50` and `.env.example:59-63` — configuration, not new plumbing.

**Bucket `resident-ids` — private.**
1. Upload unchanged in shape: `store('ids', 's3')` with private visibility. DB keeps storing the relative path.
2. **New endpoint** `GET /api/service-requests/{id}/valid-id`, `auth:sanctum`, authorizing **admin OR the owning resident** (`resident_id` match — same ownership pattern as the existing IDOR fixes). Returns a short-lived signed URL (`Storage::temporaryUrl()`, ~5 min) as JSON, or 302-redirects to it.
3. `ManageRequestView.vue:142` changes from the static `/storage/` URL to calling that endpoint. This closes #8: possession of a path is no longer possession of the image.

**Bucket `info-materials` — public-read.** These are informational by definition (evacuation guides, advisories) and residents must read them without auth ceremony. Public bucket + immutable UUID filenames; `full_url` comes from `Storage::url()`. The `str_replace` path surgery at `InfoMaterialController.php:37,54` dies; store the bare relative path.

**Existing-file migration:** iterate `storage/app/public/ids/*` and `storage/app/public/info_materials/*`, upload to the respective buckets under identical relative paths (DB rows then need no update), verify counts + spot-check checksums, then **delete the local copies and the `public/storage` symlink** — leaving the old public copies in place would un-fix #8 while looking fixed.

**Interim note:** step 2's endpoint can ship *before* any cloud work, backed by the local private disk (`Storage::disk('local')` + streamed response instead of `temporaryUrl`). That closes #8 for the current localhost deployment too, and is sequenced first in §5 for exactly that reason.

`[MOBILE-COORD]` Resident-side upload happens at request creation (`POST /api/service-requests`) which mobile will eventually call; the signed-URL read flow is admin-panel-only today. Flag the contract (multipart upload field `valid_id`; reads via the new endpoint) — not designed further here.

---

## 4. Environment, secrets, CORS, SkySMS

What changes when localhost stops being true, by file:

**`.env` (real one — note `.env.example:23` says `sqlite` while dev actually runs MySQL; fix the example as part of this work):**

| Key | Now | Becomes |
|-----|-----|---------|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | `production` / **`false` — non-negotiable.** Debug mode prints full stack traces with paths and SQL to any API caller (observed live during the audit's 500s) |
| `APP_URL` | `http://localhost` | `https://api.<domain>` — feeds `asset()`/`Storage::url()` and signed-URL generation |
| `APP_KEY` | dev key | freshly generated for prod; never reuse the dev key (it encrypts sessions/cookies) |
| `DB_*` | local MySQL | Supabase Postgres host, `DB_CONNECTION=pgsql`, **session-mode pooler port** (§2 trap) |
| `FILESYSTEM_DISK` | `local` | `s3`, plus `AWS_ENDPOINT`/`AWS_BUCKET`/keys for the S3-compatible provider (scaffold exists) |
| `ADMIN_FRONTEND_URL` | `http://localhost:3000` | deployed admin origin, e.g. `https://serbis-admin.pages.dev` |
| `SKYSMS_API_KEY` | in local `.env` | host's secret manager, never in the repo. Read via `config('services.skysms.key')` (`SmsController.php:39`) — already `config:cache`-safe per the audit's #20 fix |

**CORS (`config/cors.php:23`):** single allowed origin from `ADMIN_FRONTEND_URL`, failing closed to a placeholder — correct design, just set the var. The mobile app needs **no CORS entry** (CORS is a browser mechanism; Flutter HTTP is unaffected). If the admin panel gets preview deploys (Netlify/Pages `*.preview` URLs), either pin one production origin or use `allowed_origins_patterns` deliberately — do not wildcard.

**Proxy trust — DONE 2026-08-02.** `$middleware->trustProxies(at: '*', headers: ...)` is in `bootstrap/app.php`, verified with a temporary probe route: with `X-Forwarded-*` present the app now reports scheme `https` and the real client IP `203.0.113.7` instead of `http` and `127.0.0.1`. Narrow `at:` if the PHP port is ever exposed directly. Original analysis kept below.

**Proxy trust — new requirement, easy to miss:** on any PaaS the app sits behind a reverse proxy, so `$request->ip()` returns the proxy's IP unless proxies are trusted. Both `throttle:login` (credential+IP keyed) and `throttleApi('60,1')` (`bootstrap/app.php:16`) degrade to a single shared bucket for *all users* — the CGNAT-lockout failure mode from audit #17, self-inflicted. Add `$middleware->trustProxies(at: '*')` (or the platform's proxy CIDR) in `bootstrap/app.php`. Also required for `https` detection so signed URLs generate with the right scheme.

**Admin panel (`Web/serbis-admin-vue`):** introduce `VITE_API_URL` and replace the **31 hardcoded `http://localhost:8000` references across 12 files** (§0) with it. One mechanical PR, ideally with a tiny `api()` helper so this never recurs. `.env.production` on the static host carries the real URL. Build is proven green as of `7aa2b55`.

**`config:cache` reminder:** production hosts run it; the audit's #20 already established `env()` outside `config/` returns null under it. The codebase was swept clean — keep it that way for any new key added during this migration.

---

## 5. Migration sequence

Each step has a **gate** — what must be *proven* (not assumed) before the next step. Rollback noted where meaningful. Steps 1–2 are pure local work and de-risk everything after.

**Step 0 — Baseline.**
Commit/branch the theming WIP (including untracked `src/composables/useAppTheme.ts` — the build depends on it); tag the repo; `mysqldump serbis_test_db` to a dated file.
*Gate:* clean `git status`; dump restores into a scratch DB (actually test the restore — an untested backup isn't one).
*Rollback:* this *is* the rollback foundation for everything else.

**Step 1 — Repo hygiene, still on localhost.**
(a) `VITE_API_URL` extraction, all 31 call sites. (b) `asset()` → `Storage::url()` and removal of path `str_replace` surgery in `InfoMaterialController`. (c) The private-ID endpoint from §3 against the local private disk — closes #8 immediately, cloud or not. (d) Fix `.env.example` drift.
*Gate:* `npm run build` exits 0; full admin flows (login, requests, ID image view, files upload/delete, SMS page load) work locally; **the old `/storage/ids/...` URL now 404s.**
*Rollback:* plain git revert; nothing external exists yet.

**Step 2 — Prove Postgres locally before touching Supabase.**
Docker Postgres; `DB_CONNECTION=pgsql`; `migrate:fresh --seed`; apply the email-lowercase normalization (§2 item 2).
*Gate:* migrations + seeders green on pgsql; logins (both), request-with-upload, analytics dashboard, and the orphan sweep (0/0) all pass against Postgres; a mixed-case email login test passes *because of* the normalization, not by luck.
*Rollback:* delete the container; MySQL untouched.

**Step 3 — Provision Supabase, migrate schema + data.**
Project in `ap-southeast-1` **[VERIFY]**; `migrate` from Laravel (fresh schema — do **not** pgloader the schema; let Laravel own it). Data: the current DB is 21 seeded residents + 2 real test accounts + logs — decide between fresh-seed + recreate the 2 real accounts (recommended; minutes of work) vs. row-copying test data (not worth tooling).
*Gate:* row counts match the decision made; orphan sweep 0/0 against Supabase; connection uses session-mode pooling and survives 100 sequential requests without prepared-statement errors.
*Rollback:* delete the project; local stack still fully functional.

**Step 4 — Storage cutover.**
Buckets per §3; `FILESYSTEM_DISK=s3`; migrate existing files; flip reads to signed URLs; delete local copies + symlink.
*Gate:* upload → admin-view of an ID works end-to-end on staging; **a direct unauthenticated GET of a known object URL returns 403** (this is the explicit #8 kill-shot — test it, don't infer it); a signed URL expires (fetch after TTL → 4xx); info-material public URL loads from a browser with no token.
*Rollback:* flip `FILESYSTEM_DISK` back to `local` — local files still exist until this gate passes, which is why deletion is the *last* action inside this step.

**Step 5 — Deploy the API.**
PaaS service in Singapore region; env per §4; `trustProxies`; `config:cache` + `route:cache`; HTTPS enforced.
*Gate:* `/up` healthcheck 200 (route exists — `bootstrap/app.php:13`); login from a PH network; throttle test — two different simulated IPs get *separate* buckets (proves proxy trust); a deliberate 500 returns clean JSON with no stack trace (proves `APP_DEBUG=false`).
*Rollback:* the platform's previous-deploy rollback; DB and storage are additive so far.

**Step 6 — Deploy the admin panel.**
Static host; `VITE_API_URL=https://api...`; set `ADMIN_FRONTEND_URL` on the API to the deployed origin.
*Gate:* full admin E2E against the production API from a browser (not curl — CORS only manifests in browsers); no CORS errors in the console; login → view request → view ID image → logout.
*Rollback:* static hosts keep previous deploys; API keeps serving the old origin until env flips.

**Step 7 — SMS verification.** `[MOBILE-COORD]`-adjacent but backend-owned.
The audit's #16 burner-number test, now from the cloud host — SkySMS has no sandbox, so this is one real SMS to a team member's phone, driven from the deployed admin UI.
*Gate:* one real SMS received; SkySMS accepts the cloud host's IP (unknown whether the key is IP-restricted — no sandbox means this is genuinely untestable earlier).
*Rollback:* n/a (send-only vendor; failure here blocks nothing except the SMS feature).

**Step 8 — Handoff + hygiene.**
`[MOBILE-COORD]` Hand the teammate: base URL, Sanctum token flow (`POST /api/resident/login` → bearer token), the request-upload contract, and the signed-URL read pattern. Enable DB backups (platform-side), an uptime ping (also solves free-tier pausing), and confirm the seeder guards refuse on the prod host (`APP_ENV=production` → both seeders skip — already verified locally, re-verify there).

---

## 6. Top 5 risks

| # | Risk | Likelihood | Impact | Mitigation |
|---|------|------------|--------|------------|
| 1 | **Supabase pooler vs. Laravel prepared statements** — transaction-mode pooling errors under real traffic, works in casual testing | Medium | High (intermittent 500s that look random) | Session-mode port from day one **[VERIFY port scheme]**; step 3's 100-request gate exists precisely to surface this |
| 2 | **Free-tier project pausing on demo day** | Medium | High (total outage at the worst moment) | Uptime ping from step 8; budget line for one paid month around the defense date **[VERIFY pause policy]** |
| 3 | **#8 regression during migration** — bucket accidentally public, or old local/public copies left behind, or the signed-URL endpoint shipping without the ownership check | Medium | High (the exact harm this migration is meant to end: government IDs exposed) | Step 4's gate is explicit: direct GET must 403, expiry must work, and local deletion happens only after; ownership check reuses the already-audited IDOR pattern |
| 4 | **Postgres case-sensitivity locks out existing logins** (`Admin@…` vs `admin@…`) and is discovered as "random users can't log in" weeks later | Medium | Medium | Lowercase-normalize at write + lookup in step 2, with a mixed-case test in the gate; sweep existing rows for case-duplicate emails before import |
| 5 | **SkySMS from a cloud IP fails or the key is IP-bound** — unknowable in advance because the vendor has no sandbox (`/docs` returns 403; audit) | Low–Medium | Medium (SMS blast is a headline feature for MDRRMO) | Step 7 burner test immediately after API deploy, before the defense; SkySMS support contact ready; feature degrades gracefully (blast is admin-triggered, not automatic) |

Honorable mention (would be #6): the admin panel's 31-URL refactor missing a call site and silently pointing one view at localhost — mitigated by step 1's full-flow gate and by grep (`localhost:8000` must return zero hits in `src/` at the end of step 1).

---

## Summary of positions taken

1. **Host on an always-on PaaS in Singapore** (Railway-class); VPS is the fallback, sleep-on-idle free tiers are disqualified. The admin panel goes to a free static host either way.
2. **Supabase Postgres over managed MySQL — but honestly, narrowly.** The code diff is near-zero either way (no raw SQL anywhere); Supabase wins on $0 bundling of DB + private storage + Singapore region, not on technical necessity. Use it as plain Postgres + Storage; no Supabase Auth, no RLS.
3. **#8 is solved by the storage design, not deferred by it** — private bucket + ownership-checked short-lived signed URLs for ID scans; public bucket for info materials; and the private-read endpoint ships in step 1 against the local disk, before any cloud exists.
4. **The single biggest mechanical task is frontend, not backend:** 31 hardcoded `localhost:8000` references across 12 Vue files.
5. **Two changes are non-negotiable regardless of vendor choices:** `APP_DEBUG=false` in production, and proxy trust before the throttles are trusted.
