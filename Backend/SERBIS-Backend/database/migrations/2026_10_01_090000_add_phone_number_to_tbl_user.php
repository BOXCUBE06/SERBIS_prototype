<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a staff member's sign-in code is texted when ADMIN_MFA_ENABLED is
     * on. Nullable: every existing account starts without one, and a super
     * admin fills them in (Staff Accounts or `staff:set-phone`) before the
     * flag is turned on. Stored as +639XXXXXXXXX, like tbl_residents. Not
     * unique — two staff may share an office phone.
     */
    public function up(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->string('phone_number', 20)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->dropColumn('phone_number');
        });
    }
};
