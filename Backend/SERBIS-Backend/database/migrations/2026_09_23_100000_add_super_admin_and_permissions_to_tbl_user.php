<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which admin-panel sections a staff account may use.
 *
 * `is_super_admin` sees every section, is the only kind of account that can
 * open Staff Accounts, and is the only one that can change anyone's access.
 * Nobody is promoted here: the first super admin is chosen on purpose, per
 * environment, with `php artisan staff:make-super-admin <email>`.
 *
 * `permissions` is the list of section keys (App\Support\AdminSections) an
 * account may open. NULL means unrestricted and is what every existing row
 * keeps, so nothing changes for anyone on deploy. That is the same
 * not-fail-closed stance as `status` (User::isDeactivated): the recovery path
 * when an office locks itself out is still a hand-written INSERT, which names
 * its columns and leaves this one null. An empty list means no sections at
 * all, and is what an account created from the panel starts with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('must_change_password');
            $table->json('permissions')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'permissions']);
        });
    }
};
