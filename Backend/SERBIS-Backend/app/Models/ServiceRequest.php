<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TracksHistory;

#[Table('tbl_service_request', key: 'request_id')]
#[Fillable(['resident_id', 'walk_in_name', 'walk_in_contact_number', 'service_id', 'processed_by', 'description', 'patient_name', 'patient_age', 'patient_sex', 'patient_address', 'patient_contact_number', 'pickup_location', 'destination', 'condition_notes', 'valid_id', 'site_photo', 'status', 'remarks', 'internal_notes', 'vehicle_id', 'scheduled_at', 'scheduled_end', 'approved_at'])]
#[Hidden(['valid_id', 'site_photo'])]
#[Appends(['has_valid_id', 'has_site_photo'])]
class ServiceRequest extends Model
{
    use HasFactory, TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'scheduled_end' => 'datetime',
        'approved_at' => 'datetime',
    ];

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

    /** The trip log(s) filed against this booking. Nothing enforces one-per-request at the schema level. */
    public function conductionRequests(): HasMany
    {
        return $this->hasMany(ConductionRequest::class, 'service_request_id', 'request_id');
    }

    /**
     * Relatives named at intake, before any trip record exists. Copied into
     * the trip's own people table when one is created; the two are separate
     * facts and both are kept.
     */
    public function relatives(): HasMany
    {
        return $this->hasMany(ServiceRequestRelative::class, 'service_request_id', 'request_id')
            ->orderBy('position');
    }
}