<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real fleet unit's own government-issued plate, distinct from
 * unit_identifier (the internal fleet code, "AMB-01") that
 * 2026_06_23_130239_update_tbl_vehicles_table.php deliberately kept separate
 * from the old plate_number column it dropped that day. C7 of
 * docs/dispatch-audit.md's remediation plan flagged this as a known,
 * deferred gap: the Ambulance Trip Record form's own plate_no field has
 * stayed free-text ever since, because nothing on tbl_vehicles existed to
 * source it from.
 *
 * Nullable and not unique: older or mutual-aid fleet entries may carry no
 * plate on file, and a soft duplicate (a data-entry mistake, a
 * decommissioned unit's plate reassigned) is a person's problem to catch,
 * not a constraint that blocks saving a vehicle record over it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->string('plate_no', 32)->nullable()->after('unit_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropColumn('plate_no');
        });
    }
};
