<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient relative named on the request at intake, before any trip record
 * exists to hang them off. Copied into tbl_conduction_request_people with
 * role='relative' when the trip is created — see
 * ServiceRequestController::copyRelativesToTrip().
 */
class ServiceRequestRelative extends Model
{
    protected $table = 'tbl_service_request_relatives';

    protected $fillable = [
        'service_request_id',
        'name',
        'position',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id', 'request_id');
    }
}
