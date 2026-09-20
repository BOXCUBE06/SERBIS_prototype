<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a sign-in now depends on is the phone, so "verified" becomes a fact
 * about the phone: it was proved at sign-up, or an admin vouched for it by
 * creating the account.
 *
 * Every existing row is backfilled as verified. Accounts that finished sign-up
 * already proved their phone (the code went there); accounts an admin made
 * (barangay, organization, walk-in) count as verified by decision, 2026-09-20.
 * email_verified_at is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });

        DB::table('tbl_residents')->update([
            'phone_verified_at' => DB::raw('COALESCE(email_verified_at, created_at, NOW())'),
        ]);
    }

    public function down(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
