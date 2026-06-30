<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TracksHistory;

class Vehicle extends Model
{
    use HasFactory, TracksHistory;

    protected $table = 'tbl_vehicles';
    protected $primaryKey = 'vehicle_id';

    protected $fillable = [
        'unit_identifier',
        'type',
        'specification',
        'status'
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'vehicle_id', 'vehicle_id');
    }
}
