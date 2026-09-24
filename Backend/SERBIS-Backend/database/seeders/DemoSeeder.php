<?php

namespace Database\Seeders;

use App\Models\AmbulanceBooking;
use App\Models\ConductionRequest;
use App\Models\ConductionRequestPerson;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\ServiceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Local-only demo data: ~25 accounts and ~110 non-emergency records whose
 * statuses, timestamps and actors satisfy what the controllers would have
 * written. Run: php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * Models run with events muted and tbl_system_logs rows are written by hand,
 * backdated, so the audit trail matches the seeded actions.
 */
class DemoSeeder extends Seeder
{
    private const PLACEHOLDER = 'demo/placeholder.png';

    private const TZ = 'Asia/Manila';

    private const TOWN = 'Echague, Isabela';

    private const BARANGAYS = [1 => 'San Fabian', 2 => 'San Miguel', 3 => 'San Antonio Ugad'];

    // [first, middle, last, barangay, street]
    private const HEADS = [
        ['Ricardo', 'Bautista', 'Bumanglag', 1, 'Purok 1, Sitio Centro'],
        ['Marilou', 'Ramos', 'Pagaduan', 1, 'Purok 3, Sitio Bagong Sikat'],
        ['Efren', 'Dela Cruz', 'Agcaoili', 1, 'Purok 4, Sitio Callang'],
        ['Rosalinda', 'Aquino', 'Santos', 1, 'Purok 6, Sitio Riverside'],
        ['Benjamin', 'Tumaneng', 'Cabatbat', 1, 'Purok 1, Sitio Centro'],
        ['Analyn', 'Pascua', 'Villanueva', 1, 'Purok 3, Sitio Bagong Sikat'],
        ['Alfredo', 'Domingo', 'Gaoat', 2, 'Purok 2, Sitio Pagasa'],
        ['Nenita', 'Cabacungan', 'Rabago', 2, 'Purok 3, Sitio Lagundi'],
        ['Domingo', 'Manuel', 'Tagorda', 2, 'Purok 5, Sitio Balasa'],
        ['Cecilia', 'Reyes', 'Mendoza', 2, 'Purok 2, Sitio Pagasa'],
        ['Rolando', 'Sibayan', 'Manuel', 2, 'Purok 3, Sitio Lagundi'],
        ['Josefina', 'Agbayani', 'Aquino', 2, 'Purok 5, Sitio Balasa'],
        ['Virgilio', 'Dumlao', 'Tumaneng', 3, 'Purok 1, Sitio Ugad Proper'],
        ['Luzviminda', 'Santos', 'Castillo', 3, 'Purok 4, Sitio Manggahan'],
        ['Ernesto', 'Pagaduan', 'Dumlao', 3, 'Purok 7, Sitio Sapa'],
        ['Gloria', 'Bumanglag', 'Reyes', 3, 'Purok 4, Sitio Manggahan'],
        ['Renato', 'Cabatbat', 'Balauag', 3, 'Purok 1, Sitio Ugad Proper'],
    ];

    // [first, middle, last, barangay, street, organization_name, status]
    private const ORGS = [
        ['Lourdes', 'Ramos', 'Cabacungan', 1, 'Purok 1, Sitio Centro', 'San Fabian Elementary School PTA', 'Active'],
        ['Amado', 'Gaoat', 'Tumaneng', 2, 'Purok 2, Sitio Pagasa', 'San Miguel Parish Pastoral Council', 'Active'],
        ['Jericho', 'Pascua', 'Ballesteros', 3, 'Purok 1, Sitio Ugad Proper', 'San Antonio Ugad Sangguniang Kabataan (Youth Council)', 'Active'],
        ['Nestor', 'Dela Cruz', 'Sibayan', 2, 'Purok 3, Sitio Lagundi', 'San Miguel Farmers Multi-Purpose Cooperative', 'Active'],
        ['Corazon', 'Agcaoili', 'Dumlao', 1, 'Purok 4, Sitio Callang', 'San Fabian Rural Health Volunteers Association', 'Inactive'],
    ];

    // Barangay-hall accounts, one per barangay.
    private const HALLS = [
        ['Rodolfo', 'Manuel', 'Agbayani', 1, 'Barangay Hall, Purok 1, Sitio Centro'],
        ['Teresita', 'Cabacungan', 'Domingo', 2, 'Barangay Hall, Purok 2, Sitio Pagasa'],
        ['Eduardo', 'Tagorda', 'Pascua', 3, 'Barangay Hall, Purok 1, Sitio Ugad Proper'],
    ];

    private const PATIENTS = ['Lolo Bienvenido Agcaoili', 'Lola Felicidad Pagaduan', 'Estrella Cabatbat', 'Norma Tumaneng', 'Danilo Gaoat', 'Erlinda Rabago', 'Ramon Villanueva', 'Fe Mendoza', 'Aurelio Dumlao', 'Perla Castillo', 'Jaime Balauag', 'Leonora Sibayan'];

    private const DRIVERS = ['Rodel Cabantog', 'Jimmy Alvarez', 'Leonardo Tagorda', 'Mario Gaoat'];

    private const HOSPITALS = ['Southern Isabela Medical Center, Santiago City', 'Cauayan District Hospital'];

    // kind => [pickup hour (Manila), stay hours, destinations, notes, age range]
    private const KINDS = [
        'dialysis' => [5, 4, ['Southern Isabela Medical Center, Santiago City'], ['Hemodialysis session. Wheelchair-bound, stable.', 'Regular dialysis (3x weekly). Needs stretcher assist.'], [46, 72]],
        'prenatal' => [8, 3, ['Echague District Hospital', 'Cauayan District Hospital'], ['Prenatal checkup, 34 weeks AOG. No labor signs.', 'Prenatal checkup with ultrasound, high-risk pregnancy follow-up.'], [18, 38]],
        'transfer' => [9, 4, self::HOSPITALS, ['Non-emergency transfer for CT scan. Patient stable.', 'Transfer for cardiology consult. Stable, with companion.', 'Transfer for laboratory work-up. Stable, walking assist.'], [30, 80]],
    ];

    // P Pending, B Booked (unapproved), A Booked (approved), R Resolved, D Disapproved, C Cancelled; day offset from today.
    private const AMBULANCE_PLAN = [
        ['R', -57, 'dialysis'], ['R', -53, 'prenatal'], ['R', -49, 'transfer'], ['R', -46, 'dialysis'], ['R', -42, 'prenatal'],
        ['R', -38, 'transfer'], ['R', -33, 'dialysis'], ['R', -29, 'prenatal'], ['R', -24, 'transfer'], ['R', -20, 'dialysis'],
        ['R', -15, 'prenatal'], ['R', -11, 'transfer'], ['R', -7, 'dialysis'], ['R', -3, 'prenatal'],
        ['D', -45, 'transfer'], ['D', -26, 'prenatal'], ['D', -10, 'transfer'],
        ['C', -36, 'dialysis'], ['C', -18, 'prenatal'], ['C', -6, 'transfer'],
        ['P', 2, 'prenatal'], ['P', 5, 'dialysis'], ['P', 9, 'transfer'],
        ['B', 4, 'transfer'], ['B', 8, 'prenatal'],
        ['A', 1, 'dialysis'], ['A', 3, 'prenatal'], ['A', 6, 'transfer'],
    ];

    private const DENY_AMBULANCE = ['No ambulance available on the requested date. Please rebook.', 'Incomplete patient details. Please resubmit with a contact number.', 'Trip is emergency-level; call the MDRRMO hotline instead.'];

    private const DENY_SERVICE = ['Outside MDRRMO service coverage.', 'Incomplete details. Please resubmit with an exact landmark.', 'Schedule conflicts with an ongoing MDRRMO activity. Choose another date.', 'No unit available on that date.'];

    // [service code, status, days ago]; future rows (Pending/Booked) carry a preferred date in the next 14 days.
    private const SERVICE_PLAN = [
        ['relief-goods-distribution', 'Resolved', 40], ['relief-goods-distribution', 'Resolved', 22], ['relief-goods-distribution', 'Disapproved', 30],
        ['road-clearing', 'Resolved', 50], ['road-clearing', 'Resolved', 33], ['road-clearing', 'Resolved', 12], ['road-clearing', 'Disapproved', 44], ['road-clearing', 'Cancelled', 20],
        ['debris-removal', 'Resolved', 47], ['debris-removal', 'Resolved', 26], ['debris-removal', 'Resolved', 9], ['debris-removal', 'Cancelled', 15],
        ['sandbagging', 'Resolved', 38], ['sandbagging', 'Resolved', 17], ['sandbagging', 'Resolved', 5], ['sandbagging', 'Disapproved', 24],
        ['animal-rescue', 'Resolved', 31], ['animal-rescue', 'Resolved', 8],
        ['power-line-repair', 'Resolved', 19], ['power-line-repair', 'Resolved', 4],
        ['drrm-trainings-and-seminars', 'Resolved', 45], ['drrm-trainings-and-seminars', 'Resolved', 30], ['drrm-trainings-and-seminars', 'Disapproved', 21],
        ['drrm-trainings-and-seminars', 'Pending', 2], ['drrm-trainings-and-seminars', 'Booked', 4],
        ['simulation-drills-nsed', 'Resolved', 36], ['simulation-drills-nsed', 'Pending', 1], ['simulation-drills-nsed', 'Booked', 5],
        ['mdrrmo-certification', 'Resolved', 28], ['mdrrmo-certification', 'Disapproved', 14], ['mdrrmo-certification', 'Cancelled', 7],
        ['others', 'Resolved', 42], ['others', 'Resolved', 27], ['others', 'Resolved', 13], ['others', 'Cancelled', 10], ['others', 'Booked', 3], ['others', 'Pending', 1],
    ];

    private const DESC = [
        'relief-goods-distribution' => ['Relief packs for families affected by the recent flooding.', 'Rice and canned goods for households displaced by the river overflow.'],
        'road-clearing' => ['Fallen acacia tree blocking the barangay road after the storm.', 'Landslide debris on the feeder road to the farm lots.'],
        'debris-removal' => ['Fallen branches and roofing sheets piled beside the school gate.', 'Clogged canal debris after heavy rain, needs hauling.'],
        'sandbagging' => ['Sandbags needed along the creek bank before the rainy season.', 'Riverbank section eroding near the purok, needs sandbagging.'],
        'animal-rescue' => ['Carabao stuck in a muddy irrigation canal.', 'Stray dog trapped in a drainage culvert.'],
        'power-line-repair' => ['Sagging power line over the road after the storm.', 'Line hanging low near the covered court.'],
        'drrm-trainings-and-seminars' => ['Basic DRRM seminar for our members.', 'First aid and basic life support training request.'],
        'simulation-drills-nsed' => ['Earthquake drill (NSED) for our school and barangay.', 'Fire and evacuation simulation drill request.'],
        'mdrrmo-certification' => ['Certification needed for our disaster preparedness plan.', 'Certification for our organization records.'],
        'others' => ['Rescue vehicle to transport chairs and tents for the barangay fiesta.', 'Vehicle request for the barangay clean-up drive.', 'Vehicle to carry participants to the district sports meet.'],
    ];

    private const LANDMARKS = ['Beside the barangay hall', 'Near the elementary school', 'Across the covered court', 'By the chapel', 'Near the irrigation canal'];

    // item => [min qty, max qty, borrowers (h household / i institution), purposes]
    private const LOANS = [
        'Tent (10x10)' => [1, 3, 'hi', ['Barangay fiesta', 'Wake (burol)', 'Baptism celebration', 'School feeding program']],
        'Monobloc Chair' => [20, 50, 'hi', ['Barangay assembly', 'Wake (burol)', 'Sports fest', 'PTA general meeting']],
        'Generator (5 kVA)' => [1, 1, 'i', ['Barangay fiesta program', 'Medical mission', 'Youth night']],
        'Megaphone' => [1, 2, 'i', ['Clean-up drive', 'Barangay assembly', 'Sports fest']],
        'Rescue Boat' => [1, 1, 'i', ['Flood-response drill', 'Water rescue training']],
        'First Aid Kit' => [2, 4, 'i', ['Sports fest', 'Medical mission', 'School field trip']],
        'Wheelchair' => [1, 1, 'h', ['Post-surgery recovery at home', 'Elderly relative visiting']],
        'Crutches (pair)' => [1, 2, 'h', ['Fractured ankle recovery']],
        'Walker' => [1, 1, 'h', ['Recovering grandparent']],
        'Hospital Bed' => [1, 1, 'h', ['Bedridden family member']],
        'Oxygen Tank' => [1, 2, 'h', ['Patient with COPD at home']],
        'Nebulizer' => [1, 1, 'h', ['Child with asthma']],
    ];

    private const NEW_EQUIPMENT = ['Tent (10x10)' => 12, 'Monobloc Chair' => 200, 'Generator (5 kVA)' => 3, 'Megaphone' => 4, 'Rescue Boat' => 2, 'First Aid Kit' => 15];

    private CarbonImmutable $now;

    private array $admins = [];

    private array $vehicles = [];   // vehicle_id => type

    private array $vehicleLabels = [];

    private array $busy = [];       // vehicle_id => [[from, to]]

    private array $residents = [];  // resident_id => details

    private array $logs = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder refuses to run unless APP_ENV=local (env: '.app()->environment().').');
        }

        // Nothing here calls a controller, but keep any stray send fake.
        Http::fake();
        Mail::fake();
        Notification::fake();
        mt_srand(20260924);
        $this->now = CarbonImmutable::now()->startOfMinute();

        // Outside withoutEvents: Service::creating derives the service code.
        $this->call([
            BarangaySeeder::class, AdminSeeder::class, SmsBlastCodeSeeder::class, ServiceSeeder::class,
            EquipmentSeeder::class, VehicleSeeder::class, AmbulanceDestinationSeeder::class,
        ]);
        DB::table('tbl_system_logs')->delete(); // rows the base seeders wrote with no actor
        DB::table('tbl_ambulance_destinations')->insert(array_map(
            fn ($name) => ['name' => $name, 'created_at' => $this->now, 'updated_at' => $this->now], self::HOSPITALS
        ));

        Model::withoutEvents(function () {
            $this->staff();
            $this->residents();
            $this->ambulance();
            $this->services();
            $this->borrowings();
            $this->flushLogs();
        });

        $this->call(DemoResponderSeeder::class);

        Storage::disk(config('filesystems.uploads.private'))->put(
            self::PLACEHOLDER,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
    }

    private function staff(): void
    {
        DB::table('tbl_user')->where('email_address', 'admin@serbis.com')->update(['is_super_admin' => 1]);

        foreach ([['Maria Elena', 'Pascual', 'maria.pascual@serbis.com'], ['Ramil', 'Cabacungan', 'ramil.cabacungan@serbis.com']] as [$first, $last, $email]) {
            DB::table('tbl_user')->insert([
                'first_name' => $first, 'last_name' => $last, 'role' => 'Admin', 'status' => 'Active',
                'email_address' => $email, 'password' => Hash::make('password123'),
                'created_at' => $this->now->subDays(120), 'updated_at' => $this->now->subDays(120),
            ]);
        }

        $this->admins = DB::table('tbl_user')->pluck('admin_id')->all();
        $this->vehicles = DB::table('tbl_vehicles')->pluck('type', 'vehicle_id')->all();
        $this->vehicleLabels = DB::table('tbl_vehicles')->get()
            ->mapWithKeys(fn ($v) => [$v->vehicle_id => $v->unit_identifier.($v->specification ? " ({$v->specification})" : '')])->all();
    }

    private function residents(): void
    {
        $password = Hash::make('Passw0rd!123');
        $rows = [];
        foreach (self::HALLS as [$f, $m, $l, $b, $s]) {
            $rows[] = [$f, $m, $l, $b, $s, 'barangay', null, 'Active'];
        }
        foreach (self::ORGS as [$f, $m, $l, $b, $s, $org, $status]) {
            $rows[] = [$f, $m, $l, $b, $s, 'organization', $org, $status];
        }
        foreach (self::HEADS as [$f, $m, $l, $b, $s]) {
            $rows[] = [$f, $m, $l, $b, $s, 'head_of_family', null, 'Active'];
        }

        $prefixes = ['17', '18', '20', '27', '35', '45', '55', '75'];

        foreach ($rows as $i => [$first, $middle, $last, $brgy, $street, $type, $org, $status]) {
            $created = $this->now->subDays(mt_rand(75, 130))->setTime(mt_rand(1, 9), mt_rand(0, 59));
            $resident = new Resident([
                'barangay_id' => $brgy, 'street_address' => $street, 'first_name' => $first, 'middle_name' => $middle,
                'last_name' => $last, 'phone_number' => '+639'.$prefixes[$i % 8].sprintf('%07d', 2000000 + $i * 37141),
                'password' => $password, 'status' => $status, 'sms_opt_in' => true,
            ]);
            $resident->forceFill([
                'account_type' => $type, 'organization_name' => $org,
                'phone_verified_at' => $created->addHour(), 'created_at' => $created, 'updated_at' => $created,
            ])->save();

            $this->residents[$resident->resident_id] = [
                'type' => $type, 'status' => $status, 'brgy' => $brgy, 'street' => $street, 'org' => $org,
                'name' => "$first $last", 'phone' => $resident->phone_number,
            ];
            $this->log(Resident::class, $resident->resident_id, 'created', null, $this->snap($resident), $created, $this->admin(), null);
        }
    }

    private function ambulance(): void
    {
        $serviceId = DB::table('tbl_services')->where('code', 'ambulance-medical-response')->value('service_id');

        foreach (self::AMBULANCE_PLAN as [$code, $day, $kind]) {
            [$hour, $stay, $destinations, $notes, $ages] = self::KINDS[$kind];
            $filerId = $this->filer(['head_of_family', 'barangay']);
            $filer = $this->residents[$filerId];
            $isPast = $day < 0;
            $status = ['P' => 'Pending', 'B' => 'Booked', 'A' => 'Booked', 'R' => 'Resolved', 'D' => 'Disapproved', 'C' => 'Cancelled'][$code];

            $scheduled = $this->at($day, $hour, [0, 15, 30, 45][mt_rand(0, 3)]);
            $created = $isPast
                ? $scheduled->subDays(mt_rand(2, 6))->subHours(mt_rand(0, 8))
                : $this->now->subHours(mt_rand(26, 70));
            $approved = $created->addMinutes(mt_rand(30, 240));
            $dest = $destinations[array_rand($destinations)];
            $address = "{$filer['street']}, Brgy. ".self::BARANGAYS[$filer['brgy']].', '.self::TOWN;

            $req = ['resident_id' => $filerId, 'service_id' => $serviceId, 'valid_id' => self::PLACEHOLDER, 'status' => $status,
                'description' => "Non-emergency $kind trip to $dest", 'created_at' => $created, 'updated_at' => $created];
            $booking = ['patient_name' => self::PATIENTS[array_rand(self::PATIENTS)], 'patient_age' => mt_rand($ages[0], $ages[1]),
                'patient_address' => $address, 'patient_contact_number' => $filer['phone'],
                'pickup_location' => $address, 'destination' => $dest, 'condition_notes' => $notes[array_rand($notes)],
                'scheduled_at' => $scheduled, 'created_at' => $created, 'updated_at' => $created];
            $trip = null;
            $steps = []; // [old, new attributes, at, actor]

            if ($code === 'R') {
                $depart = $scheduled->subMinutes(mt_rand(15, 30));
                $arrive = $scheduled->addMinutes(mt_rand(40, 75));
                $leave = $arrive->addMinutes(($stay - 1) * 60 + mt_rand(0, 30));
                $back = $leave->addMinutes(mt_rand(40, 75));
                $end = $back->addMinutes(15);
                $vehicleId = $this->claim(['Ambulance'], $depart, $end);
                $resolved = $back->addMinutes(10);

                $req += ['processed_by' => $this->admin(), 'vehicle_id' => $vehicleId, 'first_responded_at' => $approved, 'resolved_at' => $resolved, 'updated_at' => $resolved];
                $booking += ['scheduled_end' => $end, 'approved_at' => $approved, 'updated_at' => $resolved];
                $trip = compact('depart', 'arrive', 'leave', 'back');
                $steps = [
                    [['status' => 'Pending'], ['status' => 'Booked', 'vehicle_id' => $vehicleId], $approved],
                    [['status' => 'Booked'], ['status' => 'Responding'], $depart],
                    [['status' => 'Responding'], ['status' => 'Resolved'], $resolved],
                ];
            } elseif ($code === 'D') {
                $at = $created->addHours(mt_rand(1, 20));
                $req += ['processed_by' => $this->admin(), 'first_responded_at' => $at, 'resolved_at' => $at, 'updated_at' => $at,
                    'remarks' => self::DENY_AMBULANCE[array_rand(self::DENY_AMBULANCE)]];
                $steps = [[['status' => 'Pending'], ['status' => 'Disapproved', 'remarks' => $req['remarks']], $at]];
            } elseif ($code === 'C') {
                $at = $created->addHours(mt_rand(3, 30));
                $req += ['resolved_at' => $at, 'updated_at' => $at];
                $steps = [[['status' => 'Pending'], ['status' => 'Cancelled'], $at, true]];
            } elseif ($code === 'A') {
                $end = $scheduled->addHours($stay)->addMinutes(15);
                $vehicleId = $this->claim(['Ambulance'], $scheduled->subMinutes(30), $end);
                $req += ['processed_by' => $this->admin(), 'vehicle_id' => $vehicleId, 'first_responded_at' => $approved, 'updated_at' => $approved];
                $booking += ['scheduled_end' => $end, 'approved_at' => $approved, 'updated_at' => $approved];
                $steps = [[['status' => 'Pending'], ['status' => 'Booked', 'vehicle_id' => $vehicleId], $approved]];
            }

            $request = ServiceRequest::forceCreate($req);
            $bookingRow = AmbulanceBooking::forceCreate($booking + ['request_id' => $request->request_id]);
            $this->log(ServiceRequest::class, $request->request_id, 'created', null, $this->snap($request), $created, null, $filerId);
            $this->log(AmbulanceBooking::class, $request->request_id, 'created', null, $this->snap($bookingRow), $created, null, $filerId);
            $this->transitions(ServiceRequest::class, $request->request_id, $steps, $req['processed_by'] ?? null, $filerId);

            if ($trip) {
                $km = mt_rand(70, 120);
                $odometer = mt_rand(18000, 64000);
                $conduction = ConductionRequest::forceCreate([
                    'service_request_id' => $request->request_id, 'vehicle_id' => $req['vehicle_id'],
                    'patient_name' => $booking['patient_name'], 'patient_age' => $booking['patient_age'],
                    'patient_address' => $address, 'patient_contact_number' => $filer['phone'],
                    'vehicle' => $this->vehicleLabels[$req['vehicle_id']], 'medical_diagnosis' => $booking['condition_notes'],
                    'origin' => $address, 'destination' => $dest,
                    'departed_office_at' => $trip['depart'], 'arrived_destination_at' => $trip['arrive'],
                    'departed_destination_at' => $trip['leave'], 'returned_office_at' => $trip['back'],
                    'odometer_start' => $odometer, 'odometer_end' => $odometer + $km,
                    'created_at' => $trip['depart'], 'updated_at' => $trip['back'],
                ]);
                ConductionRequestPerson::forceCreate(['conduction_request_id' => $conduction->conduction_request_id, 'role' => 'driver', 'name' => self::DRIVERS[array_rand(self::DRIVERS)], 'position' => 0, 'created_at' => $trip['depart'], 'updated_at' => $trip['depart']]);
                ConductionRequestPerson::forceCreate(['conduction_request_id' => $conduction->conduction_request_id, 'role' => 'relative', 'name' => $filer['name'], 'position' => 0, 'created_at' => $trip['depart'], 'updated_at' => $trip['depart']]);
                $this->log(ConductionRequest::class, $conduction->conduction_request_id, 'created', null, $this->snap($conduction), $trip['depart'], $req['processed_by'], null);
            }
        }
    }

    private function services(): void
    {
        $ids = DB::table('tbl_services')->pluck('service_id', 'code');
        $audience = DB::table('tbl_service_audience')->get()->groupBy('service_code')->map->pluck('account_type');
        $rescueTypes = ['animal-rescue' => ['Rescue Vehicle', 'Boat']];

        foreach (self::SERVICE_PLAN as [$code, $status, $ago]) {
            $filerId = $this->filer($audience[$code]->all());
            $filer = $this->residents[$filerId];
            $isProgram = in_array($code, ['drrm-trainings-and-seminars', 'simulation-drills-nsed'], true);
            $hasDate = $isProgram || $code === 'others';
            $created = $this->at(-$ago, mt_rand(8, 16), mt_rand(0, 59));
            $isFuture = in_array($status, ['Pending', 'Booked'], true);
            $processedBy = null;
            $pref = null;
            if ($hasDate) {
                // Past events fell after filing (lead 15 days for programs, 5 otherwise); open ones are in the next 14 days.
                $pref = $isFuture ? $this->at(mt_rand(10, 13), 8) : $created->setTimezone(self::TZ)->startOfDay()->addDays($isProgram ? 15 : 5)->setTime(8, 0)->utc();
            }

            $req = ['resident_id' => $filerId, 'service_id' => $ids[$code] ?? null, 'status' => $status,
                'description' => self::DESC[$code][array_rand(self::DESC[$code])],
                'valid_id' => $isProgram ? null : self::PLACEHOLDER,
                'letter' => $isProgram ? self::PLACEHOLDER : null,
                'preferred_date' => $pref?->setTimezone(self::TZ)->toDateString(),
                'landmark' => in_array($code, ['road-clearing', 'debris-removal', 'sandbagging', 'animal-rescue', 'power-line-repair'], true) ? self::LANDMARKS[array_rand(self::LANDMARKS)] : null,
                'created_at' => $created, 'updated_at' => $created];
            if ($code === 'relief-goods-distribution') {
                $req += ['fulfillment_method' => 'Delivery', 'delivery_address' => 'Barangay Hall, '.self::BARANGAYS[$filer['brgy']].', '.self::TOWN];
            }

            $steps = [];
            $first = $created->addHours(mt_rand(3, 28));
            if ($status === 'Booked') {
                $processedBy = $this->admin();
                $req += ['processed_by' => $processedBy, 'first_responded_at' => $first, 'updated_at' => $first];
                $steps = [[['status' => 'Pending'], ['status' => 'Booked'], $first]];
            } elseif ($status === 'Resolved') {
                $processedBy = $this->admin();
                // Event services close on the day; the rest a day or two after approval.
                $resolved = $hasDate ? $pref->setTimezone(self::TZ)->setTime(17, 0)->utc() : $first->addHours(mt_rand(6, 40));
                $vehicleId = null;
                if (! $isProgram && $code !== 'mdrrmo-certification') {
                    $vehicleId = $this->claim($rescueTypes[$code] ?? ['Rescue Vehicle'], $resolved->subHours($code === 'others' ? 10 : 4), $resolved);
                }
                $req += ['processed_by' => $processedBy, 'vehicle_id' => $vehicleId, 'first_responded_at' => $first, 'resolved_at' => $resolved, 'updated_at' => $resolved];
                $steps = [
                    [['status' => 'Pending'], ['status' => 'Booked', 'vehicle_id' => $vehicleId], $first],
                    [['status' => 'Booked'], ['status' => 'Responding'], $resolved->subHours(2)],
                    [['status' => 'Responding'], ['status' => 'Resolved'], $resolved],
                ];
            } elseif ($status === 'Disapproved') {
                $processedBy = $this->admin();
                $req += ['processed_by' => $processedBy, 'first_responded_at' => $first, 'resolved_at' => $first, 'updated_at' => $first,
                    'remarks' => self::DENY_SERVICE[array_rand(self::DENY_SERVICE)]];
                $steps = [[['status' => 'Pending'], ['status' => 'Disapproved', 'remarks' => $req['remarks']], $first]];
            } elseif ($status === 'Cancelled') {
                $at = $created->addHours(mt_rand(1, 20));
                $req += ['resolved_at' => $at, 'updated_at' => $at];
                $steps = [[['status' => 'Pending'], ['status' => 'Cancelled'], $at, true]];
            }

            $request = ServiceRequest::forceCreate($req);
            $this->log(ServiceRequest::class, $request->request_id, 'created', null, $this->snap($request), $created, null, $filerId);
            $this->transitions(ServiceRequest::class, $request->request_id, $steps, $processedBy, $filerId);
        }
    }

    private function borrowings(): void
    {
        foreach (self::NEW_EQUIPMENT as $name => $total) {
            Equipment::forceCreate(['item_name' => $name, 'total_quantity' => $total, 'available_quantity' => $total, 'status' => 'Available',
                'created_at' => $this->now->subDays(100), 'updated_at' => $this->now->subDays(100)]);
        }
        $stock = Equipment::all()->keyBy('item_name');
        $items = array_keys(self::LOANS);
        $plan = array_merge(...array_map(fn ($s, $n) => array_fill(0, $n, $s),
            ['Returned', 'Released', 'Approved', 'Pending', 'Denied', 'Cancelled'], [16, 5, 4, 4, 4, 3]));
        $held = [];    // equipment_id => [[from, to, qty]]
        $outNow = [];  // equipment_id => qty currently Released

        foreach ($plan as $status) {
            for ($try = 0; ; $try++) {
                if ($try > 200) {
                    throw new RuntimeException("Could not place a $status loan within stock.");
                }
                $item = $items[array_rand($items)];
                [$min, $max, $who, $purposes] = self::LOANS[$item];
                $equipment = $stock[$item];
                $qty = mt_rand($min, $max);

                // Timeline; only Released and Returned rows hold stock.
                $days = mt_rand(1, 5);
                if ($status === 'Released') {
                    $released = $this->now->subDays(mt_rand(1, 3))->subHours(mt_rand(0, 6));
                    $approved = $released->subHours(mt_rand(3, 8));
                    $created = $approved->subHours(mt_rand(2, 6));
                } else {
                    $created = match ($status) {
                        'Pending' => $this->now->subHours(mt_rand(3, 40)),
                        'Approved' => $this->now->subDays(mt_rand(2, 4))->subHours(mt_rand(0, 8)),
                        default => $this->now->subDays(mt_rand(9, 58)),
                    };
                    $approved = $created->addHours(mt_rand(2, 10));
                    $released = $approved->addHours(mt_rand(2, 20));
                }
                $returned = $released->addDays($days)->subHours(mt_rand(0, 5));
                $from = $released;
                $to = $status === 'Released' ? $this->now->addDays(30) : $returned;
                if (in_array($status, ['Released', 'Returned'], true) && ! $this->fits($held[$equipment->equipment_id] ?? [], $from, $to, $qty, $equipment->total_quantity)) {
                    continue;
                }
                break;
            }

            $householdOnly = $who === 'h';
            $filerId = $this->filer($householdOnly ? ['head_of_family'] : ($who === 'i' ? ['barangay', 'organization'] : ['head_of_family', 'barangay', 'organization']));
            $filer = $this->residents[$filerId];
            $institution = $filer['type'] !== 'head_of_family';
            $delivery = $institution && mt_rand(0, 3) === 0;
            $row = ['resident_id' => $filerId, 'equipment_id' => $equipment->equipment_id, 'quantity' => $qty,
                'purpose' => $purposes[array_rand($purposes)],
                'fulfillment_method' => $delivery ? 'Delivery' : 'Pickup',
                'delivery_address' => $delivery ? "{$filer['street']}, Brgy. ".self::BARANGAYS[$filer['brgy']].', '.self::TOWN : null,
                'borrower_type' => $institution ? 'Organization' : 'Resident',
                'organization_name' => $institution ? ($filer['org'] ?? 'Barangay '.self::BARANGAYS[$filer['brgy']]) : null,
                'status' => $status, 'created_at' => $created, 'updated_at' => $created];
            $steps = [];
            $admin = $this->admin();

            if (in_array($status, ['Approved', 'Released', 'Returned'], true)) {
                $due = $status === 'Returned' ? $released->addDays($days) : $this->at(mt_rand(1, 4));
                $row += ['due_date' => $due->setTimezone(self::TZ)->toDateString(), 'updated_at' => $approved];
                $steps[] = [['status' => 'Pending'], ['status' => 'Approved', 'due_date' => $row['due_date']], $approved];
            }
            if (in_array($status, ['Released', 'Returned'], true)) {
                $row += ['released_at' => $released, 'release_photo_path' => self::PLACEHOLDER, 'updated_at' => $released];
                $steps[] = [['status' => 'Approved'], ['status' => 'Released', 'released_at' => $released->toDateTimeString()], $released];
                $held[$equipment->equipment_id][] = [$from, $to, $qty];
            }
            if ($status === 'Released') {
                $outNow[$equipment->equipment_id] = ($outNow[$equipment->equipment_id] ?? 0) + $qty;
            }
            if ($status === 'Returned') {
                $bad = mt_rand(0, 5) === 0;
                $row += ['returned_at' => $returned, 'return_photo_path' => self::PLACEHOLDER, 'return_condition' => $bad ? 'Bad' : 'Good',
                    'return_condition_note' => $bad ? 'Minor damage, noted on return.' : 'Returned complete and in good condition.', 'updated_at' => $returned];
                $steps[] = [['status' => 'Released'], ['status' => 'Returned', 'returned_at' => $returned->toDateTimeString()], $returned];
            }
            if ($status === 'Denied') {
                $at = $created->addHours(mt_rand(2, 30));
                $unavailable = mt_rand(0, 1) === 0;
                $row += ['denial_reason' => $unavailable ? 'Item already reserved for another borrower on those dates.' : 'Purpose not covered by the lending policy.',
                    'denial_reason_code' => $unavailable ? 'Unavailable' : 'Other', 'updated_at' => $at];
                $steps[] = [['status' => 'Pending'], ['status' => 'Denied', 'denial_reason' => $row['denial_reason']], $at];
            }
            if ($status === 'Cancelled') {
                $at = $created->addHours(mt_rand(2, 40));
                $row['updated_at'] = $at;
                $steps[] = [['status' => 'Pending'], ['status' => 'Cancelled'], $at, true];
            }

            $loan = EquipmentBorrowing::forceCreate($row);
            $this->log(EquipmentBorrowing::class, $loan->borrow_id, 'created', null, $this->snap($loan), $created, null, $filerId);
            $this->transitions(EquipmentBorrowing::class, $loan->borrow_id, $steps, $admin, $filerId);
        }

        // Stock is a stored column: total minus what is out on Released loans.
        foreach ($stock as $equipment) {
            $available = $equipment->total_quantity - ($outNow[$equipment->equipment_id] ?? 0);
            DB::table('tbl_equipments')->where('equipment_id', $equipment->equipment_id)
                ->update(['available_quantity' => $available, 'status' => $available > 0 ? 'Available' : 'Unavailable', 'updated_at' => $this->now]);
        }
    }

    /** Manila-local day offset from today at hh:mm, as a UTC instant. */
    private function at(int $days, int $hour = 9, int $minute = 0): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay()->addDays($days)->setTime($hour, $minute)->utc();
    }

    private function admin(): int
    {
        return $this->admins[array_rand($this->admins)];
    }

    /** A random Active account of one of the given types. */
    private function filer(array $types): int
    {
        $ids = array_keys(array_filter($this->residents, fn ($r) => $r['status'] === 'Active' && in_array($r['type'], $types, true)));

        return $ids[array_rand($ids)];
    }

    /** First-fit unit of the given types that is free for [from, to]; starts at a random unit so load spreads. */
    private function claim(array $types, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $ids = array_keys(array_filter($this->vehicles, fn ($t) => in_array($t, $types, true)));
        $offset = mt_rand(0, count($ids) - 1);

        for ($i = 0; $i < count($ids); $i++) {
            $id = $ids[($i + $offset) % count($ids)];
            foreach ($this->busy[$id] ?? [] as [$busyFrom, $busyTo]) {
                if ($from < $busyTo && $to > $busyFrom) {
                    continue 2;
                }
            }
            $this->busy[$id][] = [$from, $to];

            return $id;
        }

        throw new RuntimeException('No free '.implode('/', $types).' unit for '.$from->toDateTimeString());
    }

    /** Conservative: sums every overlapping loan, whether or not they all overlap each other. */
    private function fits(array $held, CarbonImmutable $from, CarbonImmutable $to, int $qty, int $total): bool
    {
        $sum = $qty;
        foreach ($held as [$heldFrom, $heldTo, $heldQty]) {
            if ($from < $heldTo && $to > $heldFrom) {
                $sum += $heldQty;
            }
        }

        return $sum <= $total;
    }

    /** Status steps as audit rows: [old, new, at, byResident?]. Staff steps are attributed to $admin. */
    private function transitions(string $model, int $id, array $steps, ?int $admin, int $residentId): void
    {
        foreach ($steps as $step) {
            [$old, $new, $at] = $step;
            $byResident = $step[3] ?? false;
            $new = array_map(fn ($v) => $v instanceof CarbonImmutable ? $v->toDateTimeString() : $v, $new);
            $this->log($model, $id, 'updated', $old, $new, $at, $byResident ? null : $admin, $byResident ? $residentId : null);
        }
    }

    private function log(string $model, int $id, string $action, ?array $old, ?array $new, CarbonImmutable $at, ?int $admin, ?int $resident): void
    {
        $this->logs[] = [
            'admin_id' => $admin, 'resident_id' => $resident, 'action_type' => $action,
            'auditable_type' => $model, 'auditable_id' => $id,
            'old_values' => $old ? json_encode($old) : null, 'new_values' => $new ? json_encode($new) : null,
            'ip_address' => '127.0.0.1', 'user_agent' => 'DemoSeeder',
            'created_at' => $at->toDateTimeString(), 'updated_at' => $at->toDateTimeString(),
        ];
    }

    private function flushLogs(): void
    {
        usort($this->logs, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
        foreach (array_chunk($this->logs, 200) as $chunk) {
            DB::table('tbl_system_logs')->insert($chunk);
        }
    }

    /** The row as stored, minus what TracksHistory ignores. */
    private function snap(Model $model): array
    {
        return Arr::except($model->fresh()->getAttributes(), ['created_at', 'updated_at', 'password', 'remember_token', 'photo']);
    }
}
