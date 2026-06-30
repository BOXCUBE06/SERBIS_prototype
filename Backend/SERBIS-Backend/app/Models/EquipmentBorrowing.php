<?php

// App\Models\EquipmentBorrowing.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TracksHistory;

class EquipmentBorrowing extends Model
{
    use TracksHistory;
    protected $table = 'tbl_equipment_borrowing';
    protected $primaryKey = 'borrow_id';

    protected $fillable = [
        'resident_id',
        'equipment_id',
        'quantity',
        'status',
        'released_at',
        'returned_at'
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
