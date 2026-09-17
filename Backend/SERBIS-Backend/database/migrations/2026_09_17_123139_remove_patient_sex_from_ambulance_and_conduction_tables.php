<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MDRRMO feedback, 2026-09-17: the ambulance intake form is not gender
// sensitive and the field goes away, not just its UI. Nothing in Backend/,
// Web/serbis-admin-vue or Mobile/ reads or writes patient_sex any more as of
// this commit.
//
// tbl_service_request never carries this column in the current schema — it
// moved to tbl_ambulance_bookings in 2026_09_10_090000/100000 and this
// migration does not touch that table, so 2026_09_10_100000's down() (which
// copies patient_sex back from tbl_ambulance_bookings by name) still finds
// the column there whenever this migration is rolled back first.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_ambulance_bookings', function (Blueprint $table) {
            $table->dropColumn('patient_sex');
        });

        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->dropColumn('patient_sex');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_ambulance_bookings', function (Blueprint $table) {
            $table->enum('patient_sex', ['male', 'female'])->nullable()->after('patient_age');
        });

        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->enum('patient_sex', ['male', 'female'])->nullable()->after('patient_address');
        });
    }
};
