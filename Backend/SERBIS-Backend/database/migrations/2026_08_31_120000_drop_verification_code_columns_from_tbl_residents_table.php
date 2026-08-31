<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the three columns that backed the signup code.
 *
 * They were added by 2026_08_11_121500 alongside `email_verified_at`, which
 * stays. What changed is where an unverified signup lives: it used to be a row
 * in this table carrying a code hash and two clocks, which meant an abandoned
 * or hostile attempt permanently held an email address and a phone number that
 * only an admin deleting the row by hand could release. A pending signup is now
 * a cache entry keyed by a hash of the address — see AuthController::register()
 * — and lapses on its own, so nothing reaches tbl_residents until the code has
 * come back and the account is real.
 *
 * Rows written before that change and still unverified keep working: resend and
 * login rebuild a pending entry around them (AuthController::adoptUnverifiedResident),
 * and their outstanding code — which these columns held — is simply reissued.
 * Nothing here needs to preserve a code that was about to expire anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropColumn([
                'verification_code',
                'verification_code_expires_at',
                'verification_code_sent_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->string('verification_code')->nullable()->after('email_verified_at');
            $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');
            $table->timestamp('verification_code_sent_at')->nullable()->after('verification_code_expires_at');
        });
    }
};
