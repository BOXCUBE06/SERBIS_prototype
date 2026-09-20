<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message id SkySMS handed back for a blast (`queue_ids` in its bulk reply).
 * It is what the vendor's message endpoints know a message by, so it is what a
 * later delivery lookup has to start from. Separate rows rather than a JSON
 * column so "which blast was queue id N?" is an indexed lookup.
 */
#[Table('tbl_sms_queue_ids', key: 'sms_queue_id')]
#[Fillable(['sms_log_id', 'queue_id'])]
class SmsQueueId extends Model
{
    public function smsLog(): BelongsTo
    {
        return $this->belongsTo(SmsLog::class, 'sms_log_id', 'sms_log_id');
    }
}
