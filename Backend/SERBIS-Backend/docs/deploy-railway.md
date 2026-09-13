# Deploying SERBIS to Railway + Cloudflare R2

**Status:** prepared, not yet executed. Nothing below has been deployed, and no
`railway.json`/`railway.toml` has been added to the repository — this is
dashboard-driven configuration, described here so it can be re-created
identically if a service is ever rebuilt. **This is a fresh database, not a
copy of the Aiven one.** No import, no migration of existing rows: whoever
signed up on Render starts over on Railway.

Target shape:

| Piece | Where | Notes |
|---|---|---|
| Laravel API | Railway **Docker** web service | same image, same `Dockerfile` |
| MySQL 8 | Railway **MySQL** plugin, private network | fresh database, no Aiven import |
| Uploads | **Cloudflare R2**, two buckets | same shape as Supabase — see `config/filesystems.php` |
| Scheduler | Railway **Cron** service | runs the artisan commands directly, no `schedule:run` |
| Admin panel | Vercel, unchanged | repointed at the new API — see §8 |
| API domain | `*.up.railway.app`, TLS automatic | or a custom domain |

Four services in one Railway project: MySQL, the web service, the cron
service, and nothing else — no worker, no Redis. `app/` dispatches no queued
jobs and the cron service below is the only thing that runs on a schedule.

---

## 1. MySQL service

Add the **MySQL** plugin from Railway's template picker. It provisions its
own service in the project and exposes its connection details as variables
on that service — reference them from the web and cron services rather than
retyping them, so a credential rotation on the database side never needs a
second edit.

Assuming the MySQL service is named `MySQL` in the Railway project, reference
syntax is `${{MySQL.VARIABLE}}`:

| This app wants | Railway MySQL service variable |
|---|---|
| `DB_HOST` | `${{MySQL.MYSQLHOST}}` |
| `DB_PORT` | `${{MySQL.MYSQLPORT}}` |
| `DB_DATABASE` | `${{MySQL.MYSQLDATABASE}}` |
| `DB_USERNAME` | `${{MySQL.MYSQLUSER}}` |
| `DB_PASSWORD` | `${{MySQL.MYSQLPASSWORD}}` |

**Confirm these names in the dashboard before wiring them.** Open the MySQL
service's own Variables tab and read the exact keys it published — template
variable names have changed across Railway's MySQL template versions before,
and a typo here fails silently: the reference resolves to an empty string,
`docker-entrypoint.sh` catches the empty `DB_HOST` (see §3) and refuses to
boot, which is at least loud, but is worth ruling out first rather than
debugging from a crashed container.

**Confirm the MySQL version the template actually deployed** — the service's
Settings/Deployments tab names the image, or connect once and run
`SELECT VERSION();`. This app requires MySQL 8; a version drift matters here
specifically because of a trap already hit once with Aiven: a MariaDB
*client* cannot complete the `caching_sha2_password` handshake a real MySQL 8
*server* uses. That was a client-side tooling problem, not a server one, but
it is exactly the kind of mismatch worth ruling out before trusting the
connection.

**No TLS, and that is correct here, not a gap.** Railway's private network
keeps inter-service traffic inside the project, off the public internet, so
there is nothing for `MYSQL_ATTR_SSL_CA` to verify. **Leave it unset.**
`docker-entrypoint.sh` already anticipates this exact case — its own comment
reads *"Railway's MySQL is reached over the private network within the same
project, which needs no TLS at all"* — and `config/database.php`'s
`array_filter` drops the option cleanly when the variable is absent. Nothing
to change in either file.

## 2. Web service

New service, source **this GitHub repo**, same branch that deploys today.

- **Root Directory:** `Backend/SERBIS-Backend`. Every path Railway resolves
  for this service — the Dockerfile, the build context — is relative to this,
  the same rootDir role `render.yaml` gives the equivalent Render service.
- **Builder:** Dockerfile. Railway should auto-detect `Dockerfile` inside the
  root directory; confirm it under the service's Settings rather than
  assuming, since a misdetected Nixpacks build silently ignores
  `docker-entrypoint.sh` entirely — no seeding, no migration, no environment
  validation, and the container answers requests anyway.
- **Health Check Path:** `/up`. Laravel's own endpoint, registered in
  `bootstrap/app.php` — touches no view and no database, so it still answers
  while something else is broken.
- **Networking:** generate a Railway domain (`<service>.up.railway.app`) or
  attach a custom one. TLS is automatic either way.
- **Region:** pick the same region as the MySQL service if Railway offers a
  choice — cross-region private networking works, but there is no reason to
  pay its latency when co-locating is free.

**Nothing in `Dockerfile` or `docker-entrypoint.sh` needs to change for this
move.** `PORT` is supplied by Railway the same way Render supplies it — the
entrypoint reads `${PORT:-10000}` and rewrites Apache's config with whatever
value is actually present, regardless of which platform set it.

## 3. Environment variables

Set these on the web service; the cron service in §4 needs the identical set,
since `docker-entrypoint.sh` runs the same validation and boot sequence on
every start regardless of which service triggered it.

| Variable | Required | Secret | Source |
|---|---|---|---|
| `APP_NAME` | optional | no | fixed: `SERBIS` |
| `APP_ENV` | **required** | no | fixed: `production` |
| `APP_KEY` | **required** | **yes** | `php artisan key:generate --show`, freshly generated — never the dev key |
| `APP_DEBUG` | **required** | no | fixed: `false` — `AppServiceProvider` refuses to boot if this is `true` here |
| `APP_URL` | **required** | no | the Railway domain or custom domain from §2 |
| `LOG_CHANNEL` | optional | no | `stderr` — Railway captures stdout/stderr the same way Render does; `stack`/`single` writes inside the ephemeral container instead |
| `LOG_LEVEL` | optional | no | `warning` — `info` logs every request, `error` hides the SMS fallback warning the delivery questions need |
| `DB_CONNECTION` | optional | no | fixed: `mysql` |
| `DB_HOST` | **required** | no | `${{MySQL.MYSQLHOST}}` — see §1 |
| `DB_PORT` | **required** | no | `${{MySQL.MYSQLPORT}}` |
| `DB_DATABASE` | **required** | no | `${{MySQL.MYSQLDATABASE}}` |
| `DB_USERNAME` | **required** | no | `${{MySQL.MYSQLUSER}}` |
| `DB_PASSWORD` | **required** | **yes** | `${{MySQL.MYSQLPASSWORD}}` |
| `MYSQL_ATTR_SSL_CA` | leave unset | — | private network, no TLS — see §1 |
| `ADMIN_SEED_PASSWORD` | **required** | **yes** | chosen at deploy time, typed directly into the Railway variable |
| `SESSION_DRIVER` | optional | no | fixed: `database` |
| `CACHE_STORE` | optional | no | `file` — `throttleApi()` in `bootstrap/app.php` applies to every route, so a database-backed limiter is two extra round trips per request on a single-instance deploy; move to Redis only if this is ever scaled past one instance |
| `QUEUE_CONNECTION` | optional | no | fixed: `database` — inert, nothing queues a job |
| `UPLOADS_PRIVATE_DISK` | **required** | no | fixed: `s3` |
| `UPLOADS_PUBLIC_DISK` | **required** | no | fixed: `s3_public` |
| `AWS_ACCESS_KEY_ID` | **required** | **yes** | R2 API token — see §5 |
| `AWS_SECRET_ACCESS_KEY` | **required** | **yes** | R2 API token — see §5 |
| `AWS_DEFAULT_REGION` | **required** | no | fixed: `auto` — R2 has no regions |
| `AWS_USE_PATH_STYLE_ENDPOINT` | **required** | no | fixed: `true` — R2 addresses buckets by path |
| `AWS_ENDPOINT` | **required** | no | `https://<account-id>.r2.cloudflarestorage.com` — Cloudflare dashboard, R2 overview |
| `AWS_BUCKET` | **required** | no | the private bucket's name — see §5 |
| `AWS_PUBLIC_BUCKET` | **required** | no | the public bucket's name — see §5 |
| `AWS_PUBLIC_URL` | **required** | no | the public bucket's `r2.dev` domain or a custom domain — see §5 |
| `AWS_URL` | **leave unset** | — | its absence is what stops anything building a direct link to a government ID scan |
| `MAIL_MAILER` | optional | no | fixed: `log` — fallback only; **never `ses`**, `config/services.php` reads the same `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` R2 now uses |
| `MAIL_FROM_ADDRESS` | optional | no | placeholder — mail never actually sends with `MAIL_MAILER=log` |
| `MAIL_FROM_NAME` | optional | no | fixed: `SERBIS` |
| `PHILSMS_TOKEN` | **required** | **yes** | PhilSMS dashboard — a hard deploy blocker, registration OTP has no other channel |
| `PHILSMS_SENDER_ID` | optional | no | fixed: `PhilSMS`, the shared default |
| `FIREBASE_CREDENTIALS_B64` | **required for push** | **yes** | base64 of the downloaded service-account JSON — see the callout below |
| `FIREBASE_CREDENTIALS` | **required for push** | no (the path, not the file) | written by the boot sequence from the variable above — see below |
| `SANCTUM_ADMIN_EXPIRATION` | optional | no | fixed: `480` |
| `SANCTUM_RESIDENT_EXPIRATION` | optional | no | fixed: `43200` |
| `ADMIN_FRONTEND_URL` | **required** | no | the Vercel admin panel's real origin — see §8 |

**How the Firebase service-account file actually gets onto the container —
flagged, not yet built.** `App\Services\Fcm` reads `FIREBASE_CREDENTIALS` as
a **path to a file**, and that file must never be committed — the same rule
as every other credential here, but this one is shaped as a file, not a
string, and Railway has no repository-independent place to put a bare file
the way Render can bake `storage/certs/aiven-ca.pem` into the image (that
file is a public CA certificate; this one is a private key). The plan: paste
the downloaded JSON, base64-encoded, into `FIREBASE_CREDENTIALS_B64`, and add
one line near the top of `docker-entrypoint.sh` — before the environment
validation block — that decodes it to a file under `storage/` and points
`FIREBASE_CREDENTIALS` at that path:

```bash
if [ -n "${FIREBASE_CREDENTIALS_B64:-}" ]; then
    echo "$FIREBASE_CREDENTIALS_B64" | base64 -d > /var/www/html/storage/app/firebase-credentials.json
    export FIREBASE_CREDENTIALS=/var/www/html/storage/app/firebase-credentials.json
fi
```

That edit is **not part of this commit** — this document is the deploy plan,
not the code change. Land it in its own commit before the first real deploy;
without it, `Fcm::configured()` returns false and every push silently no-ops,
exactly as it does today with no credentials configured at all.

### Never set these in production

| Variable | Why it must stay unset |
|---|---|
| `SERBIS_OTP_BYPASS_CODE` | `AppServiceProvider::assertOtpBypassIsUnsetInProduction()` refuses to boot the container if this is set and `APP_ENV=production` |
| `SERBIS_SMS_FAKE` | `AppServiceProvider::assertSmsFakeIsUnsetInProduction()` refuses to boot for the same reason — every SMS would silently fake-succeed with nothing sent |
| `MOBILE_DEV_URL` | inert here (`config/cors.php` only reads it under `APP_ENV=local`), but leave it out — a value that does nothing in production is a value someone will ask about during an incident |

## 4. Cron service

A second service in the same project, same source, same **Root Directory**
and **Builder** as §2 — same image. Add the environment variables from §3 to
it too, in full: `docker-entrypoint.sh` validates the same set on every
start, cron run included.

- **Cron Schedule:** `0 0 * * *` — 00:00 UTC, 08:00 in Manila.
- **Custom Start Command** (overrides the image's `CMD`, not its
  `ENTRYPOINT` — the boot sequence in `docker-entrypoint.sh` still runs first,
  env validation and `migrate --force` included, which is safe since both are
  no-ops against an already-provisioned database):

  ```
  php artisan serbis:send-return-reminders && php artisan sanctum:prune-expired --hours=24
  ```

**This runs the two commands directly rather than `php artisan schedule:run`,
and that is deliberate, not a shortcut.** `routes/console.php` schedules
`send-return-reminders` at `dailyAt('08:00')->timezone('Asia/Manila')` and
`sanctum:prune-expired --hours=24` at a plain `->daily()` (midnight
`APP_TIMEZONE`, which is UTC) — `schedule:run` only fires whichever of those
is actually due at the moment it is invoked, so a cron that runs
`schedule:run` once a day only works if that one invocation happens to land
inside both commands' due windows. 00:00 UTC is exactly `send-return-
reminders`' 08:00 Manila and exactly `sanctum:prune-expired`'s own midnight
UTC default, so calling both commands directly at this one time reaches the
same result with one moving part instead of two schedules that have to keep
agreeing with a single cron trigger.

`sanctum:prune-expired --hours=24` is housekeeping, never enforcement — it
deletes rows for tokens that already stopped being accepted a day earlier.
Nothing is denied access because this cron is briefly down; dead rows in
`personal_access_tokens` merely accumulate a little longer.

## 5. Cloudflare R2

Two buckets, one account, one API token covering both — same shape as the
Supabase buckets this replaces, and for the same reason: `config/filesystems.php`
keeps government ID scans and info materials on separate disks because they
have opposite access rules, and one bucket under one visibility setting would
make either the ID scans world-readable or the info materials unreadable.

- **`serbis-resident-ids` — private.** Government ID scans and resident
  photos. **Leave public access off.** Read only through
  `GET /api/service-requests/{id}/valid-id` and
  `GET /api/residents/{id}/photo`, which check ownership before streaming the
  bytes through the API — nothing should ever link to this bucket directly,
  which is why `AWS_URL` stays unset.
- **`serbis-info-materials` — public read.** Evacuation guides and
  advisories, opened by residents with no token. Enable **public access on
  this bucket only** (R2 dashboard -> bucket -> Settings -> Public access —
  either the bucket's own `r2.dev` domain or a connected custom domain), and
  set `AWS_PUBLIC_URL` to whichever domain that turns on.

**API token scope:** Cloudflare dashboard -> R2 -> Manage R2 API Tokens ->
Create API Token. Scope it to **Object Read & Write**, and to **these two
buckets specifically** — not account-wide. `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY` are that token's access key pair; both buckets share
it, same as `AWS_ENDPOINT` and `AWS_DEFAULT_REGION`.

**The S3 driver package is already in `composer.json`** (`b97daef`,
`league/flysystem-aws-s3-v3`) — nothing to add here, R2 speaks the same S3
API Supabase did.

## 6. Order, with a gate on each step

Do not start a step until the previous gate is proven — observed, not
assumed.

1. **Deploy the API.** Gate: `/up` returns 200. A deliberate 500 returns JSON
   with no stack trace, proving `APP_DEBUG=false`.
2. **Database.** `migrate --force` and `db:seed --class=ProductionSeeder`
   both run in the entrypoint, so a successful boot has already done them —
   against a genuinely empty database this time, not an imported one. Gate:
   **admin login** (`jilmarferrer29@gmail.com`, the `ADMIN_SEED_PASSWORD`
   just set) returns a token, and the service catalogue has ten rows with
   their codes.
3. **Storage.** Upload a government ID scan through a real resident signup
   (next step covers the signup itself), view it in the admin panel, then
   request the R2 object URL directly with no token and **confirm it is
   refused**. Test it; do not infer it from the bucket setting. Then confirm
   an info material opens in a browser with no token at all.
4. **One resident signup, with OTP.** `POST /register` end to end through
   `/resident/verify-email` on a real phone number — this is the SMS path,
   not the mail fallback, and there is no sandbox, so this send is billed.
   Confirm the account lands `Active` and the code arrived by text, not by
   the mail fallback (that would mean the number failed to normalise).
5. **One private and one public upload.** The government ID scan from step 3
   is the private one; if it has not happened yet, do it now. For the public
   one, publish an info material from the admin panel and open it from a
   browser with no admin session.
6. **One push notification.** Register a device token
   (`POST /api/device-tokens`, signed in as the resident from step 4), then
   approve, reject, or reschedule an ambulance booking for that resident from
   the admin panel and confirm the push arrives on the device. This is the
   one check that exercises `FIREBASE_CREDENTIALS` end to end — if it was
   never wired per the callout in §3, this step fails quietly (`Fcm`
   no-ops, nothing throws, nothing appears) rather than with an error, so
   confirm the notification actually arrives rather than just that the
   request returned 200.
7. **Admin panel.** Vercel is unchanged — this step is the client switch in
   §8, done once the API is otherwise verified.
8. **Mobile.** A fresh APK build, not a reused one — see §8 for why an
   already-installed app cannot follow this migration on its own.
9. **Cron.** Trigger the cron service manually once (Railway supports an
   on-demand run separate from its schedule) and confirm both commands exit
   0 in its logs before trusting the `0 0 * * *` schedule to run it
   unattended.

## 7. What stays local

Development is unchanged. `.env` on a developer machine still points at
local MySQL and the local disks; the defaults in `config/filesystems.php`
are the local disks precisely so that none of this changes anything on a
developer machine.

## 8. Client switch

- **Admin panel (Vercel):** set `VITE_API_BASE` to the new API root,
  including `/api`, no trailing slash (`src/config/api.ts` has no localhost
  fallback and no default). Redeploy the Vercel project after changing it —
  it is baked in at build time, not read at runtime. Set `ADMIN_FRONTEND_URL`
  on the Railway web service to this same Vercel origin, or the panel's login
  will pass but every subsequent request will fail CORS in the browser only
  — never in curl, which is what makes that failure mode easy to miss.
- **Mobile:** `flutter build apk --release --dart-define=API_BASE_URL=https://<railway-api>/api`.
  HTTPS is mandatory in a release build regardless of host.

**Old installed APKs keep pointing at Render.** `API_BASE_URL` is baked in at
build time with no runtime config and no update channel — this app
deliberately has no Play Store distribution and no OTA mechanism (see
`PRODUCT.md`/repo decisions on that). A resident's already-installed app
does not follow this migration; only a fresh install of a build made against
the new `API_BASE_URL` does. If both APIs are kept running side by side
during a transition, decide explicitly how long Render stays up for
whoever has not reinstalled — this repo does not do that decision for you.
