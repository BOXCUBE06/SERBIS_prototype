<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The companion `tbl_service_request` got in
 * `2026_08_22_120000_add_created_at_index_to_tbl_service_request.php`, for the
 * same reason.
 *
 * `EquipmentBorrowingController::index()` is `orderBy('created_at', 'desc')`
 * with no unconditional `where` — a resident's own history filters on
 * `resident_id`, but the admin view has no predicate to anchor a composite,
 * so `created_at` first is the only shape that can order the whole table
 * without a filesort. `AnalyticsController::index()` additionally range-
 * filters this same column on every dashboard load (`created_at >= $since`,
 * once per period in the map and pie sections, plus the 30-day bar chart) —
 * this is that column too, and it had no index at all before this migration.
 *
 * Same caveat as the service-request index: on a table this small a full
 * scan may still beat the index, and MariaDB may keep choosing one. The plan
 * is what changes here, not the clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->index('created_at', 'tbl_equipment_borrowing_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropIndex('tbl_equipment_borrowing_created_at_index');
        });
    }
};
