<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * One place to invalidate every cached analytics payload.
 *
 * CACHE_STORE is `database` (config/cache.php, and the entrypoint depends on
 * it — see the --isolated note in docker-entrypoint.sh). The database store
 * supports neither tags nor pattern deletes, so there is no way to sweep
 * "every key starting with analytics:". A version counter is the standard
 * substitute: analytics keys embed the current version, and bumping it
 * strands every key built under the old one, which then expires on its own
 * TTL.
 *
 * The dashboard key predates this and is a single fixed string, so it is
 * simply forgotten rather than versioned.
 *
 * Before this existed the dashboard was TTL-only: a status change took up to
 * five minutes to reach the panel, with nothing on screen saying the number
 * was stale.
 */
class AnalyticsCache
{
    public const DASHBOARD_KEY = 'analytics:dashboard';

    public const TTL_SECONDS = 300;

    private const VERSION_KEY = 'analytics:version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * Builds a versioned key. Every analytics cache entry must go through
     * this or flush() will not reach it.
     */
    public static function key(string $suffix): string
    {
        return 'analytics:v'.self::version().':'.$suffix;
    }

    public static function flush(): void
    {
        // Read-then-write rather than Cache::increment(), because the two
        // stores this app runs on disagree about a missing key:
        // DatabaseStore::increment() updates an existing row and returns
        // false when there is none, while ArrayStore::increment() creates it
        // and returns the increment. Production is `database` and the test
        // suite is `array` (phpunit.xml), so anything branching on that
        // return value behaves differently in the two places — a seeding
        // branch written for the database store is dead under the array
        // store and so could never be proven by a test.
        //
        // Losing an update to a concurrent flush is harmless here: both
        // writers still move the version off the value the stale keys were
        // built under, which is the only thing invalidation needs. The
        // counter is monotonic, so no key is ever reachable again.
        //
        // forever(), not put(): were the counter to expire while a versioned
        // payload was still alive, the version would roll back onto keys that
        // already exist and serve those stale numbers again.
        Cache::forever(self::VERSION_KEY, self::version() + 1);

        Cache::forget(self::DASHBOARD_KEY);
    }
}
