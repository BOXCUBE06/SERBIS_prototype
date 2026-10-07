<?php

namespace App\Console\Commands;

use App\Models\AmbulanceBooking;
use App\Models\ConductionRequest;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Responder;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use App\Support\AnalyticsCache;
use App\Traits\ResolvesUploadDisks;
use Database\Seeders\DevVolumeSeeder;
use Database\Seeders\RequestDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * LOCAL ONLY. Deletes every service request, ambulance booking, trip record and
 * equipment borrowing, generates a fresh set (RequestDataSeeder), then puts
 * stock, fleet and responder status back in line with what was generated.
 * Residents, users, barangays, services and the equipment catalogue are kept.
 */
class ReseedRequestData extends Command
{
    use ResolvesUploadDisks;

    protected $signature = 'serbis:reseed-requests
        {--seed=1 : Random seed; the same seed gives the same data}
        {--volume : Also seed residents, staff and SMS history, and generate ~1000 requests over 24 months}
        {--scale=1 : With --volume, multiplies every count (0.1 for a quick run)}
        {--force : Skip the confirmation}';

    protected $description = 'LOCAL ONLY: wipe all request and borrowing data and generate a fresh realistic set';

    /** The only databases this may run against: the local one and the test suite's. */
    private const DATABASES = ['serbis_test_db', 'serbis_phpunit'];

    /** Children before parents. */
    private const TABLES = [
        'tbl_conduction_request_people',
        'tbl_conduction_requests',
        'tbl_request_responders',
        'tbl_service_request_relatives',
        'tbl_ambulance_bookings',
        'tbl_service_request',
        'tbl_equipment_borrowing',
    ];

    private const LOGGED = [ServiceRequest::class, AmbulanceBooking::class, ConductionRequest::class, EquipmentBorrowing::class];

    /** Upload folders only requests and borrowings write to. */
    private const FILE_DIRS = ['valid-ids', 'site-photos', 'letters', 'borrowing-photos'];

    public function handle(): int
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refusing to run outside local/testing (env: '.app()->environment().').');

            return self::FAILURE;
        }
        if (! in_array($database, self::DATABASES, true)) {
            $this->error("Refusing to run against '{$database}'; only ".implode(', ', self::DATABASES).'.');

            return self::FAILURE;
        }
        // Seeded phone numbers are valid Philippine mobiles and some belong to real people.
        if (! config('serbis.sms_fake')) {
            $this->error('Refusing to run: SERBIS_SMS_FAKE is not true, so a text sent from this database could reach a real phone.');

            return self::FAILURE;
        }
        if (! Schema::hasColumn('tbl_service_request', 'barangay_id')) {
            $this->error('tbl_service_request.barangay_id is missing. Run php artisan migrate first.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Delete all service requests, ambulance bookings and borrowings in {$database}?")) {
            return self::FAILURE;
        }

        // Nothing may leave this machine: SMS (SkySMS) and push (FCM) both go through Http.
        Http::preventStrayRequests();

        $volume = (bool) $this->option('volume');
        $scale = max(0.01, (float) $this->option('scale'));
        if ($volume) {
            $people = new DevVolumeSeeder;
            $people->scale = $scale;
            $people->setCommand($this)->run();
        }

        $residentsBefore = Resident::orderBy('resident_id')->pluck('barangay_id', 'resident_id')->all();

        DB::transaction(function () {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }
            DB::table('tbl_system_logs')->whereIn('auditable_type', self::LOGGED)->delete();
        });

        // After commit: DDL commits on MySQL, and files cannot be rolled back.
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
        }
        foreach (self::FILE_DIRS as $dir) {
            Storage::disk(self::privateDisk())->deleteDirectory($dir);
        }

        $seeder = new RequestDataSeeder;
        $seeder->seed = (int) $this->option('seed');
        if ($volume) {
            $this->sizeForVolume($seeder, $scale);
        }
        $seeder->setCommand($this)->run();

        $this->reconcileStock();
        $this->reconcileFleet();
        AnalyticsCache::flush();

        if (Resident::orderBy('resident_id')->pluck('barangay_id', 'resident_id')->all() !== $residentsBefore) {
            $this->error('Residents did not end in the barangays they started in.');

            return self::FAILURE;
        }

        foreach ([...self::TABLES, 'tbl_system_logs'] as $table) {
            $this->line(str_pad($table, 32).DB::table($table)->count());
        }
        $this->info('Distinct barangays on requests: '.DB::table('tbl_service_request')->distinct()->count('barangay_id'));

        return self::SUCCESS;
    }

    /** About 1000 requests and 300 loans over 24 months; open statuses only in the last 28 days. */
    private function sizeForVolume(RequestDataSeeder $seeder, float $scale): void
    {
        $scaled = fn (array $plan) => array_map(fn ($count) => max(1, (int) round($count * $scale)), $plan);

        $seeder->weighted = true;
        $seeder->spanDays = 730;
        $seeder->openDays = 28;
        $seeder->finalLoanDays = 730;
        $seeder->otherEquipmentPercent = 5;
        $seeder->servicePlan = $scaled(['Responding' => 3, 'Pending' => 11, 'Resolved' => 674, 'Cancelled' => 83, 'Disapproved' => 79]);
        $seeder->ambulancePlan = $scaled(['Responding' => 1, 'Booked' => 6, 'Pending' => 5, 'Resolved' => 116, 'Cancelled' => 12, 'Disapproved' => 10]);
        $seeder->loanPlan = $scaled(['Pending' => 8, 'Approved' => 6, 'Released' => 12, 'Overdue' => 5, 'Returned' => 217, 'Denied' => 30, 'Cancelled' => 22]);
    }

    /** available = total - quantity out on Released loans (the rule serbis:report-equipment-stock checks). */
    private function reconcileStock(): void
    {
        $out = DB::table('tbl_equipment_borrowing')->where('status', 'Released')->whereNotNull('equipment_id')
            ->groupBy('equipment_id')->selectRaw('equipment_id, SUM(quantity) as qty')->pluck('qty', 'equipment_id');

        foreach (Equipment::all() as $item) {
            $item->update(['available_quantity' => $item->total_quantity - (int) ($out[$item->equipment_id] ?? 0)]);
        }
    }

    /** A unit or responder is busy exactly when a Responding request holds it. Maintenance is left alone. */
    private function reconcileFleet(): void
    {
        $responding = ServiceRequest::where('status', 'Responding');
        $busyUnits = (clone $responding)->whereNotNull('vehicle_id')->pluck('vehicle_id')->all();
        $busyCrew = DB::table('tbl_request_responders')->whereIn('request_id', (clone $responding)->select('request_id')->toBase())
            ->pluck('responder_id')->all();

        foreach (Vehicle::where('status', '!=', 'Maintenance')->get() as $unit) {
            $unit->update(['status' => in_array($unit->vehicle_id, $busyUnits) ? 'Dispatched' : 'Available']);
        }
        foreach (Responder::where('status', '!=', 'off_duty')->get() as $responder) {
            $responder->update(['status' => in_array($responder->responder_id, $busyCrew) ? 'deployed' : 'available']);
        }
    }
}
