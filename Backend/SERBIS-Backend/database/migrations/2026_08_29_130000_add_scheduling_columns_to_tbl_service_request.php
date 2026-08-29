<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same reasoning as `2026_08_22_100000`/`2026_08_22_120000`: a composite led
 * by an equality column cannot serve a query with no predicate on that
 * column. `(status, scheduled_at)` and `(vehicle_id, scheduled_at)` are not
 * meant to — they exist for "Booked requests, in schedule order" and
 * "this vehicle's bookings, in schedule order", both of which anchor on the
 * leading equality column. Neither is a substitute for a standalone
 * `scheduled_at` index, and none is added here because nothing yet queries
 * `scheduled_at` unfiltered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->index(['status', 'scheduled_at'], 'tbl_service_request_status_scheduled_at_index');
            $table->index(['vehicle_id', 'scheduled_at'], 'tbl_service_request_vehicle_id_scheduled_at_index');
        });
    }

    public function down(): void
    {
        // `vehicle_id` carries a foreign key, and dropping
        // `tbl_service_request_vehicle_id_scheduled_at_index` below would leave
        // that constraint with no supporting index at all — MariaDB refuses
        // the DROP INDEX outright ("needed in a foreign key constraint").
        // A plain index on `vehicle_id` alone keeps the FK backed once the
        // composite is gone.
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->index('vehicle_id', 'tbl_service_request_vehicle_id_index');
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropIndex('tbl_service_request_status_scheduled_at_index');
            $table->dropIndex('tbl_service_request_vehicle_id_scheduled_at_index');

            $table->dropColumn(['scheduled_at', 'scheduled_end', 'approved_at']);
        });
    }
};
