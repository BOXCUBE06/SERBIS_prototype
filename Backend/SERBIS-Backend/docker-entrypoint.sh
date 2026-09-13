#!/usr/bin/env bash
#
# Boot sequence for the SERBIS API container.
#
# Safe to re-run: this executes on every container start, including a restart
# against a database that is already provisioned. config:cache and route:cache
# overwrite their own files, migrate --force is a no-op once applied, and every
# seeder ProductionSeeder calls returns early on a non-empty table.
#
# Nothing here is allowed to fail quietly. `set -e` aborts the boot on the first
# non-zero exit, so the server is never started against a half-provisioned
# database — a container answering 200 with a half-migrated schema is far harder
# to notice than one that refuses to start.
set -euo pipefail

fail() {
    echo "docker-entrypoint: $*" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# 1. Environment. Checked before anything touches the database, and each
#    failure names the variable: "DB_PASSWORD is not set" is actionable in the
#    Render dashboard, a PDO connection refusal is not.
#
#    DB_CONNECTION is deliberately not required — config/database.php defaults
#    it to mysql. MYSQL_ATTR_SSL_CA is deliberately not required either: Aiven
#    mandated TLS and shipped a CA for it, but Railway's MySQL is reached over
#    the private network within the same project, which needs no TLS at all.
#    config/database.php already tolerates this being unset (array_filter
#    drops it, PDO gets no ATTR_SSL_CA option) — see the conditional check
#    below for what still applies when a host does need it.
# ---------------------------------------------------------------------------
for var in \
    APP_KEY \
    DB_HOST \
    DB_PORT \
    DB_DATABASE \
    DB_USERNAME \
    DB_PASSWORD \
    ADMIN_SEED_PASSWORD
do
    if [ -z "${!var:-}" ]; then
        fail "$var is not set. Set it on the Render service and redeploy."
    fi
done

# Only checked when MYSQL_ATTR_SSL_CA is actually set — a host that requires
# TLS (Aiven did) still gets the same guard against a wrong or unreadable path.
# The CA is checked as a file, not merely as a non-empty string, because of how
# config/database.php reads it: the path goes through array_filter, so a value
# that is present but wrong yields a connection with no ATTR_SSL_CA at all.
# MySQL still negotiates TLS in that case — it simply stops verifying who is on
# the other end. That failure looks exactly like a working deployment, which is
# why it is caught here instead of in production.
if [ -n "${MYSQL_ATTR_SSL_CA:-}" ]; then
    [ -f "$MYSQL_ATTR_SSL_CA" ] \
        || fail "MYSQL_ATTR_SSL_CA points at $MYSQL_ATTR_SSL_CA, which is not a file. Without a readable CA the database connection is unverified."
    [ -r "$MYSQL_ATTR_SSL_CA" ] \
        || fail "MYSQL_ATTR_SSL_CA points at $MYSQL_ATTR_SSL_CA, which $(id -un) cannot read. Without a readable CA the database connection is unverified."
fi

# ---------------------------------------------------------------------------
# 2. Caches. Compiled first so that migrate and db:seed below run against the
#    same resolved configuration the web process will use.
#
#    One consequence worth stating: once a config cache exists Laravel stops
#    loading .env at all (LoadEnvironmentVariables returns early), so every
#    env() call from here on reads the real process environment. That is exactly
#    what Render supplies, and it is why ProductionAdminSeeder's
#    env('ADMIN_SEED_PASSWORD') still resolves after this line.
# ---------------------------------------------------------------------------
php artisan config:cache
php artisan route:cache

# ---------------------------------------------------------------------------
# 3. Schema. Not --isolated: that flag takes its lock through the cache store,
#    CACHE_STORE is database, and the `cache` table does not exist until this
#    very command creates it. On a first deploy --isolated fails on precisely
#    the empty database it was added to protect.
# ---------------------------------------------------------------------------
php artisan migrate --force

# ---------------------------------------------------------------------------
# 4. Reference data and the admin account. ProductionSeeder — never
#    DatabaseSeeder, which seeds fabricated residents, requests and borrowings.
# ---------------------------------------------------------------------------
php artisan db:seed --class=ProductionSeeder --force

# ---------------------------------------------------------------------------
# 5. Serve. Apache learns the port only now: PORT is assigned by the platform at
#    run time and is not knowable when the image is built.
# ---------------------------------------------------------------------------
port="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${port}>!" /etc/apache2/sites-available/000-default.conf

exec "$@"
