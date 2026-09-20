<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SkySMS answers a bulk send with 201 and bills the credits at that moment; it
 * says nothing about delivery, and the first real blast sat in "pending" with
 * no failure state. A blast is now recorded as Queued and only a read of the
 * vendor's message list may move it to Sent.
 *
 * Every existing 'Sent' row was written on the strength of that same
 * acceptance, so it is rewritten to 'Queued': accurate, and no claim about
 * delivery is lost because none was ever verified. Those rows have no queue ids,
 * so the panel shows them as "can't check".
 *
 * delivery_checked_at is when someone last asked SkySMS about the blast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->timestamp('delivery_checked_at')->nullable()->after('status');
        });

        DB::table('tbl_sms_logs')->where('status', 'Sent')->update(['status' => 'Queued']);
        DB::table('tbl_recipients')->where('status', 'Sent')->update(['status' => 'Queued']);
    }

    /**
     * Statuses are not restored: after a rewrite there is no telling which
     * 'Queued' rows began as 'Sent'.
     */
    public function down(): void
    {
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->dropColumn('delivery_checked_at');
        });
    }
};
