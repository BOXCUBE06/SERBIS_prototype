<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MDRRMO feedback, 2026-09-17: an "Others" tile in the services list, for a
// request that names nothing on the seeded seven-service catalogue. Mirrors
// how tbl_equipment_borrowing.equipment_id already handles an uncatalogued
// item — nullable, with the resident's own words carrying the rest. Here
// that free-text home already exists (`description`, required whenever
// service_id isn't the ambulance row — see ServiceRequestController's
// `required_unless:service_id,...` rule), so no new column is needed, only
// the FK itself becoming optional.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->change();
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->foreign('service_id')->references('service_id')->on('tbl_services');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->foreign('service_id')->references('service_id')->on('tbl_services');
        });
    }
};
