<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every ambulance walk-in and app booking has always carried its patient,
// pickup and destination as one free-text `description` paragraph -- the
// Trip Logs form then regex-parses it back out (parseAmbulanceDescription,
// ConductionRequestView.vue) when a booking is dispatched, silently, per
// field, with no error surfaced when a line doesn't match. These columns
// are the structured version of the same facts, for ambulance requests
// only. `description` is untouched and stays the single field every other
// service type uses.
//
// All nullable, so every existing row -- and every request the mobile app
// still files with only `description` -- stays valid. Only the admin
// panel's own walk-in form is wired to fill these in this pass; the
// mobile app filling them in directly is a separate, larger change this
// plan does not attempt.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->string('patient_name')->nullable()->after('description');
            $table->unsignedTinyInteger('patient_age')->nullable()->after('patient_name');
            $table->enum('patient_sex', ['male', 'female'])->nullable()->after('patient_age');
            $table->string('patient_address')->nullable()->after('patient_sex');
            $table->string('pickup_location')->nullable()->after('patient_address');
            $table->string('destination')->nullable()->after('pickup_location');
            $table->text('condition_notes')->nullable()->after('destination');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn([
                'patient_name',
                'patient_age',
                'patient_sex',
                'patient_address',
                'pickup_location',
                'destination',
                'condition_notes',
            ]);
        });
    }
};
