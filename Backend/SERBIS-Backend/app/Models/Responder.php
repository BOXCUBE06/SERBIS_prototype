<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Responder extends Model
{
    use HasFactory, TracksHistory;

    public const STATUSES = ['available', 'deployed', 'off_duty'];

    /** The four roles on an MDRRMO response team; nothing else is accepted. */
    public const POSITIONS = ['Team Leader', 'Assistant Leader', 'Logistics', 'Driver'];

    protected $table = 'tbl_responders';

    protected $primaryKey = 'responder_id';

    protected $fillable = [
        'name',
        'contact_no',
        'position',
        'status',
    ];

    // Written only by ResponderController::uploadPhoto() — see Vehicle.php
    // for why a storage path column is kept off $fillable.

    protected $ignoreLogging = ['created_at', 'updated_at'];

    /**
     * Not assignable right now: deployed on a Responding request, or off duty.
     * The one rule assign, dispatch and the pickers' "On trip" flag share.
     * $exceptRequestId spares that request's own crew.
     */
    public function scopeBusy(Builder $query, ?int $exceptRequestId = null): Builder
    {
        return $query->where('status', '!=', 'available')
            ->when($exceptRequestId, fn (Builder $q) => $q->whereDoesntHave(
                'serviceRequests',
                fn (Builder $r) => $r->where('tbl_service_request.request_id', $exceptRequestId),
            ));
    }

    public function serviceRequests(): BelongsToMany
    {
        return $this->belongsToMany(ServiceRequest::class, 'tbl_request_responders', 'responder_id', 'request_id')
            ->withPivot('assigned_at');
    }
}
