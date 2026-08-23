<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last remnant of the disaster taxonomy. `tbl_disaster` went on
 * 2026-08-22 along with the foreign key; the column stayed behind on the
 * belief that dropping it would break the mobile advisory contract.
 *
 * That belief was wrong, and the check is cheap to repeat: `Mobile/lib/` has
 * no reference to `disaster_id` or `disasterId` anywhere, and
 * `Advisory.fromJson` never reads the key. The only mention in the client was
 * an ignored entry in a test fixture, removed with this migration. The admin
 * panel never referenced it at all.
 *
 * So the column is a nullable bigint pointing at a table that no longer
 * exists, written as a hardcoded `null` on every blast and read by nothing.
 * Its index survived the constraint and is dropped first — explicitly, rather
 * than relying on the engine to discard a single-column index along with its
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->dropIndex('tbl_sms_logs_disaster_id_foreign');
            $table->dropColumn('disaster_id');
        });
    }

    public function down(): void
    {
        // Restored without the foreign key: `tbl_disaster` is gone, and the
        // migration that dropped it recreates the constraint itself when it is
        // rolled back. Every value was null, so there is nothing to backfill.
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('disaster_id')->nullable()->after('target_area_id');
            $table->index('disaster_id', 'tbl_sms_logs_disaster_id_foreign');
        });
    }
};
