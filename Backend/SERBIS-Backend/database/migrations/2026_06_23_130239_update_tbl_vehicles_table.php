<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropColumn('plate_number');
            $table->string('unit_identifier')->unique()->after('vehicle_id');
            $table->string('specification')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_vehicles', function (Blueprint $table) {
            $table->dropColumn('unit_identifier');
            $table->dropColumn('specification');
            $table->string('plate_number')->unique()->after('vehicle_id');
        });
    }
};