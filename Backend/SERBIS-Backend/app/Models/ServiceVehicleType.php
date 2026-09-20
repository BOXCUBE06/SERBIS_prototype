<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One row: a unit of this type may be assigned to this service. See the
 * migration for why it is keyed on the service code and what an empty set means.
 */
#[Table('tbl_service_vehicle_types', key: 'mapping_id')]
#[Fillable(['service_code', 'vehicle_type'])]
class ServiceVehicleType extends Model
{
    use TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    /** The types that can be mapped: every kind but Ambulance, which has its own fixed rule. */
    public static function mappableTypes(): array
    {
        return array_values(array_diff(Vehicle::TYPES, ['Ambulance']));
    }

    /**
     * The unit types allowed on [code]. An empty list means no restriction: any
     * non-ambulance unit.
     *
     * @return list<string>
     */
    public static function typesFor(string $code): array
    {
        return array_values(static::where('service_code', $code)->pluck('vehicle_type')->all());
    }
}
