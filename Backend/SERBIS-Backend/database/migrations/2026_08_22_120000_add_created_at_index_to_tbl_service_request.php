<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The companion to `(status, created_at)`, which cannot serve this query.
 *
 * `ServiceRequestController::adminIndex()` is `latest()` with no `where` at
 * all. A composite led by `status` needs an equality on `status` to anchor it;
 * with no predicate the optimiser cannot walk it in `created_at` order, so that
 * index left the unfiltered plan exactly as it found it — `ALL` plus
 * `Using filesort`. This index puts `created_at` first, which is the only shape
 * that can order the whole table without sorting it.
 *
 * `tbl_service_request_status_index` is deliberately left in place; whether the
 * table needs three overlapping indexes is a separate decision from whether
 * this query needs one.
 *
 * Same caveat as the composite: at 39 rows a full scan of a table this small
 * beats any index, and MariaDB may keep choosing one. The plan is what changes
 * here, not the clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->index('created_at', 'tbl_service_request_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropIndex('tbl_service_request_created_at_index');
        });
    }
};
