<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lists' filters and the 15-second pulse.
 *
 * - tbl_service_request (barangay_id, created_at): the barangay filter plus a
 *   date range. The foreign key's own single-column index is a prefix of it.
 * - updated_at on the four tables the pulse reads: MAX(updated_at) was a scan.
 * - tbl_equipment_borrowing.due_date: the overdue count and the due-back job.
 * - tbl_residents status and (last_name, first_name): the Users filter, the
 *   pending-accounts count, and the directory's name order.
 *
 * An index that already exists on the same columns is left alone, so this is
 * safe on a database that was given one by hand.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private const INDEXES = [
        'tbl_service_request' => [
            'tbl_service_request_barangay_id_created_at_index' => ['barangay_id', 'created_at'],
            'tbl_service_request_updated_at_index' => ['updated_at'],
        ],
        'tbl_equipment_borrowing' => [
            'tbl_equipment_borrowing_due_date_index' => ['due_date'],
        ],
        'tbl_residents' => [
            'tbl_residents_status_index' => ['status'],
            'tbl_residents_last_name_first_name_index' => ['last_name', 'first_name'],
            'tbl_residents_updated_at_index' => ['updated_at'],
        ],
        'tbl_conduction_requests' => [
            'tbl_conduction_requests_updated_at_index' => ['updated_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        // The barangay foreign key needs an index of its own once the composite
        // that covered it goes (the same trap 2026_09_15_100000 documents).
        if (Schema::hasIndex('tbl_service_request', 'tbl_service_request_barangay_id_created_at_index')
            && ! Schema::hasIndex('tbl_service_request', 'tbl_service_request_barangay_id_foreign')) {
            Schema::table('tbl_service_request', fn (Blueprint $blueprint) => $blueprint->index('barangay_id', 'tbl_service_request_barangay_id_foreign'));
        }

        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }
};
