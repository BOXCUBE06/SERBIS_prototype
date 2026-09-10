<?php

namespace Database\Factories;

use App\Models\AmbulanceBooking;
use App\Models\Service;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

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
     * Keeps the one-booking-row-per-ambulance-request invariant for every
     * row this factory creates — mirrors what the migration's backfill and
     * (from phase 4 on) the controllers themselves maintain. Resolved by
     * code, never hardcoded, same as everywhere else this lookup happens.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ServiceRequest $serviceRequest) {
            $ambulanceServiceId = Service::where('code', 'ambulance-medical-response')->value('service_id');

            if ($ambulanceServiceId === null || (int) $serviceRequest->service_id !== $ambulanceServiceId) {
                return;
            }

            AmbulanceBooking::create([
                'request_id' => $serviceRequest->request_id,
                'patient_name' => $serviceRequest->patient_name,
                'patient_age' => $serviceRequest->patient_age,
                'patient_sex' => $serviceRequest->patient_sex,
                'patient_address' => $serviceRequest->patient_address,
                'patient_contact_number' => $serviceRequest->patient_contact_number,
                'pickup_location' => $serviceRequest->pickup_location,
                'destination' => $serviceRequest->destination,
                'condition_notes' => $serviceRequest->condition_notes,
                'scheduled_at' => $serviceRequest->scheduled_at,
                'scheduled_end' => $serviceRequest->scheduled_end,
                'approved_at' => $serviceRequest->approved_at,
            ]);
        });
    }

    public function definition(): array
    {
        $start = Carbon::now('Asia/Manila')
            ->addDays(fake()->numberBetween(0, 13))
            ->setTime(fake()->numberBetween(7, 15), 0, 0)
            ->utc();

        return [
            'walk_in_name' => 'MDRRMO Desk Booking',
            'walk_in_contact_number' => '09170000000',
            'description' => 'Scheduled ambulance transport',
            'status' => 'Booked',
            'scheduled_at' => $start,
            'scheduled_end' => $start->copy()->addHours(2),
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
        return $this->state(function () use ($dayOffset, $hour) {
            $start = Carbon::now('Asia/Manila')
                ->addDays($dayOffset)
                ->setTime($hour, 0, 0)
                ->utc();

            return [
                'scheduled_at' => $start,
                'scheduled_end' => $start->copy()->addHours(2),
            ];
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
        return $this->state(['scheduled_at' => null, 'scheduled_end' => null]);
    }
}
