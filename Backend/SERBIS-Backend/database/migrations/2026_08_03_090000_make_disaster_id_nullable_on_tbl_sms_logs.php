<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tbl_sms_logs` has never held a row: SmsController::sendBlast() called the
 * vendor and returned, writing nothing. Recording the blast is what gives
 * residents an advisory feed and gives the agency a record of what was sent to
 * whom — but the table demanded a `disaster_id`, and `tbl_disaster` is empty
 * and is not something the blast form asks for.
 *
 * Rather than invent a disaster taxonomy to satisfy a foreign key, the column
 * becomes optional. A blast is worth recording whether or not anyone has
 * classified the emergency it belongs to, and the classification can be
 * attached later without touching the rows written in between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->foreignId('disaster_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows written without a disaster cannot satisfy a NOT NULL constraint,
        // so they go first. They are a log, not state anything depends on.
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->foreignId('disaster_id')->nullable(false)->change();
        });
    }
};
