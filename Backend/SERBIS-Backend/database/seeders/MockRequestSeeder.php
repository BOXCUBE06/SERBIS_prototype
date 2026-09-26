<?php

namespace Database\Seeders;

use App\Models\AmbulanceBooking;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRelative;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk realistic-looking demo data for a local admin panel, so it reads as a
 * working office rather than an empty prototype. Rewritten 2026-09-20 — the
 * previous version predated tbl_ambulance_bookings (2026-09-10) and the
 * required-on-every-return condition note (2026-09-19), so an ambulance row
 * it created had no booking record behind it at all (a 500 the moment the
 * dispatch board tried to read one), and every Returned borrowing it made
 * would now fail EquipmentBorrowingController's own validation were it ever
 * replayed through the endpoint instead of inserted directly.
 *
 * TRUNCATES tbl_service_request, tbl_ambulance_bookings,
 * tbl_service_request_relatives and tbl_equipment_borrowing before inserting.
 * Residents, admins, barangays, services and equipment are read, never
 * written, except for available_quantity on equipment this seeder itself
 * seeds as Released (reconciled the same way EquipmentBorrowingSeeder does)
 * and the status of whichever Ambulance vehicles end up carrying a seeded
 * Responding request. Run once against a live database and every real
 * request MDRRMO has taken is gone, with no undo — the same guard as before
 * stops that outside local/testing.
 */
class MockRequestSeeder extends Seeder
{
    private const BARANGAYS = ['San Fabian', 'San Miguel', 'San Antonio Ugad'];

    private const PUROKS = [
        'Purok 1', 'Purok 2', 'Purok 3', 'Purok Mabuhay', 'Purok Malaya',
        'Zone 4', 'Sitio Kapayapaan', 'Rizal Street', 'Bonifacio Street', 'Mabini Street',
    ];

    private const FIRST_NAMES = [
        'Juan', 'Pedro', 'Ramon', 'Eduardo', 'Rodrigo', 'Ariel', 'Ferdinand', 'Ronnie',
        'Danilo', 'Ernesto', 'Benjamin', 'Rogelio', 'Josephine', 'Lourdes', 'Angelica',
        'Remedios', 'Imelda', 'Divina', 'Marites', 'Editha', 'Fe', 'Corazon', 'Leonora',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Villanueva',
        'Domingo', 'Ramos', 'Aquino', 'Fernandez', 'Torres', 'Gonzales', 'Del Rosario',
        'Manalo', 'Pascual',
    ];

    private const DESTINATIONS = [
        'Echague District Hospital', 'Isabela Provincial Hospital',
        'Mother Teresa Medical Center', 'Cauayan City Hospital',
    ];

    /**
     * Every status ServiceRequestController::STATUSES actually accepts.
     * 'Booked' only ever fires for the ambulance service — see
     * ServiceRequestController::store(), which sets it exclusively for a
     * scheduled ambulance booking — so it is drawn only in ambulanceRow(),
     * never in serviceRow().
     */
    private const REQUEST_STATUSES = ['Pending', 'Responding', 'Resolved', 'Cancelled', 'Disapproved'];

    private array $allResidentIds = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'MockRequestSeeder skipped: refuses to truncate and re-seed outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        $residents = Resident::where('status', 'Active')->with('barangay')->get();
        $services = Service::whereIn('code', [
            'ambulance-medical-response', 'relief-goods-distribution', 'road-clearing',
            'power-line-repair', 'debris-removal', 'animal-rescue', 'sandbagging',
        ])->get()->keyBy('code');
        $equipment = Equipment::all();
        $ambulances = Vehicle::where('type', 'Ambulance')->get();

        if ($residents->isEmpty() || $services->count() < 7 || $equipment->isEmpty()) {
            $this->command?->error(
                'MockRequestSeeder needs at least one Active resident, all 7 catalogued services and '
                .'at least one equipment row. Run BarangaySeeder, ResidentSeeder, ServiceSeeder and '
                .'EquipmentSeeder first.'
            );

            return;
        }

        $this->allResidentIds = $residents->all();

        Schema::disableForeignKeyConstraints();
        ServiceRequestRelative::truncate();
        AmbulanceBooking::truncate();
        ServiceRequest::truncate();
        EquipmentBorrowing::truncate();
        Schema::enableForeignKeyConstraints();

        // Every borrowing row is gone, so nothing is actually out any more —
        // whatever an earlier seeder or a real Released loan had decremented
        // is reset before this seeder's own Released rows decrement it again.
        // Mirrors EquipmentBorrowingSeeder's own reconciliation, one step
        // earlier: that seeder assumes it starts from full stock too.
        Equipment::query()->update(['available_quantity' => DB::raw('total_quantity')]);

        $this->command?->info('Old service-request and borrowing records cleared.');

        $requestCount = $this->seedAmbulanceRequests($services['ambulance-medical-response'], $ambulances);

        foreach (['relief-goods-distribution', 'road-clearing', 'power-line-repair', 'debris-removal', 'animal-rescue', 'sandbagging'] as $code) {
            $requestCount += $this->seedServiceRequests($services[$code]);
        }

        $borrowCount = $this->seedEquipmentBorrowings($equipment);

        $this->command?->info("MockRequestSeeder: created {$requestCount} service requests and {$borrowCount} equipment borrowings.");
    }

    // ------------------------------------------------------------ ambulance

    private function seedAmbulanceRequests(Service $service, \Illuminate\Support\Collection $ambulances): int
    {
        // Booked/Responding/Resolved are the "approved" states — every one of
        // them gets an approved_at and a real destination; Pending never has
        // either yet, and Cancelled/Disapproved never reached approval at all.
        $plan = [
            ['status' => 'Pending', 'count' => 2],
            ['status' => 'Booked', 'count' => 2],
            ['status' => 'Responding', 'count' => 2],
            ['status' => 'Resolved', 'count' => 3],
            ['status' => 'Cancelled', 'count' => 1],
            ['status' => 'Disapproved', 'count' => 2],
        ];

        $created = 0;
        $companionSlots = [0, 2, 5, 8]; // indices (across the whole run) that get 1-2 companions
        $index = 0;
        $respondingVehicles = $ambulances->count() ? $ambulances->all() : [null];

        foreach ($plan as $group) {
            for ($i = 0; $i < $group['count']; $i++, $index++) {
                $status = $group['status'];
                $isApproved = in_array($status, ['Booked', 'Responding', 'Resolved'], true);
                $resident = $this->randomResident();
                $createdAt = Carbon::now()->subDays(random_int(1, 90))->subHours(random_int(0, 23));

                $patientIsResident = random_int(0, 1) === 1;
                $patientName = $patientIsResident
                    ? trim($resident->first_name.' '.$resident->last_name)
                    : $this->randomFullName();

                $vehicle = null;
                if ($status === 'Responding' && $respondingVehicles[0] !== null) {
                    $vehicle = $respondingVehicles[$index % count($respondingVehicles)];
                }

                $pickup = $this->randomAddress($resident);
                $destination = $isApproved ? $this->randomFrom(self::DESTINATIONS) : null;
                $condition = $this->randomCondition();

                // Same shape ServiceRequestController::composeAmbulanceDescription()
                // writes at intake — a raw insert bypasses that, so it is
                // reproduced here rather than left blank, which every real
                // ambulance row never is.
                $description = implode("\n", [
                    'Patient: '.$patientName,
                    $pickup.' → '.($destination ?? 'destination not specified'),
                    'Condition: Patient reports '.$condition.'.',
                    'Contact: '.($patientIsResident ? 'See resident profile' : $this->randomPhone()),
                ]);

                $serviceRequest = $this->createServiceRequest([
                    'resident_id' => $resident->resident_id,
                    'service_id' => $service->service_id,
                    'vehicle_id' => $vehicle?->vehicle_id,
                    'description' => $description,
                    'status' => $status,
                    'remarks' => $status === 'Disapproved' ? 'No unit available for the requested time.' : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ], [
                    'first_responded_at' => $status !== 'Pending' ? $createdAt->copy()->addMinutes(random_int(5, 90)) : null,
                    'resolved_at' => in_array($status, ['Resolved', 'Cancelled', 'Disapproved'], true)
                        ? $createdAt->copy()->addHours(random_int(1, 6))
                        : null,
                ]);

                AmbulanceBooking::create([
                    'request_id' => $serviceRequest->request_id,
                    'patient_name' => $patientName,
                    'patient_age' => random_int(4, 82),
                    'patient_address' => $this->randomAddress($resident),
                    'patient_contact_number' => $patientIsResident ? null : $this->randomPhone(),
                    'pickup_location' => $pickup,
                    'destination' => $destination,
                    'condition_notes' => 'Patient reports '.$condition.'.',
                    'approved_at' => $isApproved ? $createdAt->copy()->addMinutes(random_int(5, 45)) : null,
                ]);

                if (in_array($index, $companionSlots, true)) {
                    $this->seedCompanions($serviceRequest);
                }

                $created++;
            }
        }

        // A Responding request has an ambulance actually out — reflect that on
        // the unit so the fleet view is not lying about who is free.
        foreach ($ambulances as $vehicle) {
            if (ServiceRequest::where('vehicle_id', $vehicle->vehicle_id)->where('status', 'Responding')->exists()) {
                $vehicle->update(['status' => 'Dispatched']);
            }
        }

        return $created;
    }

    private function seedCompanions(ServiceRequest $serviceRequest): void
    {
        $count = random_int(1, 2); // MAX_RELATIVES cap — see ServiceRequestController::MAX_RELATIVES

        for ($position = 1; $position <= $count; $position++) {
            ServiceRequestRelative::create([
                'service_request_id' => $serviceRequest->request_id,
                'name' => $this->randomFullName(),
                'position' => $position,
            ]);
        }
    }

    // --------------------------------------------------------------- other 6

    private const SERVICE_DESCRIPTIONS = [
        'relief-goods-distribution' => 'Requesting relief goods for the family — food and water ran out after the flooding.',
        'road-clearing' => 'A fallen tree is blocking the road, no vehicles can pass through.',
        'power-line-repair' => 'A power line came down after the storm and is lying across the street.',
        'debris-removal' => 'Debris from a collapsed fence is blocking the pathway.',
        'animal-rescue' => 'A carabao is stranded near the riverbank and cannot get out on its own.',
        'sandbagging' => 'Requesting sandbags — the creek is rising and the area floods easily.',
    ];

    private function seedServiceRequests(Service $service): int
    {
        $created = 0;

        foreach (self::REQUEST_STATUSES as $status) {
            $rows = $status === 'Pending' ? 3 : 1; // slightly weight Pending, still every status represented

            for ($i = 0; $i < $rows; $i++) {
                $resident = $this->randomResident();
                $createdAt = Carbon::now()->subDays(random_int(1, 90))->subHours(random_int(0, 23));
                $isDelivery = $service->code === 'relief-goods-distribution' && random_int(0, 1) === 1;

                $this->createServiceRequest([
                    'resident_id' => $resident->resident_id,
                    'service_id' => $service->service_id,
                    'description' => self::SERVICE_DESCRIPTIONS[$service->code],
                    // Location is its own field; it used to be tacked onto the description.
                    'landmark' => 'Near '.$this->randomFrom(self::PUROKS).', '.($resident->barangay?->barangay_name ?? self::BARANGAYS[0]),
                    'fulfillment_method' => $service->code === 'relief-goods-distribution' ? ($isDelivery ? 'Delivery' : 'Pickup') : null,
                    'delivery_address' => $isDelivery ? $this->randomAddress($resident) : null,
                    'status' => $status,
                    'remarks' => $status === 'Disapproved' ? 'Outside the office\'s current response capacity.' : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ], [
                    'first_responded_at' => $status !== 'Pending' ? $createdAt->copy()->addHours(random_int(1, 12)) : null,
                    'resolved_at' => in_array($status, ['Resolved', 'Cancelled', 'Disapproved'], true)
                        ? $createdAt->copy()->addHours(random_int(2, 24))
                        : null,
                ]);

                $created++;
            }
        }

        return $created;
    }

    // ------------------------------------------------------------ borrowing

    private const BORROW_PURPOSES = [
        'Barangay flood drill this weekend.',
        'Evacuation centre setup for the incoming typhoon.',
        'First aid post for the barangay basketball league.',
        'Standby cover for the fiesta parade route.',
        'Clearing debris along the riverbank after the storm.',
        'Home care for an elderly family member.',
        'Relief goods distribution support.',
        'Medical mission support at the barangay hall.',
    ];

    private function seedEquipmentBorrowings(\Illuminate\Support\Collection $equipment): int
    {
        // 'reason' documents WHY each row looks the way it does, since a flat
        // loop can't show the mix of dates/notes the task asked to exercise
        // (overdue, on-time, late, Good vs Bad condition) as clearly as one
        // row per case does.
        $plan = [
            ['status' => 'Pending', 'count' => 4],
            ['status' => 'Approved', 'count' => 4],
            ['status' => 'Released', 'count' => 3, 'overdue' => false],
            ['status' => 'Released', 'count' => 2, 'overdue' => true],
            ['status' => 'Returned', 'count' => 3, 'condition' => 'Good', 'late' => false],
            ['status' => 'Returned', 'count' => 3, 'condition' => 'Bad', 'late' => true],
            ['status' => 'Denied', 'count' => 3],
            ['status' => 'Cancelled', 'count' => 2],
        ];

        $rows = [];
        $releasedByEquipment = [];

        foreach ($plan as $group) {
            for ($i = 0; $i < $group['count']; $i++) {
                $resident = $this->randomResident();
                $item = $this->randomFrom($equipment->all());
                $useOthersText = random_int(0, 9) === 0; // occasional uncatalogued request, same as real intake
                $quantity = random_int(1, 2);

                $createdAt = Carbon::now()->subDays(random_int(10, 60));
                $dueDate = null;
                $releasedAt = null;
                $returnedAt = null;
                $conditionNote = null;
                $condition = null;
                $denialReason = null;
                $denialReasonCode = null;

                switch ($group['status']) {
                    case 'Approved':
                        // The panel collects due_date at approval time, before
                        // release — see EquipmentBorrowingController::update()'s
                        // own comment on the due_date rule.
                        $dueDate = Carbon::today()->addDays(random_int(1, 7));
                        break;
                    case 'Released':
                        $releasedAt = $createdAt->copy()->addDay();
                        $dueDate = ($group['overdue'] ?? false)
                            ? Carbon::today()->subDays(random_int(1, 10))
                            : Carbon::today()->addDays(random_int(1, 6));
                        break;
                    case 'Returned':
                        $releasedAt = $createdAt->copy()->addDay();
                        $onTimeDue = $releasedAt->copy()->addDays(random_int(2, 6));
                        $returnedAt = ($group['late'] ?? false)
                            ? $onTimeDue->copy()->addDays(random_int(1, 4))
                            : $onTimeDue->copy()->subDay();
                        $dueDate = $onTimeDue;
                        $condition = $group['condition'];
                        $conditionNote = $condition === 'Bad'
                            ? $this->randomFrom(['Strap frayed, otherwise usable.', 'Wheel wobbles, needs tightening.', 'Cracked housing, unusable as-is.'])
                            : $this->randomFrom(['Came back clean, all parts intact.', 'Good condition, ready to lend again.']);
                        break;
                    case 'Denied':
                        $denialReasonCode = $this->randomFrom(['Unavailable', 'Other']);
                        $denialReason = $denialReasonCode === 'Unavailable'
                            ? 'No units currently available in stock.'
                            : 'Purpose given does not qualify under agency policy.';
                        break;
                }

                $updatedAt = $returnedAt ?? $releasedAt ?? $createdAt;

                $row = [
                    'resident_id' => $resident->resident_id,
                    'equipment_id' => $useOthersText ? null : $item->equipment_id,
                    'other_equipment_text' => $useOthersText ? 'Generator (barangay-owned, borrowed via MDRRMO)' : null,
                    'quantity' => $quantity,
                    'purpose' => $this->randomFrom(self::BORROW_PURPOSES),
                    'fulfillment_method' => 'Pickup',
                    'borrower_type' => 'Resident',
                    'due_date' => $dueDate?->toDateString(),
                    'status' => $group['status'],
                    'denial_reason' => $denialReason,
                    'denial_reason_code' => $denialReasonCode,
                    'return_condition' => $condition,
                    'return_condition_note' => $conditionNote,
                    'released_at' => $releasedAt,
                    'returned_at' => $returnedAt,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];

                $rows[] = $row;

                if ($group['status'] === 'Released' && ! $useOthersText) {
                    $releasedByEquipment[$item->equipment_id] = ($releasedByEquipment[$item->equipment_id] ?? 0) + $quantity;
                }
            }
        }

        DB::transaction(function () use ($rows, $releasedByEquipment) {
            DB::table('tbl_equipment_borrowing')->insert($rows);

            // Same reconciliation as EquipmentBorrowingSeeder: a raw insert
            // never goes through EquipmentBorrowingController::update(), so a
            // seeded 'Released' row never actually decremented stock on its
            // own. Clamped at 0 for the same reason that seeder clamps.
            foreach ($releasedByEquipment as $equipmentId => $releasedQty) {
                DB::table('tbl_equipments')
                    ->where('equipment_id', $equipmentId)
                    ->update(['available_quantity' => DB::raw("GREATEST(0, available_quantity - {$releasedQty})")]);
            }
        });

        return count($rows);
    }

    // ------------------------------------------------------------- helpers

    /**
     * first_responded_at/resolved_at are stamped by ServiceRequest's own
     * `updating` model event, not by create() — and neither is in the
     * model's #[Fillable(...)] list to begin with, so a plain
     * ServiceRequest::create() with either key silently drops it. forceFill()
     * bypasses both: a direct insert has no real "transition" to stamp these
     * from, so this seeder sets the same columns update() would end up
     * writing, by hand.
     */
    private function createServiceRequest(array $attributes, array $lifecycle): ServiceRequest
    {
        $serviceRequest = ServiceRequest::create($attributes);
        $serviceRequest->forceFill($lifecycle)->saveQuietly();

        return $serviceRequest;
    }

    private function randomResident(): Resident
    {
        return $this->randomFrom($this->allResidentIds);
    }

    private function randomFullName(): string
    {
        return $this->randomFrom(self::FIRST_NAMES).' '.$this->randomFrom(self::LAST_NAMES);
    }

    private function randomAddress(Resident $resident): string
    {
        $barangay = $resident->barangay?->barangay_name ?? self::BARANGAYS[0];

        return $this->randomFrom(self::PUROKS).', Brgy. '.$barangay.', Echague, Isabela';
    }

    private function randomPhone(): string
    {
        return '09'.random_int(170000000, 189999999);
    }

    private function randomCondition(): string
    {
        return $this->randomFrom([
            'difficulty breathing', 'chest pain', 'a fall with a possible fracture',
            'high fever and vomiting', 'a deep laceration', 'dizziness and weakness',
        ]);
    }

    private function randomFrom(array $items)
    {
        return $items[array_rand($items)];
    }
}
