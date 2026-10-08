<?php

// Adds what DemoSeeder leaves out for SHOT-LIST.md. Capture database only.
//
// Base data first (from Backend/SERBIS-Backend):
//   DB_DATABASE=serbis_capture php artisan migrate:fresh --seed --seeder=DemoSeeder
//   DB_DATABASE=serbis_capture php artisan db:seed --class=InfoMaterialSeeder
// Then, from the repo root:
//   DB_DATABASE=serbis_capture php docs/user-manuals/tools/capture/seed-capture.php
// After the Head of the Family is registered in the app, give it history:
//   DB_DATABASE=serbis_capture php docs/user-manuals/tools/capture/seed-capture.php --resident=+639XXXXXXXXX

use App\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$backend = __DIR__.'/../../../../Backend/SERBIS-Backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = config('database.connections.'.config('database.default').'.database');
if ($database !== 'serbis_capture' || ! app()->environment('local')) {
    fwrite(STDERR, "Refusing: database is '$database', env ".app()->environment().". Set DB_DATABASE=serbis_capture.\n");
    exit(1);
}

const TZ = 'Asia/Manila';
$now = CarbonImmutable::now();
$day = fn (int $days, int $h, int $m = 0) => CarbonImmutable::now(TZ)->startOfDay()->addDays($days)->setTime($h, $m)->utc();
$disk = Storage::disk(config('filesystems.uploads.private'));
$admin = (int) DB::table('tbl_user')->where('username', 'admin')->value('admin_id');

$log = function (string $model, int $id, string $action, ?array $old, ?array $new, CarbonImmutable $at, ?int $adminId, ?int $residentId) {
    DB::table('tbl_system_logs')->insert([
        'admin_id' => $adminId, 'resident_id' => $residentId, 'action_type' => $action,
        'auditable_type' => $model, 'auditable_id' => $id,
        'old_values' => $old ? json_encode($old) : null, 'new_values' => $new ? json_encode($new) : null,
        'ip_address' => '127.0.0.1', 'user_agent' => 'seed-capture',
        'created_at' => $at->toDateTimeString(), 'updated_at' => $at->toDateTimeString(),
    ]);
};

$request = function (array $row, array $steps, int $residentId) use ($log, $admin) {
    $id = DB::table('tbl_service_request')->insertGetId($row + ['updated_at' => $row['created_at']]);
    $log('App\Models\ServiceRequest', $id, 'created', null, ['status' => 'Pending'], $row['created_at'], null, $residentId);
    foreach ($steps as [$old, $new, $at, $byResident]) {
        $log('App\Models\ServiceRequest', $id, 'updated', $old, $new, $at, $byResident ? null : $admin, $byResident ? $residentId : null);
    }

    return $id;
};

$serviceId = fn (string $code) => DB::table('tbl_services')->where('code', $code)->value('service_id');

$residentArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--resident=')) {
        $residentArg = substr($arg, 11);
    }
}

// MOB-42: the "Not available" tab. Untick Ambulance for organizations (what
// MDRRMO would do on Service Audience), capture, then put it back so ADM-40
// keeps showing every box ticked.
if (in_array('--hide-ambulance-for-organizations', $argv, true)) {
    DB::table('tbl_service_audience')->where('service_code', 'ambulance-medical-response')->where('account_type', 'organization')->delete();
    Cache::flush();
    echo "Ambulance hidden from organization accounts. Run --restore-audience when done.\n";
    exit(0);
}
if (in_array('--restore-audience', $argv, true)) {
    $row = ['service_code' => 'ambulance-medical-response', 'account_type' => 'organization'];
    if (! DB::table('tbl_service_audience')->where($row)->exists()) {
        DB::table('tbl_service_audience')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
    }
    Cache::flush();
    echo "Ambulance offered to organization accounts again.\n";
    exit(0);
}

if ($residentArg === null) {
    // DemoSeeder writes "Brgy. San Fabian" (and San Miguel, San Antonio Ugad)
    // into addresses and organization names but files those accounts under
    // barangay ids 1-3, which BarangaySeeder now gives to other barangays. Move
    // the accounts, and the barangay each request was filed under, to the
    // barangays their text names. Safe to run again: ids 1-3 end up unused.
    foreach ([1 => 'San Fabian', 2 => 'San Miguel', 3 => 'San Antonio Ugad'] as $old => $name) {
        $new = DB::table('tbl_barangay')->where('barangay_name', $name)->value('barangay_id');
        DB::table('tbl_residents')->where('barangay_id', $old)->update(['barangay_id' => $new]);
        DB::table('tbl_service_request')->where('barangay_id', $old)->update(['barangay_id' => $new]);
    }

    if (DB::table('tbl_system_logs')->where('user_agent', 'seed-capture')->exists()) {
        Cache::flush();
        echo "Barangays aligned; the rest of the base data is already in.\n";
        exit(0);
    }

    // Sample images on the private disk, in place of DemoSeeder's 1x1 placeholder.
    foreach (['site-photo.png', 'handover-release.png', 'handover-return.png', 'sample-id.png', 'request-letter.pdf'] as $file) {
        $disk->put("capture/$file", file_get_contents(__DIR__."/samples/$file"));
    }
    DB::table('tbl_service_request')->where('valid_id', 'demo/placeholder.png')->update(['valid_id' => 'capture/sample-id.png']);
    DB::table('tbl_service_request')->where('letter', 'demo/placeholder.png')->update(['letter' => 'capture/request-letter.pdf']);
    DB::table('tbl_equipment_borrowing')->where('release_photo_path', 'demo/placeholder.png')->update(['release_photo_path' => 'capture/handover-release.png']);
    DB::table('tbl_equipment_borrowing')->where('return_photo_path', 'demo/placeholder.png')->update(['return_photo_path' => 'capture/handover-return.png']);

    // DemoSeeder writes with model events off, so the filing-time barangay
    // snapshot (ServiceRequest::creating) never ran. Fill it the same way.
    DB::statement('UPDATE tbl_service_request sr JOIN tbl_residents r ON r.resident_id = sr.resident_id SET sr.barangay_id = r.barangay_id WHERE sr.barangay_id IS NULL');

    // Staff: phones for SMS sign-in (fake SMS), one limited account, one on a temporary password.
    DB::table('tbl_user')->where('username', 'admin')->update(['phone_number' => '+639170009001']);
    DB::table('tbl_user')->where('username', 'maria.pascual')->update([
        'phone_number' => '+639170009002',
        'permissions' => json_encode(['dashboard', 'requests', 'ambulance', 'borrowings']),
    ]);
    DB::table('tbl_user')->where('username', 'ramil.cabacungan')->update([
        'phone_number' => '+639170009003',
        'password' => Hash::make('TempPass123'),
        'must_change_password' => true,
    ]);

    $sanFabian = DB::table('tbl_barangay')->where('barangay_name', 'San Fabian')->value('barangay_id');
    $heads = DB::table('tbl_residents')->where('account_type', 'head_of_family')->where('barangay_id', $sanFabian)->orderBy('resident_id')->pluck('resident_id');

    // ADM-10/11/12: a Pending Road Clearing request with a site photo.
    $filed = $now->subHours(3);
    $request([
        'resident_id' => $heads[0], 'barangay_id' => $sanFabian, 'service_id' => $serviceId('road-clearing'), 'status' => 'Pending',
        'description' => 'Fallen acacia tree blocking the barangay road after last night\'s wind. Tricycles cannot pass.',
        'valid_id' => 'capture/sample-id.png', 'site_photo' => 'capture/site-photo.png',
        'landmark' => 'Near the elementary school', 'created_at' => $filed,
    ], [], $heads[0]);

    // ADM-14: a Responding request with a unit out.
    $vehicle = DB::table('tbl_vehicles')->where('type', 'Rescue Vehicle')->orderBy('vehicle_id')->value('vehicle_id');
    $filed = $now->subHours(7);
    $approved = $filed->addHours(2);
    $id = $request([
        'resident_id' => $heads[1], 'barangay_id' => $sanFabian, 'service_id' => $serviceId('debris-removal'), 'status' => 'Responding',
        'description' => 'Branches and roofing sheets piled along the canal after the storm.',
        'valid_id' => 'capture/sample-id.png', 'landmark' => 'Across the covered court',
        'vehicle_id' => $vehicle, 'processed_by' => $admin, 'first_responded_at' => $approved,
        'status_changed_at' => $approved, 'created_at' => $filed,
    ], [[['status' => 'Pending'], ['status' => 'Responding', 'vehicle_id' => $vehicle], $approved, false]], $heads[1]);
    DB::table('tbl_service_request')->where('request_id', $id)->update(['updated_at' => $approved]);
    DB::table('tbl_vehicles')->where('vehicle_id', $vehicle)->update(['status' => 'Dispatched']);

    // Ambulance: one Pending booking tomorrow (ADM-17) and three trips on one day (ADM-20).
    $ambulance = $serviceId('ambulance-medical-response');
    $pending = DB::table('tbl_ambulance_bookings as b')->join('tbl_service_request as r', 'r.request_id', '=', 'b.request_id')
        ->where('r.service_id', $ambulance)->where('r.status', 'Pending')->where('b.scheduled_at', '>', $now)
        ->orderBy('b.scheduled_at')->pluck('b.request_id');
    DB::table('tbl_ambulance_bookings')->where('request_id', $pending[0])->update(['scheduled_at' => $day(1, 8, 30)]);
    $dayThree = DB::table('tbl_ambulance_bookings as b')->join('tbl_service_request as r', 'r.request_id', '=', 'b.request_id')
        ->where('r.service_id', $ambulance)->where('r.status', 'Booked')->where('b.scheduled_at', '>', $day(3, 0))
        ->where('b.scheduled_at', '<', $day(4, 0))->value('b.request_id');
    if ($dayThree !== null) {
        DB::table('tbl_ambulance_bookings')->where('request_id', $dayThree)->update(['scheduled_at' => $day(3, 6, 0), 'scheduled_end' => $day(3, 10, 0)]);
    }
    if (isset($pending[1])) {
        DB::table('tbl_ambulance_bookings')->where('request_id', $pending[1])->update(['scheduled_at' => $day(3, 13, 0)]);
    }
    if (isset($pending[2])) {
        DB::table('tbl_ambulance_bookings')->where('request_id', $pending[2])->update(['scheduled_at' => $day(3, 15, 30)]);
    }

    // Trips: the latest one fully filled in (ADM-21..23), the one before it "Not transported".
    $trips = DB::table('tbl_conduction_requests')->orderByDesc('departed_office_at')->pluck('conduction_request_id');
    $full = $trips[0];
    DB::table('tbl_conduction_requests')->where('conduction_request_id', $full)->update([
        'plate_no' => 'SAA 1234', 'others' => 'Patient brought her own wheelchair. Fuel topped up on return.',
    ]);
    $people = DB::table('tbl_conduction_request_people')->where('conduction_request_id', $full);
    if (! (clone $people)->where('role', 'passenger')->exists()) {
        $stamp = DB::table('tbl_conduction_requests')->where('conduction_request_id', $full)->value('departed_office_at');
        DB::table('tbl_conduction_request_people')->insert([
            ['conduction_request_id' => $full, 'role' => 'driver', 'name' => 'Mario Gaoat', 'position' => 1, 'created_at' => $stamp, 'updated_at' => $stamp],
            ['conduction_request_id' => $full, 'role' => 'passenger', 'name' => 'Leonardo Tagorda', 'position' => 0, 'created_at' => $stamp, 'updated_at' => $stamp],
        ]);
    }
    DB::table('tbl_conduction_requests')->where('conduction_request_id', $trips[1])->update([
        'arrived_destination_at' => null, 'departed_destination_at' => null,
        'no_arrival_reason' => 'Patient declined transport at pickup; family will bring her on their own.',
    ]);

    echo "Base capture data added.\n";
} else {
    // History for the Head of the Family registered through the app.
    $resident = Resident::where('phone_number', $residentArg)->firstOrFail();
    $rid = $resident->resident_id;
    $brgy = $resident->barangay_id;
    // Text blasts go to Active accounts only (SmsController::resolveRecipients).
    DB::table('tbl_residents')->where('resident_id', $rid)->update(['status' => 'Active']);

    $filed = $now->subDays(12);
    $done = $filed->addDays(1);
    $vehicle = DB::table('tbl_vehicles')->where('type', 'Rescue Vehicle')->orderByDesc('vehicle_id')->value('vehicle_id');
    $request([
        'resident_id' => $rid, 'barangay_id' => $brgy, 'service_id' => $serviceId('sandbagging'), 'status' => 'Resolved',
        'description' => 'Sandbags needed along the creek bank behind our purok.', 'valid_id' => 'capture/sample-id.png',
        'landmark' => 'Behind the chapel', 'vehicle_id' => $vehicle, 'processed_by' => $admin,
        'first_responded_at' => $filed->addHours(3), 'resolved_at' => $done, 'status_changed_at' => $done, 'created_at' => $filed,
    ], [
        [['status' => 'Pending'], ['status' => 'Booked', 'vehicle_id' => $vehicle], $filed->addHours(3), false],
        [['status' => 'Booked'], ['status' => 'Responding'], $done->subHours(3), false],
        [['status' => 'Responding'], ['status' => 'Resolved'], $done, false],
    ], $rid);

    $filed = $now->subDays(20);
    $request([
        'resident_id' => $rid, 'barangay_id' => $brgy, 'service_id' => null, 'status' => 'Cancelled',
        'description' => 'Vehicle to carry chairs for the purok meeting.', 'preferred_date' => $filed->addDays(4)->setTimezone(TZ)->toDateString(),
        'resolved_at' => $filed->addHours(5), 'status_changed_at' => $filed->addHours(5), 'created_at' => $filed,
    ], [[['status' => 'Pending'], ['status' => 'Cancelled'], $filed->addHours(5), true]], $rid);

    $filed = $now->subDays(2);
    $approved = $filed->addHours(4);
    $request([
        'resident_id' => $rid, 'barangay_id' => $brgy, 'service_id' => $serviceId('power-line-repair'), 'status' => 'Booked',
        'description' => 'Sagging power line over the road near our house.', 'valid_id' => 'capture/sample-id.png',
        'landmark' => 'Near the sari-sari store', 'processed_by' => $admin, 'first_responded_at' => $approved,
        'status_changed_at' => $approved, 'created_at' => $filed,
    ], [[['status' => 'Pending'], ['status' => 'Booked'], $approved, false]], $rid);

    // MOB-30: a Released wheelchair with handover photos, and a returned item.
    $wheelchair = DB::table('tbl_equipments')->where('item_name', 'Wheel Chair')->first();
    $created = $now->subDays(3);
    $released = $created->addHours(6);
    $borrowId = DB::table('tbl_equipment_borrowing')->insertGetId([
        'resident_id' => $rid, 'equipment_id' => $wheelchair->equipment_id, 'quantity' => 1,
        'purpose' => 'Post-surgery recovery at home', 'fulfillment_method' => 'Pickup', 'borrower_type' => 'Resident',
        'status' => 'Released', 'due_date' => $day(4, 9)->setTimezone(TZ)->toDateString(),
        'released_at' => $released, 'release_photo_path' => 'capture/handover-release.png',
        'created_at' => $created, 'updated_at' => $released,
    ]);
    $log('App\Models\EquipmentBorrowing', $borrowId, 'created', null, ['status' => 'Pending'], $created, null, $rid);
    $log('App\Models\EquipmentBorrowing', $borrowId, 'updated', ['status' => 'Pending'], ['status' => 'Approved'], $created->addHours(2), $admin, null);
    $log('App\Models\EquipmentBorrowing', $borrowId, 'updated', ['status' => 'Approved'], ['status' => 'Released'], $released, $admin, null);
    DB::table('tbl_equipments')->where('equipment_id', $wheelchair->equipment_id)->decrement('available_quantity');

    echo "History added for resident $rid.\n";
}

// Analytics and lists are cached; start clean.
Cache::flush();
