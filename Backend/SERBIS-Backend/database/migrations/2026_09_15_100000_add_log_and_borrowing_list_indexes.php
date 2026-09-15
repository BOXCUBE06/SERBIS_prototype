<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema audit #1, #20, #21, #22.
 *
 * - tbl_system_logs, tbl_sms_logs: both log pages order by created_at, then
 *   the primary key. Neither column was indexed, so every page sorted the whole
 *   table. InnoDB appends the primary key to a secondary index, so a plain
 *   created_at index already carries the tiebreaker.
 * - tbl_equipment_borrowing: a resident's own list filters on resident_id and
 *   orders by created_at; the foreign-key index alone left a sort after it.
 *   MySQL silently drops that implicit foreign-key index once this composite
 *   can back the key, which is why down() recreates it first.
 * - tbl_service_request_status_index is the leftmost prefix of
 *   (status, created_at), so it only cost writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_system_logs', function (Blueprint $table) {
            $table->index('created_at', 'tbl_system_logs_created_at_index');
        });

        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->index('created_at', 'tbl_sms_logs_created_at_index');
        });

        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->index(['resident_id', 'created_at'], 'tbl_equipment_borrowing_resident_id_created_at_index');
        });

        // Fresh, MySQL has already dropped the implicit index. After a rollback
        // it is a real index down() created, and would stay as a duplicate.
        if (Schema::hasIndex('tbl_equipment_borrowing', 'tbl_equipment_borrowing_resident_id_foreign')) {
            Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
                $table->dropIndex('tbl_equipment_borrowing_resident_id_foreign');
            });
        }

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropIndex('tbl_service_request_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->index('status', 'tbl_service_request_status_index');
        });

        // Without a resident_id index back first, the drop fails with 1553
        // ("needed in a foreign key constraint").
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->index('resident_id', 'tbl_equipment_borrowing_resident_id_foreign');
            $table->dropIndex('tbl_equipment_borrowing_resident_id_created_at_index');
        });

        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->dropIndex('tbl_sms_logs_created_at_index');
        });

        Schema::table('tbl_system_logs', function (Blueprint $table) {
            $table->dropIndex('tbl_system_logs_created_at_index');
        });
    }
};
