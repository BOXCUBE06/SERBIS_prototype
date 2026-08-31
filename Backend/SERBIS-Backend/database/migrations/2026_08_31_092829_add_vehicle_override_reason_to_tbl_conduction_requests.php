<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// C7 of docs/dispatch-audit.md's remediation plan: filing a trip record for a
// unit already on an open trip is now a soft block, not silent. Overriding
// it requires a reason, and this is where that reason lives -- TracksHistory
// already logs every column on create to tbl_system_logs, so writing it here
// puts it in the audit trail for free, with no second table to keep in sync.
// Null on every row that was never in conflict.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->string('vehicle_override_reason', 500)->nullable()->after('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->dropColumn('vehicle_override_reason');
        });
    }
};
