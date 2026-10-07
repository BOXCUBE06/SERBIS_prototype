<?php

namespace Database\Seeders;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\ConductionRequest;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Responder;
use App\Models\Service;
use App\Models\ServiceAudience;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRelative;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Realistic request data for a local panel: ~310 service and ambulance
 * requests over six months and ~80 equipment borrowings. Run through
 * `serbis:reseed-requests`, which wipes first and reconciles stock, fleet and
 * responders afterwards; this class only generates.
 *
 * Every row goes through its model, and each status change runs with the clock
 * set to that moment, so ServiceRequest::booted() sets barangay_id and the
 * lifecycle stamps and system logs carry historical times.
 *
 * Barangay spread: a Head of the Family is moved, quietly and without touching
 * updated_at, to one of up to four former barangays for one filing,
 * then moved back. Requests land in more barangays than residents live in, and
 * those rows read as "filed before they moved".
 */
class RequestDataSeeder extends Seeder
{
    public int $seed = 1;

    /*
     * Volume knobs, set by `serbis:reseed-requests --volume`. The defaults are the
     * six-month set; with $weighted, filing times follow growth, a weekend dip and
     * the typhoon season, and a few residents file most requests.
     */
    public int $spanDays = 180;

    /** Open statuses (Pending, Booked) are only ever this recent. */
    public int $openDays = 20;

    /** How far back Denied and Cancelled loans may reach. */
    public int $finalLoanDays = 30;

    public bool $weighted = false;

    /** Share of loans for something not in the catalogue. */
    public int $otherEquipmentPercent = 0;

    /** @var array<string, int>|null status => count */
    public ?array $servicePlan = null;

    public ?array $ambulancePlan = null;

    public ?array $loanPlan = null;

    /** @var array<int, float> resident_id => how often they file (0: never) */
    private array $weights = [];

    private const AMBULANCE = 'ambulance-medical-response';

    private const OTHERS = 'others';

    private const PROGRAMS = ['drrm-trainings-and-seminars', 'simulation-drills-nsed', 'mdrrmo-certification'];

    private const SCHEDULED_PROGRAMS = ['drrm-trainings-and-seminars', 'simulation-drills-nsed'];

    private const HOSPITALS = ['Echague District Hospital', 'Southern Isabela Medical Center', 'Isabela Provincial Hospital', 'Santiago Medical City'];

    private const FIRST = ['Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Rosa', 'Carlo', 'Liza', 'Ramon', 'Teresa', 'Mark', 'Joy', 'Ernesto', 'Lorna'];

    private const LAST = ['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Agbayani', 'Pascual', 'Domingo', 'Ramos', 'Tumaliuan', 'Bautista', 'Gumabay'];

    private const CONDITIONS = ['Difficulty breathing', 'High fever for three days', 'Fall at home, possible hip fracture', 'Labor pains, first child', 'Dialysis schedule', 'Weak and dizzy, diabetic', 'Chest pain'];

    private const DESCRIPTIONS = [
        'relief-goods-distribution' => ['Flooded since last night, need food packs for 6.', 'Family of 4 evacuated, no supplies.'],
        'road-clearing' => ['Fallen acacia blocking the road to the school.', 'Landslide debris on the farm-to-market road.'],
        'power-line-repair' => ['Sagging line touching the bamboo fence.', 'Post leaning after the storm.'],
        'debris-removal' => ['Debris from a collapsed shed on the creek.', 'Flood debris piled at the drainage.'],
        'sandbagging' => ['Riverbank eroding near the houses.', 'Need sandbags for the purok entrance.'],
        'drrm-trainings-and-seminars' => ['Basic first aid seminar for barangay tanods.', 'DRRM orientation for the council.'],
        'simulation-drills-nsed' => ['Earthquake drill at the elementary school.', 'Fire drill for the market vendors.'],
        'mdrrmo-certification' => ['Certification for a flood-free lot (bank requirement).', 'Calamity certificate for a damaged house.'],
        self::OTHERS => ['Need a tent for a wake, the family lost their house.', 'Requesting a briefing on the evacuation route.'],
    ];

    private const DISAPPROVED = ['Outside our service area.', 'No unit free on that date; please file again.', 'Duplicate of an earlier request.', 'Please coordinate with the barangay first.'];

    private CarbonImmutable $now;

    private Collection $residents;

    /** @var array<int, string> barangay_id => name */
    private array $barangayNames;

    /** @var array<int, list<int>> resident_id => up to four former barangay ids */
    private array $formerHomes = [];

    private Collection $services;

    private ?int $adminId;

    private Collection $responders;

    /** The half of the crew that open (Responding) requests draw from; the rest stay available. */
    private array $onCall;

    private Collection $vehicles;

    /** @var array<string, list<string>> service code => vehicle types */
    private array $unitTypes;

    /** @var array<int, true> units holding a Responding request (one each, by DB index) */
    private array $held = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'RequestDataSeeder skipped: refuses to seed simulated requests outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        mt_srand($this->seed);
        $this->now = CarbonImmutable::now();

        $this->residents = Resident::where('status', 'Active')->orderBy('resident_id')->get();
        $this->barangayNames = Barangay::orderBy('barangay_id')->pluck('barangay_name', 'barangay_id')->all();
        $this->services = Service::all()->keyBy('code');
        $this->adminId = User::orderBy('admin_id')->value('admin_id');
        $this->responders = Responder::orderBy('responder_id')->get();
        $this->onCall = $this->responders->take(intdiv($this->responders->count(), 2))->all();
        $this->vehicles = Vehicle::where('status', '!=', 'Maintenance')->orderBy('vehicle_id')->get();
        $this->unitTypes = DB::table('tbl_service_vehicle_types')->get()
            ->groupBy('service_code')->map(fn ($rows) => $rows->pluck('vehicle_type')->all())->all();
        $this->unitTypes[self::AMBULANCE] = ['Ambulance'];

        $ids = array_keys($this->barangayNames);
        foreach ($this->residents as $r) {
            if ($r->isHeadOfFamily()) {
                $this->formerHomes[$r->resident_id] = array_values(array_unique(array_map(fn () => $this->pick($ids), range(1, 4))));
            }
        }

        if ($this->weighted) {
            // Zipf by a seeded shuffle; the last quarter never files.
            $order = $this->residents->pluck('resident_id')->all();
            for ($i = count($order) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            }
            foreach ($order as $rank => $id) {
                $this->weights[$id] = $rank >= count($order) * 0.75 ? 0.0 : 1 / ($rank + 1) ** 0.8;
            }
        }

        try {
            $this->serviceRequests();
            $this->ambulanceRequests();
            $this->borrowings();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function serviceRequests(): void
    {
        $codes = $this->services->keys()->reject(fn ($c) => $c === self::AMBULANCE)->values()->all();
        // Responding first, so their units are held before Resolved rows pass through Responding.
        $plan = $this->servicePlan ?? ['Responding' => 20, 'Pending' => 30, 'Resolved' => 120, 'Cancelled' => 25, 'Disapproved' => 25];

        foreach ($plan as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $code = $this->chance(6) ? self::OTHERS : $this->pick($codes);
                $created = match ($status) {
                    'Responding' => $this->past(0, 6),
                    'Pending' => $this->past(0, $this->openDays),
                    default => $this->past(2, $this->spanDays),
                };
                $by = $this->filer($code, in_array($code, self::PROGRAMS, true) ? 0 : 15, $created);

                $sr = $this->file($by, $created, [
                    'service_id' => $this->services[$code]->service_id ?? null,
                    'description' => $this->pick(self::DESCRIPTIONS[$code] ?? self::DESCRIPTIONS[self::OTHERS]),
                    'landmark' => 'Near the '.$this->pick(['chapel', 'barangay hall', 'covered court', 'school', 'sari-sari store']),
                    'preferred_date' => in_array($code, self::SCHEDULED_PROGRAMS, true)
                        ? $created->addDays(mt_rand(14, 45))->toDateString() : null,
                ]);

                $this->advance($sr, $code, $status, $created->addMinutes(mt_rand(20, 600)));
            }
        }
    }

    private function ambulanceRequests(): void
    {
        $plan = $this->ambulancePlan ?? ['Responding' => 2, 'Booked' => 12, 'Pending' => 8, 'Resolved' => 55, 'Cancelled' => 7, 'Disapproved' => 6];
        $booked = 0;

        foreach ($plan as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $created = match ($status) {
                    'Responding' => $this->now->subMinutes(mt_rand(20, 120)),
                    'Pending' => $this->past(0, 3),
                    'Booked' => $this->past(0, 10),
                    default => $this->past(2, $this->spanDays),
                };
                $by = $this->filer(self::AMBULANCE, 12, $created);
                $patient = $by && $this->chance(40) ? $by->first_name.' '.$by->last_name : $this->name();
                $sr = $this->file($by, $created, [
                    'service_id' => $this->services[self::AMBULANCE]->service_id,
                    'description' => 'Patient: '.$patient,
                ]);

                // Pickup in the barangay the request was filed from.
                $this->at($created);
                AmbulanceBooking::create([
                    'request_id' => $sr->request_id,
                    'patient_name' => $patient,
                    'patient_age' => mt_rand(1, 88),
                    'patient_address' => $this->address($sr),
                    'patient_contact_number' => $sr->walk_in_contact_number ?? $by?->phone_number,
                    'pickup_location' => $this->address($sr),
                    'destination' => $this->pick(self::HOSPITALS),
                    'condition_notes' => $this->pick(self::CONDITIONS),
                ]);
                foreach (array_slice([$this->name(), $this->name()], 0, mt_rand(1, 2)) as $position => $name) {
                    ServiceRequestRelative::create(['service_request_id' => $sr->request_id, 'name' => $name, 'position' => $position]);
                }

                $t = $created->addMinutes(mt_rand(15, 90));
                if ($status === 'Booked') {
                    // One future slot per day, so no unit is double-booked.
                    $start = $this->now->setTimezone('Asia/Manila')->addDays(++$booked)->setTime(9, 0)->utc();
                    $this->book($sr, $t, $start, $this->vehicles->where('type', 'Ambulance')->values()[$booked % 2]->vehicle_id);
                } elseif ($status === 'Resolved' && $this->chance(50)) {
                    // Scheduled trip: booked first, dispatched at the slot.
                    $start = $t->addDay();
                    $this->book($sr, $t, $start, null);
                    $this->advance($sr, self::AMBULANCE, 'Resolved', $start);
                } else {
                    $this->advance($sr, self::AMBULANCE, $status, $t);
                }
            }
        }
    }

    private function book(ServiceRequest $sr, CarbonImmutable $at, CarbonImmutable $start, ?int $vehicleId): void
    {
        $this->at($at);
        $sr->update(['status' => 'Booked', 'processed_by' => $this->adminId, 'vehicle_id' => $vehicleId]);
        // Already reminded, so the reminder command never texts a seeded booking.
        $sr->ambulanceBooking()->first()->update([
            'scheduled_at' => $start,
            'scheduled_end' => $start->addHours(2),
            'approved_at' => $at,
            'scheduled_reminder_sent_at' => $at,
        ]);
    }

    /** Moves a Pending request on to $status, as staff would have. */
    private function advance(ServiceRequest $sr, string $code, string $status, CarbonImmutable $t): void
    {
        if ($status === 'Pending' || $status === 'Booked') {
            return;
        }
        if ($status === 'Cancelled') {
            $this->at($t);
            $sr->update(['status' => 'Cancelled']);

            return;
        }
        if ($status === 'Disapproved') {
            $this->at($t);
            $sr->update(['status' => 'Disapproved', 'remarks' => $this->pick(self::DISAPPROVED), 'processed_by' => $this->adminId]);

            return;
        }

        // Responding, and Resolved through Responding.
        $unit = $this->unitFor($code, keep: $status === 'Responding');
        $this->at($t);
        $sr->update(['status' => 'Responding', 'processed_by' => $this->adminId, 'vehicle_id' => $unit?->vehicle_id ?? $sr->vehicle_id]);
        // 1-2 responders; a request left Responding only from the on-call half.
        $pool = $status === 'Responding' ? $this->onCall : $this->responders->all();
        if ($pool) {
            $crew = array_unique(array_map(fn () => $this->pick($pool)->responder_id, range(1, mt_rand(1, 2))));
            $sr->responders()->attach(array_fill_keys($crew, ['assigned_at' => $t]));
        }
        $trip = $unit ? $this->trip($sr, $unit, $t) : null;

        if ($status === 'Resolved') {
            $done = $t->addMinutes(mt_rand(90, 60 * 24 * 5));
            $done = $done->greaterThan($this->now) ? $this->now->subMinutes(5) : $done;
            $this->at($done);
            $trip?->update(['departed_destination_at' => $done->subMinutes(40), 'returned_office_at' => $done]);
            $sr->update(['status' => 'Resolved']);
        }
    }

    /**
     * A unit of a type this service uses that no Responding request holds
     * (one Responding request per unit, by DB index). Kept when the request
     * stays Responding; an ambulance always gets one, other services usually.
     */
    private function unitFor(string $code, bool $keep): ?Vehicle
    {
        $types = $this->unitTypes[$code] ?? [];
        $free = $this->vehicles->filter(fn ($v) => in_array($v->type, $types, true) && ! isset($this->held[$v->vehicle_id]))->values()->all();
        $unit = $free && ($keep || $code === self::AMBULANCE || $this->chance(70)) ? $this->pick($free) : null;
        if ($unit && $keep) {
            $this->held[$unit->vehicle_id] = true;
        }

        return $unit;
    }

    private function trip(ServiceRequest $sr, Vehicle $unit, CarbonImmutable $t): ConductionRequest
    {
        $booking = $sr->ambulanceBooking()->first();
        $requester = $sr->resident()->first();
        // The trip log always names someone to contact: the patient, else the requester.
        $trip = ConductionRequest::create([
            'service_request_id' => $sr->request_id,
            'vehicle_id' => $unit->vehicle_id,
            'vehicle' => $unit->unit_identifier,
            'patient_name' => $booking?->patient_name ?? $sr->walk_in_name ?? trim($requester?->first_name.' '.$requester?->last_name),
            'patient_contact_number' => $booking?->patient_contact_number ?? $sr->walk_in_contact_number ?? $requester?->phone_number ?? '',
            'patient_age' => $booking?->patient_age,
            'patient_address' => $booking?->patient_address,
            'origin' => 'MDRRMO Echague',
            'destination' => $booking?->destination ?? $this->address($sr),
            'departed_office_at' => $t->addMinutes(10),
            'arrived_destination_at' => $t->addMinutes(mt_rand(25, 70)),
        ]);
        $trip->people()->create(['role' => 'driver', 'name' => $this->responders->isEmpty() ? $this->name() : $this->pick($this->responders->all())->name, 'position' => 0]);
        foreach ($sr->relatives()->get() as $relative) {
            $trip->people()->create(['role' => 'relative', 'name' => $relative->name, 'position' => $relative->position]);
        }

        return $trip;
    }

    private function borrowings(): void
    {
        $equipment = Equipment::orderBy('equipment_id')->get();
        $stock = $equipment->pluck('total_quantity', 'equipment_id')->all();
        $plan = $this->loanPlan ?? ['Pending' => 10, 'Approved' => 8, 'Released' => 10, 'Overdue' => 6, 'Returned' => 30, 'Denied' => 8, 'Cancelled' => 6, 'Other' => 5];
        $today = $this->now->setTimezone('Asia/Manila')->startOfDay();

        foreach ($plan as $kind => $count) {
            for ($i = 0; $i < $count; $i++) {
                $holds = in_array($kind, ['Released', 'Overdue'], true);
                // A loan out holds one unit, and never the last one on the shelf.
                $qty = $holds ? 1 : mt_rand(1, 2);
                $elsewhere = $kind === 'Other' || (! $holds && $this->chance($this->otherEquipmentPercent));
                $item = $elsewhere ? null
                    : $this->pick($equipment->filter(fn ($e) => ! $holds || $stock[$e->equipment_id] > $qty)->values()->all());
                if ($holds) {
                    $stock[$item->equipment_id] -= $qty;
                }

                [$created, $released, $due, $returned] = match ($kind) {
                    'Released' => [$c = $this->past(3, 20), $c->addDay(), $today->addDays(mt_rand(1, 14)), null],
                    'Overdue' => [$c = $this->past(20, 40), $c->addDay(), $today->subDays(mt_rand(1, 12)), null],
                    'Returned' => [$c = $this->past(10, $this->spanDays), $r = $c->addDay(), $r->addDays(14), $r->addDays(mt_rand(3, 20))],
                    'Denied', 'Cancelled', 'Other' => [$this->past(0, $this->finalLoanDays), null, null, null],
                    default => [$this->past(0, 30), null, null, null],
                };
                $by = $this->filer('equipment-borrowing', 0, $created);
                $institution = ! $by->isHeadOfFamily();
                $delivery = $this->chance(30);

                $this->at($created);
                $loan = EquipmentBorrowing::create([
                    'resident_id' => $by->resident_id,
                    'equipment_id' => $item?->equipment_id,
                    'other_equipment_text' => $item ? null : $this->pick(['Generator', 'Folding tent', 'Megaphone', 'Water pump', 'Extension ladder']),
                    'quantity' => $qty,
                    'purpose' => $this->pick(['Recovering after surgery.', 'For my mother, bedridden.', 'Barangay medical mission.', 'Community clean-up drive.']),
                    'fulfillment_method' => $delivery ? 'Delivery' : 'Pickup',
                    'delivery_address' => $delivery ? 'Purok '.mt_rand(1, 7).', Brgy. '.($this->barangayNames[$by->barangay_id] ?? '') : null,
                    'borrower_type' => $institution ? 'Organization' : 'Resident',
                    'organization_name' => $institution ? ($by->organization_name ?: 'Barangay '.($this->barangayNames[$by->barangay_id] ?? '')) : null,
                    'due_date' => $due?->toDateString(),
                    'status' => 'Pending',
                ]);

                $t = $created->addHours(mt_rand(2, 30));
                $this->at($t);
                match ($kind) {
                    'Approved' => $loan->update(['status' => 'Approved']),
                    'Denied' => $loan->update($this->chance(50)
                        ? ['status' => 'Denied', 'denial_reason_code' => 'Unavailable']
                        : ['status' => 'Denied', 'denial_reason_code' => 'Other', 'denial_reason' => 'Please borrow through your barangay hall.']),
                    'Cancelled' => $loan->update(['status' => 'Cancelled']),
                    'Other' => $loan->update(['status' => $this->pick(['Pending', 'Approved', 'Denied', 'Cancelled'])]),
                    default => null,
                };
                if ($released) {
                    $this->at($released);
                    $loan->update(['status' => 'Released', 'released_at' => $released]);
                }
                if ($kind === 'Overdue' && $this->chance(60)) {
                    $loan->update(['return_reminder_sent_at' => $due->subDay()]);
                }
                if ($returned) {
                    $returned = $returned->greaterThan($this->now) ? $this->now->subHour() : $returned;
                    $bad = $this->chance(25);
                    $this->at($returned);
                    $loan->update([
                        'status' => 'Returned',
                        'returned_at' => $returned,
                        'return_condition' => $bad ? 'Bad' : 'Good',
                        'return_condition_note' => $bad ? 'Wheel loose, needs repair.' : 'Returned clean and working.',
                    ]);
                }
            }
        }
    }

    /**
     * Files a request as $by (null: a walk-in) at $at. A Head of the Family is
     * sometimes filed from a former barangay and moved straight back.
     */
    private function file(?Resident $by, CarbonImmutable $at, array $attributes): ServiceRequest
    {
        $this->at($at);
        $home = $by?->barangay_id;
        $away = $by && isset($this->formerHomes[$by->resident_id]) && $this->chance(40)
            ? $this->pick($this->formerHomes[$by->resident_id]) : null;

        if ($away) {
            $this->place($by, $away);
        }
        try {
            return ServiceRequest::create($attributes + [
                'resident_id' => $by?->resident_id,
                'walk_in_name' => $by ? null : $this->name(),
                'walk_in_contact_number' => $by ? null : '09'.mt_rand(170000000, 199999999),
                'status' => 'Pending',
            ]);
        } finally {
            if ($away) {
                $this->place($by, $home);
            }
        }
    }

    /** No event, no log, no updated_at: the resident row ends exactly as it began. */
    private function place(Resident $resident, int $barangayId): void
    {
        $resident->barangay_id = $barangayId;
        $resident->timestamps = false;
        $resident->saveQuietly();
        $resident->timestamps = true;
    }

    /** Who files at $at: someone allowed the service, and (when weighted) already registered. */
    private function filer(string $code, int $walkInPercent, CarbonImmutable $at): ?Resident
    {
        if ($this->chance($walkInPercent)) {
            return null;
        }
        $types = ServiceAudience::typesFor($code);
        $allowed = $this->residents->filter(fn ($r) => in_array($r->account_type, $types, true))->values();

        if (! $this->weighted) {
            return $allowed->isEmpty() ? null : $allowed[mt_rand(0, $allowed->count() - 1)];
        }

        $allowed = $allowed->filter(fn ($r) => $r->created_at->lessThanOrEqualTo($at))->values();
        $point = mt_rand() / mt_getrandmax() * $allowed->sum(fn ($r) => $this->weights[$r->resident_id]);

        foreach ($allowed as $r) {
            if (($point -= $this->weights[$r->resident_id]) <= 0 && $this->weights[$r->resident_id] > 0) {
                return $r;
            }
        }

        return null;
    }

    private function address(ServiceRequest $sr): string
    {
        return 'Purok '.mt_rand(1, 7).', Brgy. '.($this->barangayNames[$sr->barangay_id] ?? 'Centro').', Echague, Isabela';
    }

    /** A daytime moment (Manila) between $minDays and $maxDays ago. */
    private function past(int $minDays, int $maxDays): CarbonImmutable
    {
        $at = $this->now->setTimezone('Asia/Manila')->subDays($this->weighted ? $this->busyDay($minDays, $maxDays) : mt_rand($minDays, $maxDays))
            ->setTime(mt_rand(6, 20), mt_rand(0, 59))->utc();

        return $at->greaterThan($this->now) ? $this->now->subMinutes(mt_rand(5, 60)) : $at;
    }

    /**
     * A day offset in [min, max] by rejection sampling: 4% more traffic each month
     * than the one before, half as many filings at weekends, +40% from July to October.
     */
    private function busyDay(int $min, int $max): int
    {
        for ($try = 0; $try < 60; $try++) {
            $days = mt_rand($min, $max);
            $date = $this->now->setTimezone('Asia/Manila')->subDays($days);
            $weight = 1.04 ** (-$days / 30)
                * ($date->isWeekend() ? 0.5 : 1)
                * ($date->month >= 7 && $date->month <= 10 ? 1.4 : 1);

            // 1.4 x 1.04^(-min/30) is the largest weight a day in range can have.
            if (mt_rand() / mt_getrandmax() <= $weight / (1.4 * 1.04 ** (-$min / 30))) {
                return $days;
            }
        }

        return $min;
    }

    private function at(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
    }

    private function name(): string
    {
        return $this->pick(self::FIRST).' '.$this->pick(self::LAST);
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }
}
