<?php

namespace App\Models;

use App\Traits\InvalidatesAnalyticsCache;
use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('tbl_service_request', key: 'request_id')]
#[Fillable(['resident_id', 'walk_in_name', 'walk_in_contact_number', 'service_id', 'processed_by', 'description', 'valid_id', 'site_photo', 'landmark', 'status', 'remarks', 'internal_notes', 'vehicle_id'])]
#[Hidden(['valid_id', 'site_photo'])]
#[Appends(['has_valid_id', 'has_site_photo'])]
class ServiceRequest extends Model
{
    use HasFactory, InvalidatesAnalyticsCache, TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    /**
     * Statuses that mean the office has answered a request that was waiting
     * for an answer. 'Cancelled' is deliberately absent: cancel() is guarded
     * by scopeToOwner and is the resident withdrawing their own request, not
     * the office responding to it.
     */
    public const RESPONSE_STATUSES = ['Booked', 'Responding', 'Disapproved'];

    /**
     * Statuses that end the request. ServiceRequestController aliases this
     * rather than keeping a second copy, and App\Services\AmbulanceAvailability
     * reads it through that alias, so all three agree by construction.
     */
    public const TERMINAL_STATUSES = ['Resolved', 'Cancelled', 'Disapproved'];

    /**
     * Not stale leftovers: tbl_service_request no longer has these columns,
     * but a query that joins tbl_ambulance_bookings and selects
     * scheduled_at/scheduled_end/approved_at under their plain names (the
     * Maintenance/delete guards in VehicleController, the availability
     * calendar) still hydrates them onto a ServiceRequest instance, and
     * still needs them cast to Carbon when it does.
     */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'scheduled_end' => 'datetime',
        'approved_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Stamps the lifecycle timestamps as the status moves. One place, so
     * every write path agrees — update(), cancel() and the automatic
     * Booked -> Responding flip in ConductionRequestController all reach this
     * through the same model event.
     *
     * Two rules, both of which exist to keep the numbers honest rather than
     * flattering:
     *
     * 1. first_responded_at is only stamped on a transition OUT OF Pending.
     *    Pending is the only status in which a request is actually waiting
     *    for the office. A request that was created already Booked
     *    (store()/adminStore() both write that for a scheduled booking) never
     *    waited, so it keeps a null here forever — and a later Booked ->
     *    Responding is the trip starting on its appointed day, not the office
     *    answering, so timing it from created_at would report the lead time
     *    to the appointment as if it were staff delay.
     *
     * 2. Neither column is ever overwritten. "First" response means first.
     *
     * Registered on `updating` rather than `updated` so both columns go out
     * in the same UPDATE as the status itself; stamping afterwards would need
     * a second save and would recurse through this same event.
     */
    protected static function booted(): void
    {
        static::updating(function (ServiceRequest $request): void {
            if (! $request->isDirty('status')) {
                return;
            }

            $request->stampLifecycle($request->getOriginal('status'), $request->status);
        });
    }

    /**
     * Public and explicit about both ends of the transition so a write path
     * that ever bypasses model events can apply the identical rules. Sets
     * attributes only — the caller decides when to persist.
     */
    public function stampLifecycle(?string $from, ?string $to): void
    {
        if ($this->first_responded_at === null
            && $from === 'Pending'
            && in_array($to, self::RESPONSE_STATUSES, true)
        ) {
            $this->first_responded_at = now();
        }

        if ($this->resolved_at === null && in_array($to, self::TERMINAL_STATUSES, true)) {
            $this->resolved_at = now();
        }
    }

    /**
     * Always loaded, on every query this model builds — find(), get(), where(),
     * fresh(), all of it — so no caller has to remember to ask for it and no
     * endpoint can forget. toArray() below depends on this: it is what keeps
     * a stray lazy-load (create()'d instances, or anything $with doesn't
     * reach) as the exception rather than the only path.
     */
    protected $with = ['ambulanceBooking'];

    /**
     * Columns that now live on ambulanceBooking instead of this table. Kept
     * flat in the API response regardless — a client must not see a
     * different shape depending on which table happens to back a field.
     */
    private const BOOKING_FIELDS = [
        'patient_name', 'patient_age', 'patient_address',
        'patient_contact_number', 'pickup_location', 'destination', 'condition_notes',
        'scheduled_at', 'scheduled_end', 'approved_at',
    ];

    /**
     * Flattens ambulanceBooking's columns back into this array — booking
     * values when a booking row exists, null otherwise — and hides the
     * nested `ambulance_booking` key so the shape a client sees never
     * changes. Unconditional, not gated on relationLoaded(): a lazy load on
     * $this->ambulanceBooking here is the safety net for the one place that
     * does not go through $with (a just-create()'d instance), not something
     * every call site has to get right.
     */
    public function toArray()
    {
        $array = parent::toArray();

        $booking = $this->ambulanceBooking;

        foreach (self::BOOKING_FIELDS as $field) {
            $array[$field] = $booking?->{$field};
        }

        unset($array['ambulance_booking']);

        return $array;
    }

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

    /** Ambulance's own patient/scheduling columns. Only present for an ambulance request. */
    public function ambulanceBooking(): HasOne
    {
        return $this->hasOne(AmbulanceBooking::class, 'request_id', 'request_id');
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
