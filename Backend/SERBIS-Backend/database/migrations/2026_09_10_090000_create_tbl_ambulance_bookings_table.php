<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 1:1 extension of tbl_service_request, keyed by the same request_id, for
// ambulance's own columns only. Ambulance stays a row in tbl_service_request
// like every other service; only the patient/scheduling facts move here.
//
// Column types, lengths and nullability copied verbatim from
// 2026_08_31_085105_add_ambulance_intake_fields_to_tbl_service_request_table
// and 2026_09_02_120000_add_patient_contact_number_to_tbl_service_request —
// this is a relocation, not a redesign.
return new class extends Migration
{
    /** Every column this migration moves off tbl_service_request. */
    private const MOVED_COLUMNS = [
        'patient_name', 'patient_age', 'patient_sex', 'patient_address',
        'patient_contact_number', 'pickup_location', 'destination', 'condition_notes',
        'scheduled_at', 'scheduled_end', 'approved_at',
    ];

    public function up(): void
    {
        Schema::create('tbl_ambulance_bookings', function (Blueprint $table) {
            $table->foreignId('request_id')
                ->primary()
                ->constrained('tbl_service_request', 'request_id')
                ->cascadeOnDelete();
            $table->string('patient_name')->nullable();
            $table->unsignedTinyInteger('patient_age')->nullable();
            $table->enum('patient_sex', ['male', 'female'])->nullable();
            $table->string('patient_address')->nullable();
            $table->string('patient_contact_number', 32)->nullable();
            $table->string('pickup_location')->nullable();
            $table->string('destination')->nullable();
            $table->text('condition_notes')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->index(['scheduled_at', 'scheduled_end']);
        });

        // Resolved by code, never hardcoded — service_id is an auto-increment
        // and is not portable across a fresh database.
        $ambulanceServiceId = DB::table('tbl_services')->where('code', 'ambulance-medical-response')->value('service_id');

        // Fresh migrate, before ServiceSeeder has run: nothing to back-fill
        // yet. The table is ready for whenever ambulance rows do exist.
        if (! $ambulanceServiceId) {
            return;
        }

        // These columns are only ever supposed to be non-null on an ambulance
        // row. If any other service somehow has data here, a plain
        // "WHERE service_id = ambulance" backfill would silently leave it
        // behind on the parent row forever — stop instead of copying half
        // the picture.
        $leaked = DB::table('tbl_service_request')
            ->where('service_id', '!=', $ambulanceServiceId)
            ->where(function ($query) {
                foreach (self::MOVED_COLUMNS as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->pluck('request_id');

        if ($leaked->isNotEmpty()) {
            throw new \RuntimeException(
                'tbl_service_request has ambulance-only column data on non-ambulance rows, refusing to backfill '
                .'tbl_ambulance_bookings: request_id '.$leaked->implode(', ')
            );
        }

        $columns = implode(', ', array_merge(['request_id'], self::MOVED_COLUMNS, ['created_at', 'updated_at']));

        // One INSERT ... SELECT, plain SQL with no engine-specific syntax —
        // runs the same on MariaDB 10.4 (local) and MySQL 8 (Aiven). Copies
        // every ambulance row, including walk-in placeholders with every
        // patient column null: the invariant is one booking row per
        // ambulance request, not one per row with data.
        DB::statement(
            "INSERT INTO tbl_ambulance_bookings ({$columns}) SELECT {$columns} FROM tbl_service_request WHERE service_id = ?",
            [$ambulanceServiceId]
        );
    }

    public function down(): void
    {
        // Parent columns are untouched by this migration and stay untouched
        // here — dropping this table only undoes the copy.
        Schema::dropIfExists('tbl_ambulance_bookings');
    }
};
