<?php

// xxxx_xx_xx_create_tbl_ambulance_destinations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // The dropdown source for the ambulance form's destination field
        // (MDRRMO feedback, 2026-09-19). No hospital API exists — DOH
        // publishes facility lists as web pages and PDFs only, and the
        // national list runs to ~1,900 entries for maybe 10 real
        // destinations this office ever actually sends a unit to. This is
        // free text still: `tbl_ambulance_bookings.destination` is
        // unchanged, so an "Others" entry a resident types is stored
        // exactly the way every destination already was.
        Schema::create('tbl_ambulance_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tbl_ambulance_destinations');
    }
};
