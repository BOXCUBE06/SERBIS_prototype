<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MDRRMO feedback, 2026-09-18: "notify the resident when declined-for-
// unavailable equipment becomes available again" needs to know WHY a request
// was declined in the first place — denial_reason was always free text an
// admin typed, with nothing machine-readable to key a later notification on.
//
// availability_reconfirm_sent_at is the companion marker: set once this
// borrowing has actually been re-notified, the same "sent means do not ask
// again" shape return_reminder_sent_at already uses.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->enum('denial_reason_code', ['Unavailable', 'Other'])->nullable()->after('denial_reason');
            $table->dateTime('availability_reconfirm_sent_at')->nullable()->after('denial_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn(['denial_reason_code', 'availability_reconfirm_sent_at']);
        });
    }
};
