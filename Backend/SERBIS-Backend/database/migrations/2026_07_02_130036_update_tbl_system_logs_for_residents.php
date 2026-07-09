<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_system_logs', function (Blueprint $table) {
            // 1. Drop the existing foreign key constraint so we can modify the column
            $table->dropForeign('tbl_system_logs_admin_id_foreign');

            // 2. Make admin_id nullable
            $table->unsignedBigInteger('admin_id')->nullable()->change();

            // 3. Add the resident_id column and foreign key constraint
            $table->foreignId('resident_id')
                  ->nullable()
                  ->after('admin_id')
                  ->constrained('tbl_residents', 'resident_id');

            // 4. Re-apply the admin_id foreign key constraint
            $table->foreign('admin_id')->references('admin_id')->on('tbl_user');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_system_logs', function (Blueprint $table) {
            $table->dropForeign(['resident_id']);
            $table->dropColumn('resident_id');

            $table->dropForeign(['admin_id']);
            $table->unsignedBigInteger('admin_id')->nullable(false)->change();
            $table->foreign('admin_id')->references('admin_id')->on('tbl_user');
        });
    }
};