<?php

namespace App\Models;

use App\Traits\InvalidatesAnalyticsCache;
use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory, InvalidatesAnalyticsCache, TracksHistory;

    /**
     * The kinds of unit the fleet can hold. `type` is a plain string column, so
     * adding a kind is a change here, in VehicleController's rules through this
     * constant, and in the panel's Fleet form — not a migration.
     */
    public const TYPES = ['Ambulance', 'Rescue Vehicle', 'Fire Truck', 'Boat'];

    protected $table = 'tbl_vehicles';

    protected $primaryKey = 'vehicle_id';

    protected $fillable = [
        'unit_identifier',
        'type',
        'specification',
        'status',
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'vehicle_id', 'vehicle_id');
    }
}
