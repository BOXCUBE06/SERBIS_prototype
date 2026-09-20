<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per barangay per blast. The vendor is called once for all recipients,
 * but the record is per target area because that is the unit a resident reads:
 * "what was sent to my barangay".
 */
#[Table('tbl_sms_logs', key: 'sms_log_id')]
#[Fillable(['sender_id', 'target_area_id', 'api_job_id', 'message_body', 'status', 'delivery_checked_at'])]
class SmsLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['delivery_checked_at' => 'datetime'];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'target_area_id', 'barangay_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id', 'admin_id');
    }

    public function queueIds(): HasMany
    {
        return $this->hasMany(SmsQueueId::class, 'sms_log_id', 'sms_log_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'sms_log_id', 'sms_log_id');
    }
}
