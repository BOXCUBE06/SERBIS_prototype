<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses 2026_09_01_090000_add_plate_no_to_tbl_vehicles.php, one day old.
 * That column existed to auto-fill the Ambulance Trip Record form's own
 * plate_no from the fleet record; the conduction request form no longer
 * carries Vehicle or Plate No at all, so nothing is left to fill.
 *
 * tbl_conduction_requests.plate_no is a different column on a different
 * table and is deliberately untouched — it holds the trip's own snapshot,
 * still typed by hand on the trip form, and outlives this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropColumn('plate_no');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->string('plate_no', 32)->nullable()->after('unit_identifier');
        });
    }
};
