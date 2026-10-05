<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Responder;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\VehicleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * serbis:reseed-requests. Own migrate:fresh rather than RefreshDatabase: the
 * command resets AUTO_INCREMENT, DDL that commits on MySQL and would escape the
 * per-test transaction (same as DropAmbulanceColumnsMigrationTest).
 */
class ReseedRequestDataTest extends TestCase
{
    private Resident $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        // The command refuses to run with real SMS possible (phpunit.xml pins this false).
        config(['serbis.sms_fake' => true]);
        // The real private disk is the developer's storage/app/private.
        Storage::fake(config('filesystems.uploads.private'));

        $this->seed([ServiceSeeder::class, VehicleSeeder::class, EquipmentSeeder::class]);
        User::create(['first_name' => 'MDRRMO', 'last_name' => 'Admin', 'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active']);
        Responder::create(['name' => 'Rodel Cabantog', 'contact_no' => '09175550000', 'position' => 'Driver', 'status' => 'available']);

        foreach (['San Fabian', 'San Miguel', 'Angoluan', 'Cabugao'] as $name) {
            Barangay::create(['barangay_name' => $name]);
        }
        $this->household = $this->resident('09171111111', Resident::TYPE_HEAD_OF_FAMILY, 1);
        $this->resident('09172222222', Resident::TYPE_BARANGAY, 2);
        $this->resident('09173333333', Resident::TYPE_ORGANIZATION, 3, 'Red Cross Echague');
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function resident(string $phone, string $type, int $barangayId, ?string $org = null): Resident
    {
        $resident = new Resident;
        // account_type and organization_name are not fillable.
        $resident->forceFill([
            'barangay_id' => $barangayId, 'first_name' => 'Maria', 'last_name' => 'Santos', 'phone_number' => $phone,
            'password' => Hash::make('password123'), 'status' => 'Active', 'account_type' => $type, 'organization_name' => $org,
        ])->save();

        return $resident;
    }

    public function test_it_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('serbis:reseed-requests', ['--force' => true])->assertFailed();
    }

    public function test_it_refuses_any_other_database(): void
    {
        $default = config('database.default');
        config(["database.connections.{$default}.database" => 'serbis_production']);

        $this->artisan('serbis:reseed-requests', ['--force' => true])->assertFailed();
    }

    public function test_it_replaces_the_data_and_leaves_everything_consistent(): void
    {
        ServiceRequest::create(['resident_id' => $this->household->resident_id, 'status' => 'Pending', 'description' => 'OLD ROW']);
        $residentsBefore = Resident::orderBy('resident_id')->get(['resident_id', 'barangay_id', 'updated_at'])->toArray();

        $this->artisan('serbis:reseed-requests', ['--force' => true, '--seed' => 7])->assertSuccessful();

        $this->assertSame(0, ServiceRequest::where('description', 'OLD ROW')->count());
        $this->assertGreaterThan(250, ServiceRequest::count());
        $this->assertGreaterThan(50, EquipmentBorrowing::count());

        // The hook set every account's barangay; walk-ins have none.
        $this->assertSame(0, ServiceRequest::whereNotNull('resident_id')->whereNull('barangay_id')->count());
        $this->assertSame(0, ServiceRequest::whereNull('resident_id')->whereNotNull('barangay_id')->count());
        $this->assertGreaterThan(0, ServiceRequest::whereNull('resident_id')->count());
        // Some requests were filed before their resident "moved".
        $this->assertGreaterThan(0, DB::table('tbl_service_request')
            ->join('tbl_residents', 'tbl_residents.resident_id', '=', 'tbl_service_request.resident_id')
            ->whereColumn('tbl_service_request.barangay_id', '!=', 'tbl_residents.barangay_id')->count());

        // Residents end exactly as they began.
        $this->assertSame($residentsBefore, Resident::orderBy('resident_id')->get(['resident_id', 'barangay_id', 'updated_at'])->toArray());

        // Stock: available = total - Released.
        foreach (Equipment::all() as $item) {
            $out = (int) EquipmentBorrowing::where('equipment_id', $item->equipment_id)->where('status', 'Released')->sum('quantity');
            $this->assertSame($item->total_quantity - $out, $item->available_quantity, $item->item_name);
            $this->assertGreaterThan(0, $item->available_quantity, $item->item_name.' left with none on the shelf');
        }
        // At least half the crew is free.
        $this->assertGreaterThanOrEqual(Responder::count() / 2, Responder::where('status', 'available')->count());

        // Fleet: Dispatched exactly when a Responding request holds the unit.
        $held = ServiceRequest::where('status', 'Responding')->whereNotNull('vehicle_id')->pluck('vehicle_id')->sort()->values()->all();
        $this->assertSame($held, Vehicle::where('status', 'Dispatched')->orderBy('vehicle_id')->pluck('vehicle_id')->all());

        // Every Booked ambulance row is already reminded.
        $this->assertSame(0, ServiceRequest::where('status', 'Booked')
            ->whereHas('ambulanceBooking', fn ($q) => $q->whereNull('scheduled_reminder_sent_at'))->count());
    }
}
