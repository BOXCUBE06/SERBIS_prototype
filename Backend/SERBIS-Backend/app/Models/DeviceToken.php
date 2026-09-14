<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per device a resident has registered for push notifications. */
#[Table('tbl_device_tokens')]
#[Fillable(['resident_id', 'token', 'platform', 'last_seen_at'])]
class DeviceToken extends Model
{
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }
}
