<?php

namespace Database\Seeders;

use App\Models\ConductionRequest;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the ambulance scheduling calendar and the availability
 * endpoint: bookings spread over the next 14 days, plus every edge case
 * AmbulanceAvailability and the calendar need to prove they handle
 * correctly — a cross-unit overlap that would misfire if the check ever
 * dropped vehicle_id, ignored terminal bookings, a Maintenance unit, a
 * Dispatched unit with no window, and a conduction trip actually linked
 * back to the booking it fulfils.
 *
 * Never production data: refuses outside local/testing, the same guard
 * ServiceRequestSeeder and ResidentSeeder use. Self-skips if this seeder's
 * own signature — a scheduled_at already on tbl_ambulance_bookings — is
 * already there, the way VehicleSeeder self-skips on a non-empty
 * tbl_vehicles. Not called from ProductionSeeder.
 */
class AmbulanceBookingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'AmbulanceBookingSeeder skipped: refuses to seed simulated bookings outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        if (DB::table('tbl_ambulance_bookings')->whereNotNull('scheduled_at')->exists()) {
            $this->command?->warn('AmbulanceBookingSeeder skipped: a scheduled booking already exists.');

            return;
        }

        $service = Service::where('service_name', 'Ambulance/Medical Response')->first();

        $units = Vehicle::where('type', 'Ambulance')->orderBy('unit_identifier')->get()->keyBy('unit_identifier');

        if (! $service || $units->count() < 4) {
            $this->command?->warn(
                'AmbulanceBookingSeeder skipped: needs the Ambulance/Medical Response service and 4 Ambulance '
                .'units — run ServiceSeeder and VehicleSeeder first.'
            );

            return;
        }

        $amb01 = $units['Ambulance 1'];
        $amb02 = $units['Ambulance 2'];
        $amb03 = $units['Ambulance 3'];
        $amb04 = $units['Ambulance 4'];

        // Bookings across all four units over the next 14 days — two apiece,
        // on days and hours that do not collide with any scenario below.
        $this->booking($service, $amb01, 1, 8);
        $this->booking($service, $amb01, 5, 14);
        $this->booking($service, $amb02, 2, 8);
        $this->booking($service, $amb02, 6, 14);
        $this->booking($service, $amb03, 4, 8);
        $this->booking($service, $amb03, 10, 8);
        $this->booking($service, $amb04, 4, 13);
        $this->booking($service, $amb04, 11, 8);

        // A pair that would overlap if the check were wrong: the same day and
        // the same 2-hour window, but on two different units. If
        // AmbulanceAvailability ever compared windows without scoping to
        // vehicle_id, booking Ambulance 2 here would wrongly exclude Ambulance 1 too.
        $this->booking($service, $amb01, 3, 9);
        $this->booking($service, $amb02, 3, 9);

        // Ignored regardless of how squarely their window sits inside an
        // otherwise-open day — a Cancelled and a Disapproved booking are not
        // active, so they must never shrink availability.
        $this->booking($service, $amb01, 8, 9, 'Cancelled');
        $this->booking($service, $amb02, 8, 9, 'Disapproved');

        // A few unscheduled requests — no window, so they cannot participate
        // in the overlap test at all. Two are plain unassigned walk-in calls;
        // the third is Ambulance 4's own mid-trip run, dispatched immediately with
        // no scheduled window, and is also the booking the mid-trip
        // ConductionRequest below links back to.
        ServiceRequest::factory()->unscheduled()->create([
            'service_id' => $service->service_id,
            'walk_in_name' => 'Walk-in caller',
            'walk_in_contact_number' => '09171234567',
            'description' => 'Phoned-in request, not yet assigned a unit',
            'status' => 'Pending',
        ]);
        ServiceRequest::factory()->unscheduled()->create([
            'service_id' => $service->service_id,
            'walk_in_name' => 'Walk-in caller',
            'walk_in_contact_number' => '09171234568',
            'description' => 'Second phoned-in request, not yet assigned a unit',
            'status' => 'Pending',
        ]);
        $midTripRequest = ServiceRequest::factory()->unscheduled()->create([
            'service_id' => $service->service_id,
            'vehicle_id' => $amb04->vehicle_id,
            'walk_in_name' => 'Walk-in caller',
            'walk_in_contact_number' => '09171234569',
            'description' => 'Dispatched immediately, no scheduled window',
            'status' => 'Responding',
        ]);

        // Two conduction requests linked back to the booking they fulfil, one
        // mid-trip and one completed — Ambulance 4's dispatch above for the first,
        // a fresh today-scheduled booking on Ambulance 2 for the second, so the
        // completed trip's own vehicle is free to return to Available rather
        // than colliding with a unit this seeder already marks Dispatched or
        // Maintenance.
        $this->conductionTrip($amb04, $midTripRequest);
        $this->completedConductionTrip($service, $amb02);

        // Vehicle status set last, once every request row above already
        // exists — it does not depend on write order, but this keeps each
        // unit's final state next to the scenario that explains it.
        $amb03->update(['status' => 'Maintenance']);
        $amb04->update(['status' => 'Dispatched']);
    }

    private function booking(Service $service, Vehicle $vehicle, int $dayOffset, int $hour, string $status = 'Booked'): ServiceRequest
    {
        return ServiceRequest::factory()
            ->onDay($dayOffset, $hour)
            ->create([
                'service_id' => $service->service_id,
                'vehicle_id' => $vehicle->vehicle_id,
                'status' => $status,
            ]);
    }

    /** The mid-trip conduction request — linked to a run already dispatched, checkpoints start and stop there. */
    private function conductionTrip(Vehicle $vehicle, ServiceRequest $serviceRequest): void
    {
        $departed = Carbon::now('UTC')->subMinutes(15);

        ConductionRequest::create([
            'service_request_id' => $serviceRequest->request_id,
            'vehicle_id' => $vehicle->vehicle_id,
            'patient_name' => 'Pedro Ramos',
            'patient_age' => 41,
            'patient_address' => 'Barangay San Fabian, Echague, Isabela',
            'patient_contact_number' => '09179876543',
            'medical_diagnosis' => 'Hypertensive emergency, needs monitored transport',
            'origin' => 'MDRRMO Office, Echague',
            'destination' => 'Echague District Hospital',
            'departed_office_at' => $departed,
        ]);
    }

    /**
     * The completed conduction request: its own today-scheduled booking, all
     * four trip-log checkpoints filled, odometer included — the shape
     * `getTripStatusAttribute()` reads as 'Completed'.
     */
    private function completedConductionTrip(Service $service, Vehicle $vehicle): void
    {
        $today = Carbon::now('Asia/Manila')->setTime(7, 0, 0)->utc();

        $serviceRequest = ServiceRequest::factory()->create([
            'service_id' => $service->service_id,
            'vehicle_id' => $vehicle->vehicle_id,
            'status' => 'Resolved',
            'scheduled_at' => $today,
            'scheduled_end' => $today->copy()->addHours(2),
            'description' => 'Conduction trip demo (completed)',
        ]);

        $departed = $today->copy()->addMinutes(10);

        ConductionRequest::create([
            'service_request_id' => $serviceRequest->request_id,
            'vehicle_id' => $vehicle->vehicle_id,
            'patient_name' => 'Ana Cruz',
            'patient_age' => 54,
            'patient_address' => 'Barangay San Fabian, Echague, Isabela',
            'patient_contact_number' => '09179876544',
            'medical_diagnosis' => 'Post-fall trauma, needs monitored transport',
            'origin' => 'MDRRMO Office, Echague',
            'destination' => 'Echague District Hospital',
            'departed_office_at' => $departed,
            'arrived_destination_at' => $departed->copy()->addMinutes(25),
            'departed_destination_at' => $departed->copy()->addHours(1),
            'returned_office_at' => $departed->copy()->addHours(1)->addMinutes(20),
            'odometer_start' => 10523,
            'odometer_end' => 10561,
        ]);
    }
}
