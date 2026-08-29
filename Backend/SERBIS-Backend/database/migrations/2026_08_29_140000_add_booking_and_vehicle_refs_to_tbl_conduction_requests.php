<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first foreign keys `tbl_conduction_requests` has ever had — it has no
 * index beyond the primary key today.
 *
 * `service_request_id` links a trip log back to the booking it fulfils.
 * Null means a walk-in trip with no prior booking, which is every row that
 * exists right now — nothing populates this column here.
 *
 * `vehicle_id` is the real, referential companion to the free-text `vehicle`
 * and `plate_no` columns, which stay in place and nullable; 5C is where a
 * write path picks between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->foreignId('service_request_id')
                ->nullable()
                ->after('conduction_request_id')
                ->constrained('tbl_service_request', 'request_id')
                ->nullOnDelete();

            $table->foreignId('vehicle_id')
                ->nullable()
                ->after('service_request_id')
                ->constrained('tbl_vehicles', 'vehicle_id')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropConstrainedForeignId('service_request_id');
        });
    }
};
