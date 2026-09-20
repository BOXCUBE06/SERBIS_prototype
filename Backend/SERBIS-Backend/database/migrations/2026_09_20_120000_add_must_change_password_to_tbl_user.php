<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set when another admin (or the staff:reset-password command) hands a staff
 * member a temporary password. The account can sign in with it but reaches
 * nothing except changing it — see IsAdmin.
 *
 * Existing rows default to false: nobody who can sign in today is forced to
 * change anything by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
