<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TracksHistory;

#[Table('tbl_service_request', key: 'request_id')]
#[Fillable(['resident_id', 'service_id', 'processed_by', 'description', 'valid_id', 'status', 'remarks', 'vehicle_id'])]
class ServiceRequest extends Model
{
    use HasFactory, TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by', 'admin_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }
}