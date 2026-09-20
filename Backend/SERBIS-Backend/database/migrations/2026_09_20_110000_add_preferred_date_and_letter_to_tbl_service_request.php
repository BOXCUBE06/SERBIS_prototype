<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things the scheduled MDRRMO programs (trainings and seminars,
 * simulation drills) need that a plain description cannot carry.
 *
 * `preferred_date` is a real column, not a line of prose, because the office
 * requires it to be at least 14 days out and that has to be enforced by the
 * server, not just by the mobile date picker. A DATE, not a DATETIME: the
 * resident names a day, and the office agrees the hour afterwards.
 *
 * `letter` is the request letter the office asks for. Same rule as `valid_id`
 * and `site_photo`: it holds a storage path on the private disk, is hidden
 * from every response, and clients get a `has_letter` boolean and fetch the
 * file from a controller that checks ownership first.
 *
 * Both are nullable and NULL for every service that is not one of these, so
 * no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->date('preferred_date')->nullable()->after('landmark');
            $table->string('letter')->nullable()->after('site_photo');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn(['preferred_date', 'letter']);
        });
    }
};
