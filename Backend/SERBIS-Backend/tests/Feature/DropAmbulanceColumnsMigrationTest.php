<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_10_100000_drop_ambulance_columns_from_tbl_service_request —
 * specifically its down(), which is the only side of this migration with
 * any logic worth testing (up() is a column/index drop; the earlier
 * migration tests already cover the backfill it mirrors).
 *
 * Rolled back with --step=1, never a full migrate:rollback: the full chain
 * eventually reaches 2026_08_31_150000's broken down() (see
 * AmbulanceBookingsMigrationTest's class doc comment) — --step=1 only ever
 * touches this migration, the most recent one.
 *
 * Runs its own migrate:fresh rather than RefreshDatabase for the same
 * reason as AmbulanceBookingsMigrationTest: the rollback here is real DDL,
 * which implicit-commits on MySQL/MariaDB and would escape RefreshDatabase's
 * per-test transaction. RefreshDatabaseState::$migrated is reset in
 * tearDown() so the next RefreshDatabase test class migrates fresh for
 * itself instead of trusting this class's rolled-back schema.
 */
class DropAmbulanceColumnsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_rolling_back_restores_the_columns_with_values_copied_from_the_booking(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $request = ServiceRequest::create([
            'resident_id' => $resident->getKey(),
            'service_id' => $ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'patient_name' => 'Juan Dela Cruz',
            'patient_age' => 62,
            'patient_sex' => 'male',
            'patient_address' => 'Purok 2, San Fabian',
            'patient_contact_number' => '09189999999',
            'pickup_location' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Chest pains',
            'scheduled_at' => Carbon::parse('2026-09-15 08:00:00'),
            'scheduled_end' => Carbon::parse('2026-09-15 10:00:00'),
            'approved_at' => Carbon::parse('2026-09-10 09:30:00'),
        ]);

        // A placeholder row too — the invariant this migration's forward
        // backfill established (one booking per ambulance request, data or
        // not) must round-trip through down() the same way.
        $placeholder = ServiceRequest::create([
            'service_id' => $ambulance->getKey(),
            'status' => 'Booked',
        ]);
        AmbulanceBooking::create(['request_id' => $placeholder->getKey()]);

        $this->assertFalse(Schema::hasColumn('tbl_service_request', 'patient_name'));

        $this->artisan('migrate:rollback', ['--step' => 1]);

        $this->assertTrue(Schema::hasColumn('tbl_service_request', 'patient_name'));
        $this->assertTrue(Schema::hasColumn('tbl_service_request', 'scheduled_at'));
        $this->assertTrue(Schema::hasColumn('tbl_service_request', 'scheduled_end'));
        $this->assertTrue(Schema::hasColumn('tbl_service_request', 'approved_at'));

        $row = DB::table('tbl_service_request')->where('request_id', $request->getKey())->first();
        $this->assertSame('Juan Dela Cruz', $row->patient_name);
        $this->assertSame(62, (int) $row->patient_age);
        $this->assertSame('male', $row->patient_sex);
        $this->assertSame('Purok 2, San Fabian', $row->patient_address);
        $this->assertSame('09189999999', $row->patient_contact_number);
        $this->assertSame('Purok 2, San Fabian', $row->pickup_location);
        $this->assertSame('Echague District Hospital', $row->destination);
        $this->assertSame('Chest pains', $row->condition_notes);
        $this->assertNotNull($row->scheduled_at);
        $this->assertNotNull($row->scheduled_end);
        $this->assertNotNull($row->approved_at);

        $placeholderRow = DB::table('tbl_service_request')->where('request_id', $placeholder->getKey())->first();
        $this->assertNull($placeholderRow->patient_name);
        $this->assertNull($placeholderRow->scheduled_at);

        // The standalone vehicle_id index this migration's up() added is
        // gone again; the restored (vehicle_id, scheduled_at) composite is
        // what backs the foreign key now — the same index the column had
        // before any of this series ran. The rollback call above already
        // proved this ordering doesn't break the FK (a wrong order throws
        // SQLSTATE 1553 immediately), this just names what covers it.
        $this->assertTrue(Schema::hasIndex('tbl_service_request', ['vehicle_id', 'scheduled_at']));
        $this->assertFalse(Schema::hasIndex('tbl_service_request', 'tbl_service_request_vehicle_id_index'));
    }
}
