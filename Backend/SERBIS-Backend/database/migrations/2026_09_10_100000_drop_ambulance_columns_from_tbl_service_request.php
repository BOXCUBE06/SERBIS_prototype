<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The last step of the ambulance_bookings split. Nothing in Backend/ has
// written or read these columns off tbl_service_request since the previous
// two migrations in this series (2026_09_10_090000's backfill and the
// controllers/services that followed) — tbl_ambulance_bookings has been the
// only source of truth for them for a while now. This just catches the
// schema up to that.
return new class extends Migration
{
    private const MOVED_COLUMNS = [
        'patient_name', 'patient_age', 'patient_sex', 'patient_address',
        'patient_contact_number', 'pickup_location', 'destination', 'condition_notes',
        'scheduled_at', 'scheduled_end', 'approved_at',
    ];

    public function up(): void
    {
        // vehicle_id's foreign key needs its own supporting index before the
        // composite (vehicle_id, scheduled_at) one goes, or MariaDB refuses
        // the drop outright ("needed in a foreign key constraint") — same
        // fix 2026_08_29_130000's own down() already uses for the same
        // reason.
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->index('vehicle_id', 'tbl_service_request_vehicle_id_index');
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropIndex('tbl_service_request_status_scheduled_at_index');
            $table->dropIndex('tbl_service_request_vehicle_id_scheduled_at_index');

            $table->dropColumn(self::MOVED_COLUMNS);
        });
    }

    public function down(): void
    {
        // Types, lengths and positions exactly as
        // 2026_08_31_085105_add_ambulance_intake_fields_to_tbl_service_request_table,
        // 2026_09_02_120000_add_patient_contact_number_to_tbl_service_request and
        // 2026_08_29_130000_add_scheduling_columns_to_tbl_service_request
        // originally added them.
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->string('patient_name')->nullable()->after('description');
            $table->unsignedTinyInteger('patient_age')->nullable()->after('patient_name');
            $table->enum('patient_sex', ['male', 'female'])->nullable()->after('patient_age');
            $table->string('patient_address')->nullable()->after('patient_sex');
            $table->string('patient_contact_number', 32)->nullable()->after('patient_address');
            $table->string('pickup_location')->nullable()->after('patient_contact_number');
            $table->string('destination')->nullable()->after('pickup_location');
            $table->text('condition_notes')->nullable()->after('destination');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('approved_at')->nullable();
        });

        // Copied back from the one place that has held the real values since
        // the columns were dropped. Plain multi-table UPDATE, no
        // engine-specific syntax — the same portability the forward
        // migration's INSERT ... SELECT relied on.
        $assignments = implode(', ', array_map(
            fn (string $column) => "sr.{$column} = b.{$column}",
            self::MOVED_COLUMNS
        ));

        DB::statement(
            "UPDATE tbl_service_request sr
             JOIN tbl_ambulance_bookings b ON b.request_id = sr.request_id
             SET {$assignments}"
        );

        // The composite goes back before the standalone vehicle_id index
        // comes off — vehicle_id's foreign key needs an index backing it at
        // every point in between, and (vehicle_id, scheduled_at) is what
        // covers that once it exists again.
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at'], 'tbl_service_request_status_scheduled_at_index');
            $table->index(['vehicle_id', 'scheduled_at'], 'tbl_service_request_vehicle_id_scheduled_at_index');
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropIndex('tbl_service_request_vehicle_id_index');
        });
    }
};
