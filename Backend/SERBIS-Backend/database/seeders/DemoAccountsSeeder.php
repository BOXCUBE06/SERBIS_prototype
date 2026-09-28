<?php

namespace Database\Seeders;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Responder;
use App\Models\Service;
use App\Models\ServiceAudience;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AnalyticsCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 5 barangay + 5 organization demo accounts, each with a few service requests
 * covering every status the admin tables filter by. Local/testing only, and
 * NOT in DatabaseSeeder — run it with:
 *   php artisan db:seed --class=DemoAccountsSeeder
 *
 * Accounts are keyed on email (safe to re-run). Requests are found by MARKER
 * in `description`, deleted and rebuilt each run.
 *
 * Runs under withoutEvents: no TracksHistory log rows. Nothing here can send an
 * SMS or push — those live in controllers, which this never calls. Vehicle and
 * responder statuses are left alone, as in AmbulanceBookingStatusDemoSeeder.
 */
class DemoAccountsSeeder extends Seeder
{
    private const MARKER = '[demo-accounts]';

    private const PASSWORD = 'DemoPass#2026';

    private const BARANGAYS = ['San Fabian', 'San Miguel', 'San Antonio Ugad'];

    private const AMBULANCE = 'ambulance-medical-response';

    /** [email local part, type, contact first, contact last, organization] */
    private const ACCOUNTS = [
        ['demo.brgy1', 'barangay', 'Rogelio', 'Dela Cruz', null],
        ['demo.brgy2', 'barangay', 'Marilou', 'Santos', null],
        ['demo.brgy3', 'barangay', 'Ernesto', 'Bautista', null],
        ['demo.brgy4', 'barangay', 'Consuelo', 'Villanueva', null],
        ['demo.brgy5', 'barangay', 'Alfredo', 'Aquino', null],
        ['demo.org1', 'organization', 'Lorna', 'Mendoza', 'Echague Rural Health Unit Volunteers'],
        ['demo.org2', 'organization', 'Benito', 'Ramos', 'Echague National High School'],
        ['demo.org3', 'organization', 'Teresita', 'Domingo', 'Echague PNP Community Desk'],
        ['demo.org4', 'organization', 'Jaime', 'Pagaduan', 'BFP Echague Fire Station'],
        ['demo.org5', 'organization', 'Norma', 'Cabacungan', 'Isabela Youth Volunteers Circle'],
    ];

    /**
     * Per account, in ACCOUNTS order: [service code|null, status, days ago
     * submitted, days from now scheduled (ambulance only)]. `null` is "Others".
     * Every row must pass ServiceAudience::allows for that account's type.
     */
    private const PLAN = [
        [[self::AMBULANCE, 'Pending', 2, null], ['road-clearing', 'Pending', 14, null], ['relief-goods-distribution', 'Resolved', 30, null]],
        [[self::AMBULANCE, 'Booked', 1, 3], ['sandbagging', 'Responding', 3, null], ['drrm-trainings-and-seminars', 'Pending', 9, null]],
        [[self::AMBULANCE, 'Responding', 2, 0], ['relief-goods-distribution', 'Disapproved', 40, null], ['power-line-repair', 'Cancelled', 20, null]],
        [[self::AMBULANCE, 'Resolved', 12, -10], ['debris-removal', 'Pending', 5, null], ['simulation-drills-nsed', 'Resolved', 55, null], ['animal-rescue', 'Responding', 6, null]],
        [[self::AMBULANCE, 'Disapproved', 8, -5], [self::AMBULANCE, 'Cancelled', 4, 6], ['relief-goods-distribution', 'Pending', 25, null]],
        [[self::AMBULANCE, 'Booked', 2, 10], ['road-clearing', 'Resolved', 45, null], ['mdrrmo-certification', 'Pending', 18, null]],
        [['drrm-trainings-and-seminars', 'Resolved', 35, null], ['sandbagging', 'Cancelled', 11, null], [self::AMBULANCE, 'Pending', 0, 2]],
        [['simulation-drills-nsed', 'Disapproved', 28, null], ['power-line-repair', 'Pending', 7, null], [self::AMBULANCE, 'Resolved', 50, -48]],
        [['debris-removal', 'Disapproved', 16, null], ['animal-rescue', 'Cancelled', 22, null], [null, 'Pending', 3, null]],
        [['mdrrmo-certification', 'Resolved', 58, null], ['road-clearing', 'Responding', 4, null], [self::AMBULANCE, 'Cancelled', 13, 5]],
    ];

    private const PATIENTS = [
        ['Ramon Dizon', 64, 'Chest pain, difficulty breathing.'],
        ['Luz Manalo', 71, 'Fall at home, suspected fracture.'],
        ['Andres Corpuz', 45, 'High fever and dizziness.'],
        ['Felisa Ordonez', 58, 'Dialysis transport, stable.'],
        ['Jose Tolentino', 33, 'Laceration, bleeding controlled.'],
        ['Amparo Galang', 80, 'Post-operative check-up transport.'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DemoAccountsSeeder skipped: local/testing only (env: '.app()->environment().').');

            return;
        }

        // First: hands last run's units and responders back before any pool is read.
        $this->purge();

        $barangays = Barangay::whereIn('barangay_name', self::BARANGAYS)->get()->keyBy('barangay_name');
        $services = Service::pluck('service_id', 'code');
        $ambulances = Vehicle::where('type', 'Ambulance')->orderBy('vehicle_id')->skip(2)->take(2)->get(); // AMB-03, AMB-04
        $rescue = Vehicle::where('type', 'Rescue Vehicle')->orderBy('vehicle_id')->take(2)->get();
        $responders = Responder::orderBy('responder_id')->take(6)->get();

        if ($barangays->count() < 3 || $services->isEmpty() || $ambulances->count() < 2 || $rescue->count() < 2 || $responders->count() < 6) {
            $this->command?->warn('DemoAccountsSeeder skipped: needs the 3 barangays, services, 4+ ambulances, 2 rescue vehicles and 6 responders.');

            return;
        }

        // One distinct Available unit per non-ambulance Responding request, as the app enforces.
        $live = Vehicle::where('type', '!=', 'Ambulance')->where('status', 'Available')->orderBy('vehicle_id')->take(3)->get();

        if ($live->count() < 3) {
            $this->command?->warn('DemoAccountsSeeder skipped: needs 3 Available non-ambulance units.');

            return;
        }

        $adminId = User::orderBy('admin_id')->value('admin_id');
        $now = Carbon::now('Asia/Manila');
        $n = 0;

        Model::withoutEvents(function () use ($barangays, $services, $ambulances, $rescue, $responders, $live, $adminId, $now, &$n) {
            foreach (self::ACCOUNTS as $i => [$local, $type, $first, $last, $org]) {
                $barangay = $barangays[self::BARANGAYS[$i % 3]];
                $account = $this->account($local, $type, $first, $last, $org, $barangay, 1001 + $i);

                foreach (self::PLAN[$i] as [$code, $status, $ago, $scheduleDays]) {
                    if ($code !== null && ! ServiceAudience::allows($code, $type)) {
                        throw new \LogicException("{$type} may not request {$code}");
                    }

                    $request = $this->request($account, $barangay, $code === null ? null : $services[$code], $status, $ago, $adminId, $n);

                    if ($code === self::AMBULANCE) {
                        $this->booking($request, $barangay, $status, $ago, $scheduleDays, $now, $n);
                    }

                    if (in_array($status, ['Responding', 'Resolved'], true)) {
                        $isLive = $status === 'Responding';
                        $unit = $code === self::AMBULANCE
                            ? $ambulances[$isLive ? 0 : 1]
                            : ($isLive ? $live->shift() : $rescue[$n % 2]);
                        $request->forceFill(['vehicle_id' => $unit->vehicle_id])->save();

                        // Responding is the only status where the app leaves them out.
                        if ($isLive) {
                            $unit->update(['status' => 'Dispatched']);
                        }

                        if ($code !== self::AMBULANCE) {
                            $crew = [$responders[$n % 6]->responder_id, $responders[($n + 3) % 6]->responder_id];
                            $request->responders()->attach($crew, ['assigned_at' => $request->created_at->copy()->addHours(3)]);

                            if ($isLive) {
                                Responder::whereIn('responder_id', $crew)->update(['status' => 'deployed']);
                            }
                        }
                    }
                    $n++;
                }
            }
        });

        AnalyticsCache::flush();
        $this->command?->info('DemoAccountsSeeder: '.count(self::ACCOUNTS)." accounts, {$n} requests. Password: ".self::PASSWORD);
    }

    private function account(string $local, string $type, string $first, string $last, ?string $org, Barangay $barangay, int $phoneSuffix): Resident
    {
        $account = Resident::firstOrNew(['email_address' => "{$local}@serbis.test"]);

        // account_type / organization_name are not fillable on purpose.
        $account->forceFill([
            'barangay_id' => $barangay->getKey(),
            'first_name' => $first,
            'last_name' => $last,
            'phone_number' => '0917000'.$phoneSuffix,
            'password' => Hash::make(self::PASSWORD),
            'status' => 'Active',
            'account_type' => $type,
            'organization_name' => $org,
            'sms_opt_in' => false,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ])->save();

        return $account;
    }

    /** Timestamps follow what the lifecycle hook would have stamped. */
    private function request(Resident $account, Barangay $barangay, ?int $serviceId, string $status, int $ago, ?int $adminId, int $n): ServiceRequest
    {
        $created = Carbon::now()->subDays($ago)->subHours($n % 9);
        $moved = $status === 'Pending' ? null : $created->copy()->addHours(2);
        $closed = in_array($status, ['Resolved', 'Cancelled', 'Disapproved'], true) ? $created->copy()->addDay() : null;

        $request = new ServiceRequest([
            'resident_id' => $account->getKey(),
            'service_id' => $serviceId,
            'status' => $status,
            'description' => self::MARKER.' Demo request from '.($account->organization_name ?? "Brgy. {$barangay->barangay_name}").'.',
            'landmark' => 'Near the '.($n % 2 ? 'barangay hall' : 'covered court').', '.$barangay->barangay_name,
            'processed_by' => $status === 'Pending' || $status === 'Cancelled' ? null : $adminId,
            'remarks' => $status === 'Disapproved' ? 'Outside the office schedule for that date.' : null,
        ]);

        $request->forceFill([
            'created_at' => $created,
            'updated_at' => $closed ?? $moved ?? $created,
            'status_changed_at' => $closed ?? $moved,
            'first_responded_at' => in_array($status, ['Responding', 'Resolved', 'Disapproved'], true) ? $moved : null,
            'resolved_at' => $closed,
        ])->save();

        return $request;
    }

    private function booking(ServiceRequest $request, Barangay $barangay, string $status, int $ago, ?int $scheduleDays, Carbon $now, int $n): void
    {
        [$patient, $age, $condition] = self::PATIENTS[$n % count(self::PATIENTS)];
        $start = $scheduleDays === null
            ? null
            : $now->copy()->addDays($scheduleDays)->setTime(8 + $n % 8, 0)->utc();
        $approved = in_array($status, ['Booked', 'Responding', 'Resolved'], true);

        AmbulanceBooking::create([
            'request_id' => $request->request_id,
            'patient_name' => $patient,
            'patient_age' => $age,
            'patient_address' => 'Purok '.(1 + $n % 5).", {$barangay->barangay_name}, Echague, Isabela",
            'patient_contact_number' => '0917000'.(2001 + $n),
            'pickup_location' => $request->landmark,
            'destination' => 'Echague District Hospital',
            'condition_notes' => $condition,
            'scheduled_at' => $start,
            'scheduled_end' => $start?->copy()->addHours(2),
            'approved_at' => $approved ? $request->created_at->copy()->addHours(2) : null,
        ]);
    }

    private function purge(): void
    {
        $ids = DB::table('tbl_service_request')->where('description', 'like', self::MARKER.'%')->pluck('request_id');

        // Hand back only what this seeder's Responding requests hold.
        $responding = DB::table('tbl_service_request')->whereIn('request_id', $ids)->where('status', 'Responding');
        DB::table('tbl_vehicles')->whereIn('vehicle_id', (clone $responding)->pluck('vehicle_id'))
            ->where('status', 'Dispatched')->update(['status' => 'Available']);
        DB::table('tbl_responders')->whereIn('responder_id', DB::table('tbl_request_responders')
            ->whereIn('request_id', (clone $responding)->pluck('request_id'))->pluck('responder_id'))
            ->where('status', 'deployed')->update(['status' => 'available']);

        DB::table('tbl_request_responders')->whereIn('request_id', $ids)->delete();
        DB::table('tbl_ambulance_bookings')->whereIn('request_id', $ids)->delete();
        DB::table('tbl_service_request')->whereIn('request_id', $ids)->delete();
    }
}
