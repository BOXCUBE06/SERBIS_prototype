<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TracksHistory;

#[Table('tbl_service_request', key: 'request_id')]
#[Fillable(['resident_id', 'walk_in_name', 'walk_in_contact_number', 'service_id', 'processed_by', 'description', 'valid_id', 'site_photo', 'status', 'remarks', 'vehicle_id'])]
#[Hidden(['valid_id', 'site_photo'])]
#[Appends(['has_valid_id', 'has_site_photo'])]
class ServiceRequest extends Model
{
    use HasFactory, TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    /**
     * The `valid_id` storage path is hidden so it never reaches a client; the image
     * is served only by GET /api/service-requests/{id}/valid-id. Clients use this
     * flag to decide whether to fetch it.
     */
    public function getHasValidIdAttribute(): bool
    {
        return ! empty($this->valid_id);
    }

    /**
     * The site photo is hidden for the same reason as `valid_id`: the column is
     * a storage path, and a path handed to a client is a path a client can ask
     * for. It is served by GET /api/service-requests/{id}/site-photo.
     */
    public function getHasSitePhotoAttribute(): bool
    {
        return ! empty($this->site_photo);
    }

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