<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026_09_10_090000_create_tbl_ambulance_bookings_table — the table itself,
 * plus its one-shot backfill.
 *
 * Runs its own migrate:fresh rather than using RefreshDatabase or
 * DatabaseMigrations: every test here drops and recreates
 * tbl_ambulance_bookings by hand to re-run the migration's up() against data
 * seeded beforehand, and CREATE/DROP TABLE issue an implicit commit on
 * MySQL/MariaDB — inside RefreshDatabase's per-test transaction that would
 * leak committed rows into every later test. DatabaseMigrations avoids that
 * by rolling every migration back after each test, but that walks an
 * unrelated, pre-existing broken down() elsewhere in the chain
 * (2026_08_31_150000's dropUnique fails once later migrations have added an
 * FK that needs the same index) — not this change's to fix. Marking
 * RefreshDatabaseState::$migrated false in tearDown() is what the failed
 * rollback would otherwise have done: it tells the next RefreshDatabase test
 * class to migrate fresh for itself instead of trusting this class's
 * modified schema.
 *
 * setUp() also rolls back 2026_09_10_100000 right after the fresh migrate —
 * that migration has since dropped the columns this one's backfill writes
 * to, so testing this migration's up() at all needs them back first. Done
 * by --path, not --step: --step picks whichever migration is chronologically
 * last across the whole table, which silently became wrong the moment
 * return-due-reminder's own migration landed after this series and started
 * eating the rollback meant for this one. --path names the file directly and
 * stays correct no matter what gets appended to the chain later.
 */
class AmbulanceBookingsMigrationTest extends TestCase
{
    private Service $ambulance;

    private Service $roadClearing;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        // Rolled back on its own, by --path rather than --step (see the
        // class doc comment), so tbl_service_request has the moved columns
        // back again, matching the schema this migration actually runs
        // against.
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_10_100000_drop_ambulance_columns_from_tbl_service_request.php',
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->roadClearing = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris removal.',
        ]);

        // The normal migration run already created and (against an empty
        // database) backfilled the table. Dropped here so each test can
        // seed rows first and re-run up() against real data.
        Schema::dropIfExists('tbl_ambulance_bookings');
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    /** A fresh instance of the migration under test, same as the migrator resolves. */
    private function migration(): object
    {
        return require database_path('migrations/2026_09_10_090000_create_tbl_ambulance_bookings_table.php');
    }

    public function test_it_backfills_every_ambulance_row_including_a_placeholder_with_null_patient_fields(): void
    {
        // forceCreate(), not create(): these 11 columns are no longer
        // fillable on ServiceRequest (they don't exist on the table in the
        // normal, fully-migrated state) — this simulates the raw legacy row
        // the pre-drop schema, temporarily restored in setUp(), actually had.
        $withPatient = ServiceRequest::forceCreate([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
            'patient_name' => 'Juan Dela Cruz',
            'patient_age' => 62,
            'patient_sex' => 'male',
            'patient_address' => 'Purok 2, San Fabian',
            'patient_contact_number' => '09189999999',
            'pickup_location' => 'Purok 2, San Fabian',
            'destination' => 'Echague District Hospital',
            'condition_notes' => 'Chest pains',
            'scheduled_at' => '2026-09-15 08:00:00',
            'scheduled_end' => '2026-09-15 10:00:00',
            'approved_at' => '2026-09-10 09:30:00',
        ]);

        // A walk-in placeholder slot: an ambulance row with every patient
        // field null. The invariant is one booking row per ambulance
        // request, not one per row that has data.
        $placeholder = ServiceRequest::create([
            'service_id' => $this->ambulance->getKey(),
            'status' => 'Booked',
        ]);

        $notAmbulance = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->getKey(),
            'description' => 'Fallen tree.',
            'status' => 'Pending',
        ]);

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('tbl_ambulance_bookings'));
        $this->assertSame(2, DB::table('tbl_ambulance_bookings')->count());

        $bookingWithPatient = DB::table('tbl_ambulance_bookings')->where('request_id', $withPatient->getKey())->first();
        $this->assertSame('Juan Dela Cruz', $bookingWithPatient->patient_name);
        $this->assertSame(62, (int) $bookingWithPatient->patient_age);
        $this->assertSame('male', $bookingWithPatient->patient_sex);
        $this->assertSame('09189999999', $bookingWithPatient->patient_contact_number);
        $this->assertSame('Echague District Hospital', $bookingWithPatient->destination);
        $this->assertNotNull($bookingWithPatient->scheduled_at);
        $this->assertNotNull($bookingWithPatient->created_at);
        $this->assertNotNull($bookingWithPatient->updated_at);

        $bookingPlaceholder = DB::table('tbl_ambulance_bookings')->where('request_id', $placeholder->getKey())->first();
        $this->assertNotNull($bookingPlaceholder);
        $this->assertNull($bookingPlaceholder->patient_name);
        $this->assertNull($bookingPlaceholder->scheduled_at);

        $this->assertNull(
            DB::table('tbl_ambulance_bookings')->where('request_id', $notAmbulance->getKey())->first()
        );
    }

    public function test_it_skips_the_backfill_when_the_ambulance_service_does_not_exist_yet(): void
    {
        // A fresh migrate before ServiceSeeder has run: no service has the
        // ambulance code at all.
        DB::table('tbl_services')->delete();

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('tbl_ambulance_bookings'));
        $this->assertSame(0, DB::table('tbl_ambulance_bookings')->count());
    }

    public function test_it_refuses_to_backfill_if_a_non_ambulance_row_carries_moved_column_data(): void
    {
        ServiceRequest::forceCreate([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->getKey(),
            'description' => 'Fallen tree.',
            'status' => 'Pending',
            // Should never happen in practice — store()/adminStore() null
            // these out for every non-ambulance request — but the migration
            // must not silently drop this data if it ever does.
            'patient_name' => 'Should not be here',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-ambulance rows/');

        $this->migration()->up();
    }

    public function test_down_drops_the_table_and_leaves_the_parent_untouched(): void
    {
        $withPatient = ServiceRequest::forceCreate([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->getKey(),
            'description' => 'Patient: Juan Dela Cruz',
            'status' => 'Booked',
            'patient_name' => 'Juan Dela Cruz',
            'destination' => 'Echague District Hospital',
        ]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasTable('tbl_ambulance_bookings'));
        // Raw query, not Eloquent: ServiceRequest::$with always asks for
        // ambulanceBooking now, and that table is gone at this point in the
        // test on purpose — the migration's own concern is the parent
        // column, read here independent of anything the model layer does.
        $this->assertSame(
            'Juan Dela Cruz',
            DB::table('tbl_service_request')->where('request_id', $withPatient->getKey())->value('patient_name')
        );
    }
}
