<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the return-due reminder SMS was last attempted for this borrowing,
     * successful or not. Null means never attempted. Set once per borrowing so
     * a failed send is not retried on the next day's run — see
     * app/Console/Commands/SendReturnDueReminders.php.
     */
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dateTime('return_reminder_sent_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn('return_reminder_sent_at');
        });
    }
};
