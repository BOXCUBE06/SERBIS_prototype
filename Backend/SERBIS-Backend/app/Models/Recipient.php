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

    /**
     * What is known about one message. QUEUED is what a blast is recorded as
     * when SkySMS accepts it: taken and billed, delivery not confirmed. PENDING
     * and SENT come only from SkySMS's own message list. OTHER is a status the
     * vendor sent that this code does not know, kept apart and never counted as
     * delivered. UNCONFIRMED is a request that timed out.
     */
    public const QUEUED = 'Queued';

    public const PENDING = 'Pending';

    public const SENT = 'Sent';

    public const FAILED = 'Failed';

    public const UNCONFIRMED = 'Unconfirmed';

    public const OTHER = 'Other';

    /** The vendor's status word as one of the states above. */
    public static function fromVendor(mixed $status): string
    {
        return match (is_string($status) ? strtolower(trim($status)) : '') {
            'queued' => self::QUEUED,
            'pending' => self::PENDING,
            'sent' => self::SENT,
            'failed' => self::FAILED,
            default => self::OTHER,
        };
    }

    public function smsLog(): BelongsTo
    {
        return $this->belongsTo(SmsLog::class, 'sms_log_id', 'sms_log_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }
}
