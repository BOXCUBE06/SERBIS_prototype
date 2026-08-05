<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a blast actually went to. Kept per resident rather than as a count so a
 * "did I get the warning?" question has an answer, and so the advisory feed can
 * be scoped to the residents who were really included — the recipient query
 * excludes Inactive accounts and blank phone numbers, so barangay membership
 * alone is not the same thing.
 */
#[Table('tbl_recipients', key: 'recipient_id')]
#[Fillable(['sms_log_id', 'resident_id', 'status'])]
class Recipient extends Model
{
    use HasFactory;

    public function smsLog(): BelongsTo
    {
        return $this->belongsTo(SmsLog::class, 'sms_log_id', 'sms_log_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }
}
