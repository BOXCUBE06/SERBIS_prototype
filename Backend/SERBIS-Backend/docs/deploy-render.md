# Deploying SERBIS to Render + Aiven + Supabase Storage

**Status:** prepared, not yet executed. Everything below is the plan the repo is
now configured for; nothing has been deployed, and **the Docker image has never
been built** — neither development machine has Docker, so the first
`docker build` happens on Render.

Target shape:

| Piece | Where | Cost |
|---|---|---|
| Laravel API | Render **Docker** web service, Singapore | free plan |
| MySQL 8 | **Aiven** managed service | free / trial tier |
| Uploads | Supabase Storage, two buckets | free tier |
| Scheduler | Render cron job, once daily | per-run |
| Admin panel | run locally against the deployed API | free |
| API domain | `*.onrender.com`, TLS automatic | free |

Three providers, one job each. **Supabase supplies storage only** — its Postgres
is not used and it cannot host the API, because it runs no PHP. **Aiven supplies
MySQL only.** **Render runs the application.**

`render.yaml` at the repository root declares all of it: the web service, the
cron job, and one shared environment group. Applying that blueprint is the whole
setup.

For the alpha demo the admin panel is not hosted: it runs on the presenter's
machine at `http://localhost:3000` and talks to the deployed API, which is why
`ADMIN_FRONTEND_URL` is a localhost origin. `config/cors.php:23` reads that
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

**The S3 driver is a package, and it was missing until `b97daef`.**
`league/flysystem-aws-s3-v3` is now in `composer.json`; without it these disks
resolve to nothing and the first upload fails at runtime, on a path no local run
exercises because development writes to the local disks.

## 2. The database

Aiven MySQL 8. Take the host, port, database name and user from the service
overview — none of them is a Render reference variable, because the database
lives outside Render.

**Aiven requires TLS, and the CA is already in the repository** at
`storage/certs/aiven-ca.pem`, which is
`/var/www/html/storage/certs/aiven-ca.pem` inside the image. That is the value
`render.yaml` gives `MYSQL_ATTR_SSL_CA`. It is a public certificate carrying no
private key; committing it is deliberate.

**Read this before changing that variable.** `config/database.php` reads it
inside an `array_filter`, so an unset, misspelled or wrongly pathed value is
dropped and PDO receives no `ATTR_SSL_CA` at all. The connection then still
succeeds — MySQL negotiates TLS and simply stops verifying who is on the other
end — and nothing in the application will ever tell you. An unverified
connection is indistinguishable from a healthy one, which is why
`docker-entrypoint.sh` stats the file and refuses to boot without it.

The certificate expires **2036-08-19**. Aiven rotates project CAs; if a
connection starts failing verification years from now, that is where to look.

## 3. Environment variables

`render.yaml` declares these in an env group shared by the web service and the
cron job — the cron runs the same image, and the entrypoint refuses to start
without the full set. Values marked `sync: false` there are prompted for on
first apply and are never stored in the repository.

```
APP_NAME=SERBIS
APP_ENV=production
APP_KEY=                      # php artisan key:generate --show, fresh. NEVER the dev key
APP_DEBUG=false               # AppServiceProvider refuses to boot if this is true here
APP_URL=https://<service>.onrender.com

LOG_CHANNEL=stack
LOG_LEVEL=error               # the example file says debug, which is a dev value

DB_CONNECTION=mysql
DB_HOST=<aiven service host>
DB_PORT=<aiven service port>  # not 3306; Aiven assigns one
DB_DATABASE=<aiven database>
DB_USERNAME=<aiven user>
DB_PASSWORD=<aiven password>
MYSQL_ATTR_SSL_CA=/var/www/html/storage/certs/aiven-ca.pem

ADMIN_SEED_PASSWORD=<the first admin password>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

UPLOADS_PRIVATE_DISK=s3
UPLOADS_PUBLIC_DISK=s3_public
AWS_ACCESS_KEY_ID=<supabase s3 key>
AWS_SECRET_ACCESS_KEY=<supabase s3 secret>
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

Deliberately **not** set: `MOBILE_DEV_URL` (only honoured under `APP_ENV=local`),
`FILESYSTEM_DISK` (the controllers read `filesystems.uploads.*`, never the
default disk), and `AWS_URL` — its absence is what stops anything building a
direct link to a government ID scan.

**`ADMIN_SEED_PASSWORD` is what creates the only account that can log in.**
`ProductionAdminSeeder` reads it, creates `jilmarferrer29@gmail.com` with the
role `Admin` and status `Active`, and **skips entirely once that account
exists** — so a redeploy cannot reset a password the admin has since changed.
The container will not start without the variable set. Change the password in
the panel after the first login; the variable is then only historical.

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

## 4. Build and release

**Render runs Docker here, not Nixpacks and not a buildpack.** `Dockerfile` and
`docker-entrypoint.sh` live in `Backend/SERBIS-Backend/`, which `render.yaml`
sets as `rootDir`.

**`Procfile` is not read by Render at all.** Its `release:` line is dead on this
platform; everything it did now happens in the entrypoint, which also seeds —
something the release line never did.

The entrypoint runs on **every** container start, in this order, aborting the
boot on the first failure rather than serving a half-provisioned database:

1. **Validate the environment.** `APP_KEY`, every `DB_*`,
   `ADMIN_SEED_PASSWORD`, `MYSQL_ATTR_SSL_CA` — plus a `stat` of the CA file
   itself. A missing variable exits non-zero naming that variable.
2. `php artisan config:cache && php artisan route:cache`
3. `php artisan migrate --force`
4. `php artisan db:seed --class=ProductionSeeder --force`
5. `exec apache2-foreground`, after writing Render's `$PORT` into Apache's
   config.

All of it is safe to repeat: `migrate` is a no-op once applied, and every seeder
`ProductionSeeder` calls returns early on a non-empty table.

**`migrate` is deliberately not `--isolated`.** That flag takes its lock through
the cache store, `CACHE_STORE` is `database`, and the `cache` table does not
exist until that very command creates it — so on a first deploy `--isolated`
fails on precisely the empty database it was added to protect.

**No Node stage.** Nothing in the app calls `@vite` any more: `routes/web.php`
answers `/` with JSON and the only Blade file left is the verification e-mail.

**No `storage:link`.** Uploads go to Supabase; `UPLOADS_PUBLIC_DISK` is
`s3_public`, so the symlink would serve nothing.

- **Health check path:** `/up` — Laravel's own endpoint from
  `bootstrap/app.php`. It touches no view and no database, so it still answers
  while something else is broken.
- **Cron, not a worker.** `routes/console.php` schedules one command,
  `sanctum:prune-expired --hours=24`, and `app/` dispatches no queued jobs at
  all, so a full-time worker service would be paid idle time. The cron service
  in `render.yaml` runs `schedule:run` daily at 18:00 UTC (02:00 Manila).
  Note that `dockerCommand` overrides `CMD` but **not** `ENTRYPOINT`: each
  nightly run walks the boot sequence above before reaching `schedule:run`. Every
  step is a no-op on a provisioned database, but the job will fail loudly if the
  database is unreachable at that hour.

**The free web service spins down when idle** and the next request pays a cold
start. Wake it before a demo rather than discovering this in front of the panel.

## 5. Order, with a gate on each step

Do not start a step until the previous gate is proven — observed, not assumed.

1. **Deploy the API.** Gate: `/up` returns 200. A deliberate 500 returns JSON
   with no stack trace, proving `APP_DEBUG=false`.
2. **Database.** `migrate --force` and `db:seed --class=ProductionSeeder` both
   run in the entrypoint, so a successful boot has already done them. Gate:
   admin login returns a token, and the service catalogue has ten rows with
   their codes. Also confirm the six fixture seeders are absent from production
   data — no seeded residents, no fabricated requests.
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
7. **Hygiene.** Turn on Aiven backups.

## 6. What stays local

Development is unchanged. `.env` here still points at local MySQL and the local
disks; the defaults in `config/filesystems.php` are the local disks precisely so
that pulling this work changes nothing on a developer machine.
