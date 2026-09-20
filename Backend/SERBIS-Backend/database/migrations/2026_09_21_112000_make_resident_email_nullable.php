<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A resident signs up and signs in with a phone number now, so an email is no
 * longer collected. The column stays, with its data and its unique index
 * (MySQL allows any number of NULLs under a unique index): dropping it would
 * lose the addresses residents already gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->string('email_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows without an address cannot go back to NOT NULL; give them a
        // placeholder that cannot collide, then restore the constraint.
        DB::table('tbl_residents')->whereNull('email_address')->update([
            'email_address' => DB::raw("CONCAT('no-email-', resident_id, '@invalid.local')"),
        ]);

        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->string('email_address')->nullable(false)->change();
        });
    }
};
