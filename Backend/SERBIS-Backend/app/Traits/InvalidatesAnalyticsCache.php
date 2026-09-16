<?php

namespace App\Traits;

use App\Support\AnalyticsCache;

/**
 * Drops the cached dashboard and analytics payloads whenever a model whose
 * rows they count is written.
 *
 * Applied to every model the two endpoints aggregate: ServiceRequest,
 * EquipmentBorrowing, ConductionRequest, Vehicle, Resident, Equipment,
 * Service and Barangay.
 *
 * SystemLog is deliberately NOT in that set. TracksHistory writes a log row
 * as a side effect of a save on one of the models above, so the parent's own
 * flush has already fired by then; adding it here would double every
 * invalidation and put a second write on the cache table for every audit row.
 *
 * Model events only — a raw DB::table() write bypasses this entirely and has
 * to call AnalyticsCache::flush() itself. PurgeRetiredServices is the one
 * such caller today.
 *
 * @method static created(\Closure $callback)
 * @method static updated(\Closure $callback)
 * @method static deleted(\Closure $callback)
 */
trait InvalidatesAnalyticsCache
{
    public static function bootInvalidatesAnalyticsCache(): void
    {
        static::created(static fn () => AnalyticsCache::flush());
        static::updated(static fn () => AnalyticsCache::flush());
        static::deleted(static fn () => AnalyticsCache::flush());
    }
}
