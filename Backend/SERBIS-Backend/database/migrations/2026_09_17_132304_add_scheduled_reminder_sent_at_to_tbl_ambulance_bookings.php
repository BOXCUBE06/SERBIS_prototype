<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the scheduled-booking reminder was last accepted for this
     * ambulance booking. Null means never sent (either not yet attempted, or
     * a send that was rejected/threw — same non-retry-on-success-only shape
     * as tbl_equipment_borrowing.return_reminder_sent_at). Set once so a
     * confirmed booking is not reminded twice for the same appointment — see
     * app/Console/Commands/SendReturnDueReminders.php.
     */
    public function up(): void
    {
        Schema::table('tbl_ambulance_bookings', function (Blueprint $table) {
            $table->dateTime('scheduled_reminder_sent_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_ambulance_bookings', function (Blueprint $table) {
            $table->dropColumn('scheduled_reminder_sent_at');
        });
    }
};
