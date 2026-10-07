<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Responder;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DevVolumeSeeder;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\VehicleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * serbis:reseed-requests --volume at a tenth of the real size: the invariants the
 * full 1000-request run has to hold. Own migrate:fresh for the same reason as
 * ReseedRequestDataTest (AUTO_INCREMENT resets are DDL).
 */
class DevVolumeSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        Storage::fake(config('filesystems.uploads.private'));
        Storage::fake('public');
        config(['serbis.sms_fake' => true]);

        $this->seed([ServiceSeeder::class, VehicleSeeder::class, EquipmentSeeder::class]);
        User::create(['first_name' => 'MDRRMO', 'last_name' => 'Admin', 'username' => 'admin',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active']);
        // Six, because DemoAccountsSeeder wants that many.
        foreach (range(0, 5) as $i) {
            Responder::create(['name' => "Responder {$i}", 'contact_no' => '0917555000'.$i, 'position' => $i % 2 ? 'Logistics' : 'Driver', 'status' => 'available']);
        }

        // The three DemoAccountsSeeder looks up by name, plus enough for a long tail.
        foreach (['San Fabian', 'San Miguel', 'San Antonio Ugad', 'Angoluan', 'Cabugao', 'Dammang', 'Gucab', 'Soyung', 'Taggappan', 'Malitao', 'Fugu', 'Maligaya'] as $name) {
            Barangay::create(['barangay_name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function seedVolume(): void
    {
        $this->artisan('serbis:reseed-requests', ['--force' => true, '--volume' => true, '--scale' => 0.1])->assertSuccessful();
    }

    public function test_it_refuses_unless_sms_is_faked(): void
    {
        config(['serbis.sms_fake' => false]);

        $this->artisan('serbis:reseed-requests', ['--force' => true, '--volume' => true, '--scale' => 0.1])->assertFailed();
        $this->assertSame(0, Resident::count());

        $this->expectException(RuntimeException::class);
        (new DevVolumeSeeder)->run();
    }

    public function test_the_data_is_consistent_recent_where_open_and_safe_to_rerun(): void
    {
        $this->seedVolume();

        // People: seeded phones are valid and unique, emails resemble the name, one hall per barangay.
        $seeded = Resident::where('phone_number', 'like', '+63999%')->get();
        $this->assertSame(30, $seeded->count());
        $this->assertTrue($seeded->every(fn ($r) => preg_match(PhoneNumber::REGEX, $r->phone_number) === 1));
        $this->assertSame($seeded->count(), $seeded->pluck('phone_number')->unique()->count());
        foreach ($seeded->whereNotNull('email_address') as $r) {
            $this->assertStringEndsWith('@seed.serbis.test', $r->email_address);
            $this->assertStringContainsString(strtolower(preg_replace('/[^a-z]/i', '', substr($r->first_name, 0, 1).$r->last_name)), $r->email_address);
        }
        $halls = Resident::where('account_type', 'barangay')->pluck('barangay_id');
        $this->assertGreaterThan(0, $halls->count());
        $this->assertSame($halls->count(), $halls->unique()->count());
        $this->assertSame(0, Resident::whereColumn('updated_at', '<', 'created_at')->count());
        $this->assertGreaterThan(0, Resident::where('status', 'Inactive')->count());
        $this->assertGreaterThan(0, Resident::whereNull('email_address')->count());

        // Staff: the admin is untouched, five were added, one of them closed.
        $this->assertSame(6, User::count());
        $this->assertSame(1, User::where('status', 'Inactive')->count());
        $this->assertTrue(Hash::check(DevVolumeSeeder::PASSWORD, User::where('username', 'jonas.tumaneng')->value('password')));

        // Requests and loans: children after parents, updated_at never before created_at.
        // 100 at a tenth, plus rounding: every status keeps at least one row.
        $this->assertEqualsWithDelta(100, ServiceRequest::count(), 5);
        $this->assertSame(0, DB::table('tbl_service_request')->whereColumn('updated_at', '<', 'created_at')->count());
        $this->assertSame(0, DB::table('tbl_service_request')
            ->join('tbl_residents', 'tbl_residents.resident_id', '=', 'tbl_service_request.resident_id')
            ->whereColumn('tbl_service_request.created_at', '<', 'tbl_residents.created_at')->count());
        $this->assertSame(0, DB::table('tbl_equipment_borrowing')
            ->join('tbl_residents', 'tbl_residents.resident_id', '=', 'tbl_equipment_borrowing.resident_id')
            ->whereColumn('tbl_equipment_borrowing.created_at', '<', 'tbl_residents.created_at')->count());
        $this->assertSame(0, DB::table('tbl_equipment_borrowing')->whereColumn('updated_at', '<', 'created_at')->count());
        $this->assertSame(0, ServiceRequest::whereNotNull('resident_id')->whereNull('barangay_id')->count());

        // Open statuses are recent; everything older is final.
        $this->assertSame(0, ServiceRequest::whereIn('status', ['Pending', 'Booked', 'Responding'])->where('created_at', '<', Carbon::now()->subDays(29))->count());
        $this->assertSame(0, EquipmentBorrowing::whereIn('status', ['Pending', 'Approved', 'Released'])->where('created_at', '<', Carbon::now()->subDays(41))->count());
        $this->assertGreaterThan(0, ServiceRequest::where('status', 'Resolved')->where('created_at', '<', Carbon::now()->subDays(60))->count());
        $this->assertGreaterThan(0, EquipmentBorrowing::where('status', 'Returned')->where('created_at', '<', Carbon::now()->subDays(60))->count());

        // Some residents never file, and a few file much more than the rest.
        $filed = ServiceRequest::whereNotNull('resident_id')->groupBy('resident_id')->selectRaw('resident_id, count(*) n')->pluck('n', 'resident_id');
        $this->assertLessThan($seeded->count(), $filed->count());
        $this->assertGreaterThan($filed->avg(), $filed->max());

        // Stock: available = total - Released.
        foreach (Equipment::all() as $item) {
            $out = (int) EquipmentBorrowing::where('equipment_id', $item->equipment_id)->where('status', 'Released')->sum('quantity');
            $this->assertSame($item->total_quantity - $out, $item->available_quantity, $item->item_name);
        }

        // Blasts reach only opted-in residents who were already registered.
        $this->assertGreaterThan(0, DB::table('tbl_sms_logs')->count());
        $this->assertSame(0, DB::table('tbl_recipients')
            ->join('tbl_sms_logs', 'tbl_sms_logs.sms_log_id', '=', 'tbl_recipients.sms_log_id')
            ->join('tbl_residents', 'tbl_residents.resident_id', '=', 'tbl_recipients.resident_id')
            ->where(fn ($q) => $q->where('tbl_residents.sms_opt_in', 0)->orWhereColumn('tbl_residents.created_at', '>', 'tbl_sms_logs.created_at'))
            ->count());

        // Re-running adds no residents or staff, and DemoAccountsSeeder still works afterwards.
        $this->seedVolume();
        $this->assertSame(30, Resident::where('phone_number', 'like', '+63999%')->count());
        $this->assertSame(6, User::count());

        $this->seed(DemoAccountsSeeder::class);
        $this->assertSame(10, Resident::where('email_address', 'like', 'demo.%@serbis.test')->count());
        $this->assertSame(30, Resident::where('phone_number', 'like', '+63999%')->count());
    }
}
