<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The barangay a request was filed under, kept on the request. Residents can
 * now change their own barangay (PATCH /me); without this, every past request
 * would move with them on the map and in the filters.
 *
 * Nullable: a walk-in has no account and so no barangay. Set by
 * ServiceRequest::booted(), never by a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->foreignId('barangay_id')->nullable()->after('resident_id')
                ->constrained('tbl_barangay', 'barangay_id')->restrictOnDelete();
        });

        // Today's barangay is the best answer the data has: earlier moves were
        // made by staff and not recorded per request.
        DB::statement('
            UPDATE tbl_service_request sr
            JOIN tbl_residents r ON r.resident_id = sr.resident_id
            SET sr.barangay_id = r.barangay_id
            WHERE sr.barangay_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropConstrainedForeignId('barangay_id');
        });
    }
};
