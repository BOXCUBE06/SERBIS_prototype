<?php

namespace Database\Seeders;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One ambulance booking sitting in every status tbl_service_request allows
 * (Pending, Booked, Responding, Resolved, Cancelled, Disapproved), so the
 * admin panel's Bookings queue shows every state at once instead of whatever
 * a real week of intake happens to have produced.
 *
 * Never production data: same local/testing guard every other demo seeder
 * uses. Not called from DatabaseSeeder or ProductionSeeder.
 *
 * ## Re-running this
 *
 * The six residents are keyed by a fixed @ambulance-status-demo.serbis.local
 * email and updateOrCreate()'d, so re-running never duplicates them — no
 * purge needed there, and nothing else in the app writes to that domain.
 * The six service requests (and the trip records two of them own) are
 * deleted and recreated every run: found by the '[demo-ambulance]' marker at
 * the front of `description`, the same visible-marker convention
 * DemoBorrowingSeeder uses for `purpose`.
 *
 * ## What this does NOT attempt
 *
 * The Responding and Resolved rows attach a real Ambulance unit's
 * vehicle_id so the panel shows a unit name, but this seeder does not flip
 * that vehicle's own `status` column to Dispatched the way a real dispatch
 * (ServiceRequestController::syncFleet()) would. Doing that safely would
 * mean tracking and restoring each unit's original status on purge — real
 * complexity for a seeder whose only job is to show request-status variety,
 * not fleet-state consistency. The two units used are left exactly as
 * VehicleSeeder/AmbulanceBookingSeeder set them.
 */
class AmbulanceBookingStatusDemoSeeder extends Seeder
{
    /** Marker and prefix in one, matched with a LIKE — same convention as DemoBorrowingSeeder::MARKER. */
    public const MARKER = '[demo-ambulance]';

    private const RESIDENT_DOMAIN = 'ambulance-status-demo.serbis.local';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'AmbulanceBookingStatusDemoSeeder skipped: refuses to seed outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        $service = Service::where('code', 'ambulance-medical-response')->first();

        $barangays = Barangay::whereIn('barangay_name', ['San Fabian', 'San Miguel', 'San Antonio Ugad'])
            ->get()
            ->keyBy('barangay_name');

        $vehicles = Vehicle::where('type', 'Ambulance')->orderBy('unit_identifier')->get();

        if (! $service || $barangays->count() < 3 || $vehicles->count() < 2) {
            $this->command?->warn(
                'AmbulanceBookingStatusDemoSeeder skipped: needs the Ambulance/Medical Response service, '
                .'all 3 barangays, and at least 2 Ambulance units — run ServiceSeeder, BarangaySeeder and '
                .'VehicleSeeder first.'
            );

            return;
        }

        $removed = self::purge();

        $admin = User::where('email_address', 'admin@serbis.com')->first();

        $sanFabian = $barangays['San Fabian'];
        $sanMiguel = $barangays['San Miguel'];
        $sanAntonioUgad = $barangays['San Antonio Ugad'];

        $unitOne = $vehicles[0];
        $unitTwo = $vehicles[1];

        $now = Carbon::now('Asia/Manila');

        // ---------------------------------------------------------- Pending
        // Phoned-in emergency, nothing scheduled — the same shape store()
        // gives a resident who submits with no scheduled_at at all.
        $pendingResident = $this->resident(
            'rosario.bautista', 'Rosario', 'Bautista', '09171110001', $sanFabian,
        );

        $this->request($service, $pendingResident, 'Pending', [
            'description' => self::MARKER.' Household head reports her father, 68, with sudden chest pain '
                .'and shortness of breath. Requesting immediate pickup.',
            'landmark' => 'Purok 2, San Fabian — beside the barangay hall',
        ], [
            'patient_name' => 'Ernesto Bautista',
            'patient_age' => 68,
            'patient_address' => 'Purok 2, San Fabian, Echague, Isabela',
            'patient_contact_number' => '09171110001',
            'pickup_location' => 'Purok 2, San Fabian — beside the barangay hall',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Chest pain, shortness of breath, onset ~20 minutes ago.',
        ]);

        // ----------------------------------------------------------- Booked
        // Scheduled by the resident; office has not yet approved a unit —
        // scheduled_end, vehicle_id and approved_at all stay null, exactly
        // what store() itself leaves for a fresh scheduled booking.
        $bookedResident = $this->resident(
            'teodoro.villanueva', 'Teodoro', 'Villanueva', '09171110002', $sanMiguel,
        );

        $bookedAt = $now->copy()->addDays(2)->setTime(9, 0, 0)->utc();

        $this->request($service, $bookedResident, 'Booked', [
            'description' => self::MARKER.' Standing dialysis appointment — needs a scheduled roundtrip to '
                .'Echague District Hospital.',
            'landmark' => 'Purok 4, San Miguel — beside the covered court',
        ], [
            'patient_name' => 'Teodoro Villanueva',
            'patient_age' => 57,
            'patient_address' => 'Purok 4, San Miguel, Echague, Isabela',
            'patient_contact_number' => '09171110002',
            'pickup_location' => 'Purok 4, San Miguel — beside the covered court',
            'destination' => 'Echague District Hospital — Dialysis Center',
            'condition_notes' => 'Stable, ambulatory. Chronic kidney disease, regular dialysis.',
            'scheduled_at' => $bookedAt,
        ]);

        // -------------------------------------------------------- Responding
        // Approved and dispatched: a unit is attached, approved_at and
        // scheduled_end are both set, and a mid-trip conduction record exists
        // — the same trip a real Booked -> Responding dispatch creates via
        // createConductionStub().
        $respondingResident = $this->resident(
            'corazon.mendoza', 'Corazon', 'Mendoza', '09171110003', $sanAntonioUgad,
        );

        $respondingScheduledAt = $now->copy()->subHours(1)->utc();
        $respondingScheduledEnd = $respondingScheduledAt->copy()->addHours(2);
        $respondingApprovedAt = $now->copy()->subDay()->utc();

        $respondingRequest = $this->request($service, $respondingResident, 'Responding', [
            'description' => self::MARKER.' Elderly resident fell at home, suspected hip fracture. Unit '
                .'dispatched.',
            'landmark' => 'Purok 1, San Antonio Ugad — end of the riverside road',
            'vehicle_id' => $unitOne->vehicle_id,
            'processed_by' => $admin?->getKey(),
        ], [
            'patient_name' => 'Corazon Mendoza',
            'patient_age' => 71,
            'patient_address' => 'Purok 1, San Antonio Ugad, Echague, Isabela',
            'patient_contact_number' => '09171110003',
            'pickup_location' => 'Purok 1, San Antonio Ugad — end of the riverside road',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Fall at home, suspected hip fracture, conscious and stable.',
            'scheduled_at' => $respondingScheduledAt,
            'scheduled_end' => $respondingScheduledEnd,
            'approved_at' => $respondingApprovedAt,
        ]);

        ConductionRequest::create([
            'service_request_id' => $respondingRequest->request_id,
            'vehicle_id' => $unitOne->vehicle_id,
            'patient_name' => 'Corazon Mendoza',
            'patient_age' => 71,
            'patient_address' => 'Purok 1, San Antonio Ugad, Echague, Isabela',
            'patient_contact_number' => '09171110003',
            'medical_diagnosis' => 'Fall at home, suspected hip fracture, conscious and stable.',
            'origin' => 'Purok 1, San Antonio Ugad — end of the riverside road',
            'destination' => 'Echague District Hospital',
            'vehicle' => $unitOne->unit_identifier.($unitOne->specification ? " ({$unitOne->specification})" : ''),
            'departed_office_at' => $now->copy()->subMinutes(15)->utc(),
        ]);

        // ---------------------------------------------------------- Resolved
        // Completed trip, every checkpoint filled, odometer included — the
        // same shape ConductionRequestController::update() leaves once a
        // trip is closed out.
        $resolvedResident = $this->resident(
            'bienvenido.aquino', 'Bienvenido', 'Aquino', '09171110004', $sanFabian,
        );

        $resolvedScheduledAt = $now->copy()->subDay()->setTime(8, 0, 0)->utc();
        $resolvedScheduledEnd = $resolvedScheduledAt->copy()->addHours(2);
        $resolvedApprovedAt = $now->copy()->subDays(2)->utc();

        $resolvedRequest = $this->request($service, $resolvedResident, 'Resolved', [
            'description' => self::MARKER.' Snakebite while clearing brush. Transported and treated.',
            'landmark' => 'Purok 3, San Fabian — across the covered court',
            'vehicle_id' => $unitTwo->vehicle_id,
            'processed_by' => $admin?->getKey(),
        ], [
            'patient_name' => 'Bienvenido Aquino',
            'patient_age' => 45,
            'patient_address' => 'Purok 3, San Fabian, Echague, Isabela',
            'patient_contact_number' => '09171110004',
            'pickup_location' => 'Purok 3, San Fabian — across the covered court',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Snakebite, right forearm, while clearing brush.',
            'scheduled_at' => $resolvedScheduledAt,
            'scheduled_end' => $resolvedScheduledEnd,
            'approved_at' => $resolvedApprovedAt,
        ]);

        $resolvedDeparted = $resolvedScheduledAt->copy()->addMinutes(10);

        ConductionRequest::create([
            'service_request_id' => $resolvedRequest->request_id,
            'vehicle_id' => $unitTwo->vehicle_id,
            'patient_name' => 'Bienvenido Aquino',
            'patient_age' => 45,
            'patient_address' => 'Purok 3, San Fabian, Echague, Isabela',
            'patient_contact_number' => '09171110004',
            'medical_diagnosis' => 'Snakebite, right forearm, while clearing brush.',
            'origin' => 'Purok 3, San Fabian — across the covered court',
            'destination' => 'Echague District Hospital',
            'vehicle' => $unitTwo->unit_identifier.($unitTwo->specification ? " ({$unitTwo->specification})" : ''),
            'departed_office_at' => $resolvedDeparted,
            'arrived_destination_at' => $resolvedDeparted->copy()->addMinutes(25),
            'departed_destination_at' => $resolvedDeparted->copy()->addHours(2),
            'returned_office_at' => $resolvedDeparted->copy()->addHours(2)->addMinutes(20),
            'odometer_start' => 8412,
            'odometer_end' => 8459,
        ]);

        // ------------------------------------------------------- Disapproved
        // Was a scheduled booking; the office rejected it before approval —
        // remarks is required on this transition, same as update() enforces.
        $disapprovedResident = $this->resident(
            'leonora.ramos', 'Leonora', 'Ramos', '09171110005', $sanMiguel,
        );

        $disapprovedScheduledAt = $now->copy()->addDays(3)->setTime(14, 0, 0)->utc();

        $this->request($service, $disapprovedResident, 'Disapproved', [
            'description' => self::MARKER.' Requested a scheduled transport for a routine hospital follow-up.',
            'landmark' => 'Purok 5, San Miguel — corner of the national road',
            'remarks' => 'No ambulance unit available for that time slot — advised to arrange private '
                .'transport or request a different time.',
            'processed_by' => $admin?->getKey(),
        ], [
            'patient_name' => 'Leonora Ramos',
            'patient_age' => 63,
            'patient_address' => 'Purok 5, San Miguel, Echague, Isabela',
            'patient_contact_number' => '09171110005',
            'pickup_location' => 'Purok 5, San Miguel — corner of the national road',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Routine follow-up, ambulatory, non-urgent.',
            'scheduled_at' => $disapprovedScheduledAt,
        ]);

        // -------------------------------------------------------- Cancelled
        // Withdrawn by the resident before approval — cancel() writes no
        // reason of its own, so none is set here either.
        $cancelledResident = $this->resident(
            'marcelino.domingo', 'Marcelino', 'Domingo', '09171110006', $sanAntonioUgad,
        );

        $cancelledScheduledAt = $now->copy()->addDays(4)->setTime(10, 0, 0)->utc();

        $this->request($service, $cancelledResident, 'Cancelled', [
            'description' => self::MARKER.' Requested transport for a scheduled check-up; withdrawn after '
                .'the patient recovered.',
            'landmark' => 'Purok 1, San Antonio Ugad — near the elementary school',
        ], [
            'patient_name' => 'Marcelino Domingo',
            'patient_age' => 52,
            'patient_address' => 'Purok 1, San Antonio Ugad, Echague, Isabela',
            'patient_contact_number' => '09171110006',
            'pickup_location' => 'Purok 1, San Antonio Ugad — near the elementary school',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Scheduled check-up; no longer needed.',
            'scheduled_at' => $cancelledScheduledAt,
        ]);

        if ($removed > 0) {
            $this->command?->info("AmbulanceBookingStatusDemoSeeder: replaced {$removed} row(s) from a previous run.");
        }

        $this->command?->info('AmbulanceBookingStatusDemoSeeder: created one booking per status (Pending, '
            .'Booked, Responding, Resolved, Disapproved, Cancelled), all marked '.self::MARKER.'.');

        $this->command?->info('Undo with: '.self::class.'::purge()');
    }

    /**
     * Deletes every service request this seeder made, and the trip records
     * two of them own. Public and static so a future purge command (there is
     * none yet — DemoBorrowingSeeder's split is the only precedent) has one
     * real implementation to call rather than a second copy.
     *
     * Residents are untouched here — see the class doc comment on why they
     * are updateOrCreate()'d instead of purged.
     *
     * Returns how many service requests were removed.
     */
    public static function purge(): int
    {
        $requestIds = DB::table('tbl_service_request')
            ->where('description', 'like', self::MARKER.'%')
            ->pluck('request_id');

        if ($requestIds->isEmpty()) {
            return 0;
        }

        // No FK cascade on this one (nullOnDelete, since a walk-in trip can
        // exist with no booking behind it) — deleted explicitly, before the
        // parent row, or a re-run would leave an orphaned trip log behind
        // every time.
        DB::table('tbl_conduction_requests')->whereIn('service_request_id', $requestIds)->delete();

        // tbl_ambulance_bookings cascades on this delete (its own FK is
        // cascadeOnDelete) — nothing to do for it here.
        DB::table('tbl_service_request')->whereIn('request_id', $requestIds)->delete();

        return $requestIds->count();
    }

    /** One demo resident, stable across re-runs — keyed by a fixed email under a domain nothing else uses. */
    private function resident(
        string $localPart,
        string $firstName,
        string $lastName,
        string $phone,
        Barangay $barangay,
    ): Resident {
        return Resident::updateOrCreate(
            ['email_address' => "{$localPart}@".self::RESIDENT_DOMAIN],
            [
                'barangay_id' => $barangay->getKey(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone_number' => $phone,
                'password' => Hash::make(fake()->password(16)),
                'status' => 'Active',
            ],
        );
    }

    /** One ServiceRequest + its AmbulanceBooking row, built the way store()/update() actually leave them. */
    private function request(
        Service $service,
        Resident $resident,
        string $status,
        array $requestFields,
        array $bookingFields,
    ): ServiceRequest {
        $serviceRequest = ServiceRequest::create(array_merge([
            'resident_id' => $resident->getKey(),
            'service_id' => $service->getKey(),
            'status' => $status,
        ], $requestFields));

        AmbulanceBooking::create(array_merge(
            ['request_id' => $serviceRequest->request_id],
            $bookingFields,
        ));

        return $serviceRequest;
    }
}
