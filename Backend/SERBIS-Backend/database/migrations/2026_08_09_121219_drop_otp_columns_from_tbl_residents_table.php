<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No OTP flow was ever built: nothing generated a code, sent one, or checked
     * one. The columns have been dead since the table was created.
     */
    public function up(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropColumn(['otp', 'otp_verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores both columns as the creating migration declared them. The
     * `otp_verified_at` values do not come back — they were written by
     * ResidentSeeder and recorded a verification that never happened.
     */
    public function down(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->string('otp')->nullable();
            $table->timestamp('otp_verified_at')->nullable();
        });
    }
};
