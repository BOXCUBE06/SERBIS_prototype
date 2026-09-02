<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient relatives named on the request itself, before any trip exists.
 *
 * tbl_conduction_request_people cannot hold these: its FK points at
 * tbl_conduction_requests, and that row is not created until the request
 * reaches Responding (ServiceRequestController::createConductionStub, or a
 * manually filed trip). Relatives now have to be collected at intake, which
 * is strictly earlier.
 *
 * Kept as a separate table rather than a nullable second parent on the
 * people table: these are two different facts with two different lifetimes.
 * This one is a claim made at intake about who intends to travel; the trip
 * manifest is what the crew actually logged. Merging them would overwrite
 * the first with the second and would force every existing reader of
 * people()/drivers() to understand a nullable parent it currently does not.
 *
 * Shape deliberately mirrors tbl_conduction_request_people minus `role` —
 * relatives are the only thing collected at intake, and a one-value enum
 * would be noise. Add one if a second intake role ever appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_service_request_relatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')
                ->constrained('tbl_service_request', 'request_id')
                ->cascadeOnDelete();
            $table->string('name');
            // Preserves entry order, same as the people table's own column:
            // "Relative 1" vs "Relative 2" is a display distinction, but the
            // form still has to render them back as they were typed.
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_service_request_relatives');
    }
};
