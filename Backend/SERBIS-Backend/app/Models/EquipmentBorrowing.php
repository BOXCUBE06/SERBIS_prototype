<?php

// App\Models\EquipmentBorrowing.php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentBorrowing extends Model
{
    use TracksHistory;

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
        'status',
        'denial_reason',
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
        'released_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id', 'equipment_id');
    }
}
