<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A booking maps to at most one trip. App-level guards for this already
 * exist (ConductionRequestController::store()'s 409 on a service_request_id
 * that already has a row), but that is the application, not the schema —
 * this is the same rule enforced at the one layer nothing can route around.
 *
 * Nullable stays nullable: the FK migration
 * (2026_08_29_140000_add_booking_and_vehicle_refs_to_tbl_conduction_requests)
 * left service_request_id nullable for walk-in trips with no booking behind
 * them, and that is still the common case. A unique index over a nullable
 * column treats every NULL as distinct on both engines this app runs
 * against — MySQL 8 (prod, Aiven) and MariaDB 10.4 (this box's XAMPP) — so
 * any number of walk-in rows still coexist under one.
 *
 * Guarded the same way 2026_08_23_110000_add_code_to_tbl_services_table.php
 * guards its own unique index: check first, throw with the offending ids if
 * the check fails, so a dirty table aborts the migration cleanly instead of
 * dying on a raw MySQL 1062 mid-deploy on a host (Render) that auto-deploys
 * from a merge to main. Confirmed clean against prod on 2026-08-31 via
 * `php artisan serbis:report-duplicate-conduction-requests`, and the one
 * pair that existed (service_request_id 3, trips 3 and 4 — this bug's own
 * reproduction) was resolved by hand before this migration was written. The
 * check below runs again anyway rather than trusting that a point-in-time
 * finding still holds by the time this actually deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicates();

        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->unique('service_request_id', 'tbl_conduction_requests_service_request_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->dropUnique('tbl_conduction_requests_service_request_id_unique');
        });
    }

    private function assertNoDuplicates(): void
    {
        $duplicates = DB::table('tbl_conduction_requests')
            ->whereNotNull('service_request_id')
            ->select('service_request_id', DB::raw('COUNT(*) as trip_count'))
            ->groupBy('service_request_id')
            ->having('trip_count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $described = $duplicates->map(function ($row) {
            $tripIds = DB::table('tbl_conduction_requests')
                ->where('service_request_id', $row->service_request_id)
                ->orderBy('conduction_request_id')
                ->pluck('conduction_request_id')
                ->implode(', ');

            return "service_request_id {$row->service_request_id} (trips: {$tripIds})";
        })->implode('; ');

        throw new RuntimeException(
            'Cannot add the unique index on tbl_conduction_requests.service_request_id: '
            . 'these bookings have more than one trip filed against them. Resolve each '
            . '(keep one, decide what happens to the rest) with '
            . '`php artisan serbis:report-duplicate-conduction-requests` for detail, then '
            . 'migrate again. ' . $described
        );
    }
};
