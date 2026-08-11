<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email verification for resident self-registration.
 *
 * This is NOT a revert of 2026_08_09_121219_drop_otp_columns. That pair of
 * columns backed an SMS flow that was never built, and one of them held 20
 * seeded timestamps recording a verification nobody performed. These columns
 * back a flow that exists, and the code itself is stored hashed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email_address');

            // Hashed, never the code itself. It is a short-lived credential that
            // grants an account, and tbl_residents is read by the admin panel.
            $table->string('verification_code')->nullable()->after('email_verified_at');
            $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');

            // Drives the resend cooldown. Separate from the expiry because a
            // resident may resend long before the outstanding code lapses.
            $table->timestamp('verification_code_sent_at')->nullable()->after('verification_code_expires_at');
        });

        // Existing rows are dummy data — the 20 from ResidentSeeder plus the
        // handful registered while testing. Backfilling them as verified keeps
        // every demo login working. This is a convenience for seeded rows, not
        // a record that anyone verified anything; no real resident exists yet.
        DB::table('tbl_residents')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropColumn([
                'email_verified_at',
                'verification_code',
                'verification_code_expires_at',
                'verification_code_sent_at',
            ]);
        });
    }
};
