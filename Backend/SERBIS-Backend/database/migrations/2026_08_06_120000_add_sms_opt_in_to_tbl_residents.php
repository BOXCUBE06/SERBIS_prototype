<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this resident wants the MDRRMO's text blasts.
 *
 * Defaults to true, and existing rows are backfilled to true by that default:
 * everybody who was receiving advisories before this migration keeps receiving
 * them after it. Opting out has to be a thing the resident did, never a thing
 * a schema change did to them — the alternative is a flood warning that quietly
 * stops reaching a barangay because a column was added.
 *
 * Not the same switch as `status`. `status` is the MDRRMO deciding an account
 * is real; this is the resident deciding they want the messages. A blast needs
 * both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->boolean('sms_opt_in')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropColumn('sms_opt_in');
        });
    }
};
