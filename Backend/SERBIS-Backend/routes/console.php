<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A token stops being accepted the moment it expires; this only clears the dead
// rows, which accumulate for the first time now that tokens expire at all
// (audit #30). --hours=24 means "already expired for a day", so pruning can
// never be what ends a live session — it is housekeeping, not enforcement.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
