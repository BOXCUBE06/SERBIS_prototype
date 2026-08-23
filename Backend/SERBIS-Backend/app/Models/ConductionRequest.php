<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TracksHistory;

class ConductionRequest extends Model
{
    use TracksHistory;

    protected $table = 'tbl_conduction_requests';
    protected $primaryKey = 'conduction_request_id';

    protected $fillable = [
        'patient_name',
        'patient_age',
        'patient_address',
        'patient_sex',
        'patient_contact_number',
        'vehicle',
        'medical_diagnosis',
        'plate_no',
        'origin',
        'destination',
        'departed_office_at',
        'arrived_destination_at',
        'departed_destination_at',
        'returned_office_at',
        'odometer_start',
        'odometer_end',
        'others',
    ];

    /**
     * Format-cast, not a bare 'datetime'. These four are wall-clock times an
     * MDRRMO staffer typed into a plain <input type="datetime-local">, with
     * no timezone attached — the office runs on Manila time and never enters
     * anything else. A bare 'datetime' cast serialises with a 'Z' (app.timezone
     * is UTC), which tells a browser "this is a UTC instant" and shifts a
     * typed 09:00 to 5:00 PM on render. The 'T', no-offset format here is
     * parsed by JS as local time, so what was typed is what comes back.
     */
    protected $casts = [
        'departed_office_at' => 'datetime:Y-m-d\TH:i:s',
        'arrived_destination_at' => 'datetime:Y-m-d\TH:i:s',
        'departed_destination_at' => 'datetime:Y-m-d\TH:i:s',
        'returned_office_at' => 'datetime:Y-m-d\TH:i:s',
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    protected $appends = ['trip_status'];

    public function people(): HasMany
    {
        return $this->hasMany(ConductionRequestPerson::class, 'conduction_request_id', 'conduction_request_id')
            ->orderBy('position');
    }

    public function drivers(): HasMany
    {
        return $this->people()->where('role', 'driver');
    }

    public function authorizedPassengers(): HasMany
    {
        return $this->people()->where('role', 'passenger');
    }

    public function patientRelatives(): HasMany
    {
        return $this->people()->where('role', 'relative');
    }

    /**
     * Not a stored column. The trip log is nullable-all-the-way and the panel
     * needs a single word to badge the row with, rather than re-deriving this
     * chain of null checks in every view that lists these.
     */
    public function getTripStatusAttribute(): string
    {
        if ($this->returned_office_at) {
            return 'Completed';
        }
        if ($this->departed_office_at) {
            return 'In transit';
        }
        return 'Not dispatched';
    }
}
