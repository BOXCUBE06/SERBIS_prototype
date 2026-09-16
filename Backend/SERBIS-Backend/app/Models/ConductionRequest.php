<?php

namespace App\Models;

use App\Traits\InvalidatesAnalyticsCache;
use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConductionRequest extends Model
{
    use InvalidatesAnalyticsCache, TracksHistory;

    protected $table = 'tbl_conduction_requests';

    protected $primaryKey = 'conduction_request_id';

    protected $fillable = [
        'service_request_id',
        'vehicle_id',
        'vehicle_override_reason',
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
        'no_arrival_reason',
        'departed_destination_at',
        'returned_office_at',
        'odometer_start',
        'odometer_end',
        'others',
    ];

    /**
     * Plain 'datetime', so these serialise with an offset like every other
     * timestamp the API sends.
     *
     * They used to be format-cast to 'Y-m-d\TH:i:s', which emits no offset. That
     * was a workaround for the column holding the office's wall clock rather
     * than an instant: a browser reads a no-offset string as local time, so what
     * a staffer typed was what came back. ConductionRequestController::tripLog()
     * now converts on the way in and the column holds a real UTC instant, which
     * makes the offset the thing that has to be sent — Flutter parses inbound
     * timestamps with DateTime.tryParse(...).toLocal() and would silently
     * mis-shift a bare string, and the panel hands the value to new Date().
     */
    protected $casts = [
        'departed_office_at' => 'datetime',
        'arrived_destination_at' => 'datetime',
        'departed_destination_at' => 'datetime',
        'returned_office_at' => 'datetime',
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    protected $appends = ['trip_status'];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id', 'request_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }

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
