<?php

namespace Database\Factories;

use App\Models\AmbulanceBooking;
use App\Models\Service;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * A scheduled ambulance booking, walk-in style (no resident_id) since these
 * exist purely to give the availability calendar something to render.
 *
 * `service_id` and `vehicle_id` are not defaulted — every caller supplies
 * both, the same way a real booking always will once the writer for it
 * exists (phase 5).
 */
class ServiceRequestFactory extends Factory
{
    protected $model = ServiceRequest::class;

    /**
     * These 11 columns do not exist on tbl_service_request any more — they
     * never reach ServiceRequest::create() at all, not even transiently.
     * Every source that used to hand them to this factory (definition()'s
     * own random default, onDay(), unscheduled(), an explicit override) now
     * carries its values to afterCreating() as a plain closure variable
     * instead, and applyBookingOverrides() is the one place any of them
     * actually reach AmbulanceBooking.
     */
    private const BOOKING_FIELDS = [
        'patient_name', 'patient_age', 'patient_sex', 'patient_address',
        'patient_contact_number', 'pickup_location', 'destination', 'condition_notes',
        'scheduled_at', 'scheduled_end', 'approved_at',
    ];

    /**
     * Keeps the one-booking-row-per-ambulance-request invariant for every
     * row this factory creates — mirrors what the migration's backfill and
     * the controllers themselves maintain. Resolved by code, never
     * hardcoded, same as everywhere else this lookup happens.
     *
     * Unconditional: every ambulance row gets a booking with a default
     * random schedule, which onDay()/unscheduled()/an explicit override
     * then update — see the class doc comment on BOOKING_FIELDS.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ServiceRequest $serviceRequest) {
            $start = Carbon::now('Asia/Manila')
                ->addDays(fake()->numberBetween(0, 13))
                ->setTime(fake()->numberBetween(7, 15), 0, 0)
                ->utc();

            self::applyBookingOverrides($serviceRequest, [
                'scheduled_at' => $start,
                'scheduled_end' => $start->copy()->addHours(2),
            ]);
        });
    }

    /**
     * Intercepts any of the 11 booking-only keys out of an explicit
     * ->create([...]) call before they ever reach ServiceRequest — they are
     * not fillable there, and even if they were, that table no longer has
     * the columns. Carried to afterCreating() instead, same as
     * onDay()/unscheduled() below; registered last, so an explicit override
     * always wins over both the random default and any chained state.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        // The callable form (a per-model attribute resolver) is not used
        // anywhere in this codebase — only a plain array of overrides, or
        // none. Nothing to intercept for the callable form, so it passes
        // through unchanged.
        if (! is_array($attributes)) {
            return parent::create($attributes, $parent);
        }

        $bookingOverrides = Arr::only($attributes, self::BOOKING_FIELDS);

        if (! $bookingOverrides) {
            return parent::create($attributes, $parent);
        }

        return $this->afterCreating(function (ServiceRequest $serviceRequest) use ($bookingOverrides) {
            self::applyBookingOverrides($serviceRequest, $bookingOverrides);
        })->create(Arr::except($attributes, self::BOOKING_FIELDS), $parent);
    }

    /** Only an ambulance request ever gets a booking row — same invariant the controllers enforce. */
    private static function applyBookingOverrides(ServiceRequest $serviceRequest, array $overrides): void
    {
        $ambulanceServiceId = Service::where('code', 'ambulance-medical-response')->value('service_id');

        if ($ambulanceServiceId === null || (int) $serviceRequest->service_id !== $ambulanceServiceId) {
            return;
        }

        AmbulanceBooking::updateOrCreate(['request_id' => $serviceRequest->request_id], $overrides);
    }

    public function definition(): array
    {
        return [
            'walk_in_name' => 'MDRRMO Desk Booking',
            'walk_in_contact_number' => '09170000000',
            'description' => 'Scheduled ambulance transport',
            'status' => 'Booked',
        ];
    }

    /**
     * `$dayOffset` days from now, at `$hour`:00 Manila time, converted to the
     * UTC instant the column actually stores — the same conversion
     * ConductionRequestController::tripLog() applies to a staff-typed
     * checkpoint. A 2-hour window, matching the default `scheduled_end`
     * described in the migration that added the column.
     */
    public function onDay(int $dayOffset, int $hour): static
    {
        $start = Carbon::now('Asia/Manila')
            ->addDays($dayOffset)
            ->setTime($hour, 0, 0)
            ->utc();

        return $this->afterCreating(function (ServiceRequest $serviceRequest) use ($start) {
            self::applyBookingOverrides($serviceRequest, [
                'scheduled_at' => $start,
                'scheduled_end' => $start->copy()->addHours(2),
            ]);
        });
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'Cancelled']);
    }

    public function disapproved(): static
    {
        return $this->state(['status' => 'Disapproved']);
    }

    public function unscheduled(): static
    {
        return $this->afterCreating(function (ServiceRequest $serviceRequest) {
            self::applyBookingOverrides($serviceRequest, ['scheduled_at' => null, 'scheduled_end' => null]);
        });
    }
}
