<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The unique index goes first and in its own statement. MySQL drops it
        // implicitly with the column, but SQLite refuses — "error in index
        // tbl_vehicles_plate_number_unique after drop column" — which made the
        // whole migration set unrunnable on the sqlite database the test suite
        // uses, and so made feature tests impossible to write.
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropUnique('tbl_vehicles_plate_number_unique');
        });

        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropColumn('plate_number');
            $table->string('unit_identifier')->unique()->after('vehicle_id');
            $table->string('specification')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropUnique('tbl_vehicles_unit_identifier_unique');
        });

        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropColumn('unit_identifier');
            $table->dropColumn('specification');
            $table->string('plate_number')->unique()->after('vehicle_id');
        });
    }
};