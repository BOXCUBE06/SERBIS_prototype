<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_vehicles', function (Blueprint $table) {
            $table->id('vehicle_id');
            $table->string('type'); // e.g., Ambulance, Rescue Truck, Firetruck
            $table->string('plate_number')->unique();
            $table->string('status')->default('Available'); // Available, Dispatched, Maintenance
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_vehicles');
    }
};