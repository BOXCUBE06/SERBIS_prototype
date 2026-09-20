<?php

// App\Models\EquipmentBorrowing.php

namespace App\Models;

use App\Traits\InvalidatesAnalyticsCache;
use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// The two handover columns hold storage paths on the private disk, written
// only by POST /borrowings/{id}/photo. A path handed to a client is a path a
// client can ask for, so they are hidden for the same reason `valid_id` and
// `site_photo` are on ServiceRequest: clients get a boolean and fetch the image
// itself from GET /borrowings/{id}/photo/{stage}, which checks ownership first.
#[Hidden(['release_photo_path', 'return_photo_path'])]
#[Appends(['has_release_photo', 'has_return_photo'])]
class EquipmentBorrowing extends Model
{
    use InvalidatesAnalyticsCache, TracksHistory;

    protected $table = 'tbl_equipment_borrowing';

    protected $primaryKey = 'borrow_id';

    protected $fillable = [
        'resident_id',
        'equipment_id',
        'other_equipment_text',
        'quantity',
        'purpose',
        'fulfillment_method',
        'delivery_address',
        'borrower_type',
        'organization_name',
        'due_date',
        'return_reminder_sent_at',
        'status',
        'denial_reason',
        'denial_reason_code',
        'availability_reconfirm_sent_at',
        'return_condition_note',
        'return_condition',
        'released_at',
        'returned_at',
    ];

    /**
     * Without the format, a date column serialises with a time and a timezone
     * the column does not actually store, and the panel would have to strip it
     * back off before comparing against today.
     */
    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'return_reminder_sent_at' => 'datetime',
        'availability_reconfirm_sent_at' => 'datetime',
        'released_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    /**
     * Whether staff photographed the item as it left the building. Both the
     * panel and the app key off these rather than the paths — see the note
     * above the class.
     */
    public function getHasReleasePhotoAttribute(): bool
    {
        return ! empty($this->release_photo_path);
    }

    public function getHasReturnPhotoAttribute(): bool
    {
        return ! empty($this->return_photo_path);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id', 'equipment_id');
    }
}
