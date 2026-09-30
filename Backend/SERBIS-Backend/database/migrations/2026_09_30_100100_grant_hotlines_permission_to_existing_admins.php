<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for the new 'hotlines' section (App\Support\AdminSections).
 *
 * NULL permissions means unrestricted (User::allowedSections() — a NULL row
 * already sees every assignable section, 'hotlines' included, the moment
 * it's added to AdminSections::ASSIGNABLE). Only a row with an explicit,
 * non-null permissions array needs this section appended, or an admin who
 * was individually granted a specific list would not see the new page.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tbl_user')->whereNotNull('permissions')->orderBy('admin_id')
            ->each(function ($admin) {
                $permissions = json_decode($admin->permissions, true) ?? [];

                if (! in_array('hotlines', $permissions, true)) {
                    $permissions[] = 'hotlines';

                    DB::table('tbl_user')->where('admin_id', $admin->admin_id)
                        ->update(['permissions' => json_encode($permissions)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('tbl_user')->whereNotNull('permissions')->orderBy('admin_id')
            ->each(function ($admin) {
                $permissions = array_values(array_diff(json_decode($admin->permissions, true) ?? [], ['hotlines']));

                DB::table('tbl_user')->where('admin_id', $admin->admin_id)
                    ->update(['permissions' => json_encode($permissions)]);
            });
    }
};
