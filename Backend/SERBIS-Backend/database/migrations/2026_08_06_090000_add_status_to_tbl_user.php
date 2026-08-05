<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing a departing employee's account cannot be a row deletion.
 *
 * `tbl_system_logs.admin_id` is a foreign key to this table, so deleting an
 * admin who has ever done anything fails outright — and forcing it through
 * would erase the name attached to every action they took. Deactivation keeps
 * the audit trail readable and still ends their access.
 *
 * Existing rows default to Active: everyone who could sign in before this
 * migration must still be able to after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->string('status')->default('Active')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
