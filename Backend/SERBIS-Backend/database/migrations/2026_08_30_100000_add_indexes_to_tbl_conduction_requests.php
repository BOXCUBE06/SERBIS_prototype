<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns `tbl_conduction_requests` is actually queried on, neither of
 * which is indexed.
 *
 * `service_request_id` and `vehicle_id` already have indexes — MySQL creates
 * one implicitly for every foreign key, and both got theirs in
 * 2026_08_29_140000. These two never did, because nothing references them:
 *
 *  - `created_at` — `ConductionRequestController::index()` is a bare
 *    `latest()` over the whole table with no `where`, which is the same shape
 *    that earned `tbl_service_request` its own `created_at` index: with no
 *    predicate to anchor a composite, the only index that can order the table
 *    without a filesort is one led by `created_at`.
 *  - `departed_office_at` — `AnalyticsController::index()` runs
 *    `whereNull('departed_office_at')->count()` on every dashboard load, which
 *    is every time a staffer opens the panel. A plain index serves an IS NULL
 *    predicate; without one this is a full scan on the busiest page.
 *
 * Same caveat the 2026-08-22 index migrations recorded, and it still holds:
 * on a table this small a full scan beats any index and MariaDB may keep
 * choosing one. What changes here is the plan the optimiser *can* pick as the
 * table grows, not the clock today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->index('created_at', 'tbl_conduction_requests_created_at_index');
            $table->index('departed_office_at', 'tbl_conduction_requests_departed_office_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->dropIndex('tbl_conduction_requests_departed_office_at_index');
            $table->dropIndex('tbl_conduction_requests_created_at_index');
        });
    }
};
