<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    public function serviceRequests(): BelongsToMany
    {
        return $this->belongsToMany(ServiceRequest::class, 'tbl_request_responders', 'responder_id', 'request_id')
            ->withPivot('assigned_at');
    }
}
