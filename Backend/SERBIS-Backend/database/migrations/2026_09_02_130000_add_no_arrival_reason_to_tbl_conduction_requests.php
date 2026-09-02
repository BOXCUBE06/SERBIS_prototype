<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A dispatched trip that never arrives (patient already left, crew recalled
// mid-route, transport refused) has no way to record why — the resolve gate
// in ServiceRequestController::update() requires arrived_destination_at with
// no override. This does not touch that gate; it only gives staff a place to
// write the reason once one exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->string('no_arrival_reason', 500)->nullable()->after('arrived_destination_at');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->dropColumn('no_arrival_reason');
        });
    }
};
