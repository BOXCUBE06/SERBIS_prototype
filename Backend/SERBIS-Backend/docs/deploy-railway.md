# Deploying SERBIS to Railway + Supabase Storage

**Status:** prepared, not yet executed. Everything below is the plan the repo is
now configured for; nothing has been deployed.

Target shape:

| Piece | Where | Cost |
|---|---|---|
| Laravel API | Railway service, Singapore region | ~$5/mo with the database |
| MySQL | Railway managed database, same project | included above |
| Uploads | Supabase Storage, two buckets | free tier |
| Admin panel | run locally against the deployed API | free |
| API domain | `*.up.railway.app`, TLS automatic | free |

Supabase supplies **storage only**. Its Postgres is not used, and it cannot host
the API at all — Supabase runs no PHP. Railway stays for both the app and MySQL.

For the alpha demo the admin panel is not hosted: it runs on the presenter's
machine at `http://localhost:3000` and talks to the deployed API, which is why
`ADMIN_FRONTEND_URL` below is a localhost origin. `config/cors.php:22` reads that
variable directly, so no code change is needed to host it later — set the
variable to the real origin and redeploy.

MySQL is deliberately kept. The Postgres move in `cloud-migration-plan.md` was
chosen for Supabase's free bundle, not for any technical need, and it costs an
email case-sensitivity fix plus the pooler/prepared-statement trap — two of that
document's own top-five risks, bought to save a few dollars a month.

---

## 1. Buckets

Two, in one Supabase project, one S3 access key covering both. Create them
under Storage -> Buckets, then take the key from
Project Settings -> Storage -> S3 access keys. That key is **not** the anon or
the service_role key.

- **`serbis-resident-ids` — private.** Government ID scans and resident photos.
  Leave the bucket Private. These are read only through
  `GET /api/service-requests/{id}/valid-id` and `GET /api/residents/{id}/photo`,
  which check ownership and stream the bytes through the API.
- **`serbis-info-materials` — public read.** Evacuation guides and advisories,
  which residents open with no token. Mark the bucket Public and set
  `AWS_PUBLIC_URL` to
  `https://<project-ref>.supabase.co/storage/v1/object/public/serbis-info-materials`.

**Supabase free projects pause after about a week of inactivity, and a paused
project serves no storage.** Uploads and ID-scan reads both fail while it is
down. Open the dashboard and confirm the project is awake before any demo.

They cannot be one bucket. `config/filesystems.php` has separate `s3` and
`s3_public` disks for exactly this reason — the comment there explains what
merging them breaks.

## 2. Environment variables

Set on the Railway service. `${{MySQL.*}}` are Railway reference variables that
resolve to the database service in the same project — use them rather than
pasting a host, so a database rebuild does not silently break the app.

```
APP_NAME=SERBIS
APP_ENV=production
APP_KEY=                      # php artisan key:generate --show, fresh. NEVER the dev key
APP_DEBUG=false               # AppServiceProvider refuses to boot if this is true here
APP_URL=https://<service>.up.railway.app

LOG_CHANNEL=stack
LOG_LEVEL=error               # the example says debug, which is a dev value

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

UPLOADS_PRIVATE_DISK=s3
UPLOADS_PUBLIC_DISK=s3_public
AWS_ACCESS_KEY_ID=<r2 token>
AWS_SECRET_ACCESS_KEY=<r2 secret>
AWS_DEFAULT_REGION=<project region, e.g. ap-southeast-1>
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ENDPOINT=https://<project-ref>.supabase.co/storage/v1/s3
AWS_BUCKET=serbis-resident-ids
AWS_PUBLIC_BUCKET=serbis-info-materials
AWS_PUBLIC_URL=https://<public-bucket-domain>

MAIL_MAILER=log               # fallback only; NOT ses — see the trap below
MAIL_FROM_ADDRESS=noreply@<domain>
MAIL_FROM_NAME=SERBIS

PHILSMS_TOKEN=<token>
PHILSMS_SENDER_ID=PhilSMS     # the shared default; a dedicated id needs telco approval

SANCTUM_ADMIN_EXPIRATION=480
SANCTUM_RESIDENT_EXPIRATION=43200

ADMIN_FRONTEND_URL=http://localhost:3000
```

Deliberately **not** set: `MOBILE_DEV_URL` (only honoured under `APP_ENV=local`)
and `FILESYSTEM_DISK` (the controllers read `filesystems.uploads.*`, never the
default disk).

**Trap: do not use the `ses` mailer.** `config/services.php:25-29` reads
`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` — the same two variables Supabase
Storage now uses. Selecting `ses` would hand Amazon the Supabase keys and fail
confusingly. Use plain SMTP if mail is ever configured.

**The OTP goes by SMS, and mail is only the fallback.**
`AuthController::sendVerificationCode()` texts the code through PhilSMS and
drops to `Mail` only when the resident's number cannot be normalised to a
Philippine E.164 number. So `PHILSMS_TOKEN` is a hard deploy blocker —
without it nobody can complete a signup — while `MAIL_MAILER=log` is merely a
dead fallback. Load PhilSMS credit before the demo: every registration and every
resend costs one message, and there is no sandbox.

## 3. Build and release

Let Nixpacks detect the Laravel app; do not add a Dockerfile unless it fails.
The `Procfile` no longer has a `web:` line for this reason — the old one called
a Heroku buildpack binary this project never installs.

- **Root directory:** `Backend/SERBIS-Backend`
- **Pre-deploy command:** `php artisan config:cache && php artisan route:cache && php artisan migrate --force && php artisan storage:link`
- **Health check path:** `/up`
- **Cron:** `php artisan schedule:run` daily. This runs one thing —
  `sanctum:prune-expired --hours=24`. There are no queued jobs anywhere in
  `app/`, so a full-time worker service would be paid idle time.

If Nixpacks cannot produce PHP 8.4 (`composer.json` requires `^8.4`), that is
the point to write a Dockerfile, and only then.

## 4. Order, with a gate on each step

Do not start a step until the previous gate is proven — observed, not assumed.

1. **Deploy the API.** Gate: `/up` returns 200. A deliberate 500 returns JSON
   with no stack trace, proving `APP_DEBUG=false`.
2. **Database.** `migrate --force` runs in the pre-deploy; seed, then recreate
   the two real test accounts by hand. Gate: admin login returns a token. Also
   confirm the seeders refuse to run under `APP_ENV=production`.
3. **Storage.** Gate — the important one: upload an ID scan, view it in the
   admin panel, then request the Supabase object URL directly with no token and
   **confirm it is refused**. Test it; do not infer it. Then confirm an info
   material opens in a browser with no token.
4. **Admin panel.** Run it locally: `VITE_API_BASE=https://<api>/api npm run dev`
   with `ADMIN_FRONTEND_URL=http://localhost:3000` set on the API.
   Gate: full login-to-logout flow in a real browser. CORS failures appear only
   in browsers, never in curl.
5. **Mobile.** `flutter build apk --release --dart-define=API_BASE_URL=https://<api>/api`.
   HTTPS is mandatory — the release manifest sets `usesCleartextTraffic="false"`
   and the debug-only exemption is not merged into a release build.
   **This cannot be built on either dev machine.** Neither has an Android SDK,
   and `flutter build apk` prints "No Android SDK found" and still exits 0, so a
   backgrounded build reports success having produced nothing. Check the log.
6. **SMS.** One real send to a team handset, and one real registration OTP —
   they are different code paths now. PhilSMS has no sandbox, and whether the
   token is IP-restricted is unknowable until it is called from the cloud host.
   Numbers are normalised by `App\Services\PhilSms::normalize()`; a resident
   whose stored number is not a Philippine mobile is dropped before the send,
   so check the recipient count matches what you expected.
7. **Hygiene.** Turn on Railway database backups.

## 5. What stays local

Development is unchanged. `.env` here still points at local MySQL and the local
disks; the defaults in `config/filesystems.php` are the local disks precisely so
that pulling this work changes nothing on a developer machine.
