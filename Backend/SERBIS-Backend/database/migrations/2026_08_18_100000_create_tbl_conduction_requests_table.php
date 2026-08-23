<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MDRRMO Conduction Request Form (Echague Rescue EMS). A dedicated table
// instead of a row in tbl_service_request: the physical form the office
// already uses has fields (driver names, plate number, odometer readings,
// a trip log filled in after dispatch) with no equivalent anywhere in the
// generic request pipeline, and shoehorning them in would have meant a pile
// of nullable columns on tbl_service_request that every other service type
// carries and never fills. Filed by MDRRMO staff, not residents — there is
// no resident_id here, the same way tbl_vehicles carries none.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_conduction_requests', function (Blueprint $table) {
            $table->id('conduction_request_id');

            // Patient / trip details, in the order the paper form asks for them.
            $table->string('patient_name');
            $table->unsignedTinyInteger('patient_age')->nullable();
            $table->string('patient_address');
            $table->enum('patient_sex', ['male', 'female'])->nullable();
            $table->string('patient_contact_number');
            $table->string('vehicle')->nullable();
            $table->text('medical_diagnosis');
            $table->string('plate_no')->nullable();
            $table->string('origin');
            $table->string('destination');

            // Trip log — filled in after dispatch, via a separate action, never
            // on the request-creation form. All nullable for that reason.
            $table->dateTime('departed_office_at')->nullable();
            $table->dateTime('arrived_destination_at')->nullable();
            $table->dateTime('departed_destination_at')->nullable();
            $table->dateTime('returned_office_at')->nullable();
            $table->unsignedInteger('odometer_start')->nullable();
            $table->unsignedInteger('odometer_end')->nullable();
            $table->text('others')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_conduction_requests');
    }
};
