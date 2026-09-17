<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1:1 extension of ServiceRequest, keyed by the same request_id: ambulance's
 * patient and scheduling columns, moved off the shared table. Every
 * ambulance request has exactly one row here; no other service does.
 */
#[Table('tbl_ambulance_bookings', key: 'request_id', incrementing: false)]
#[Fillable(['request_id', 'patient_name', 'patient_age', 'patient_address', 'patient_contact_number', 'pickup_location', 'destination', 'condition_notes', 'scheduled_at', 'scheduled_end', 'approved_at'])]
class AmbulanceBooking extends Model
{
    use TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'scheduled_end' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id', 'request_id');
    }
}
