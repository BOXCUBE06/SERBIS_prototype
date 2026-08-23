<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ServiceRequestController::adminIndex()` calls `latest()`, which orders by
 * `created_at` — a column with no index. Both shapes the admin list runs
 * finish with `Using filesort`: the unfiltered one scans the whole table, and
 * the status-filtered one uses `tbl_service_request_status_index` for the
 * lookup and then sorts the matches by hand.
 *
 * `(status, created_at)` covers both. Leading with `status` keeps the existing
 * equality lookup and lets `created_at` supply the order for free; a query with
 * no status filter can still range-scan the same index in `created_at` order
 * within each status, which is cheaper than a full sort once the table is large
 * enough for the optimiser to prefer it.
 *
 * At 39 rows none of this is measurable, and MariaDB may well keep choosing a
 * full scan — a sequential read of 39 rows beats any index. This is here for
 * the row counts the table will actually reach, not for today's.
 *
 * The single-column `tbl_service_request_status_index` is now covered by this
 * index's leftmost prefix and is a candidate for removal, but that is a
 * separate concern and is deliberately left in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'tbl_service_request_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropIndex('tbl_service_request_status_created_at_index');
        });
    }
};
