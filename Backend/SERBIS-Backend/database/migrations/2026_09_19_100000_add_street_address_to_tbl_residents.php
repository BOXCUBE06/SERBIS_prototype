<?php

// xxxx_xx_xx_add_street_address_to_tbl_residents.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // MDRRMO feedback, 2026-09-19: tbl_residents carries a barangay_id and
        // nothing finer, so every free-text address field across the app
        // (ambulance patient address, relief goods, delivery address) has had
        // to ask the resident to retype their purok/street every single time.
        // This is the one place it is captured once and reused everywhere —
        // set at registration, editable later from the profile.
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->string('street_address')->nullable()->after('barangay_id');
        });
    }

    public function down()
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropColumn('street_address');
        });
    }
};
