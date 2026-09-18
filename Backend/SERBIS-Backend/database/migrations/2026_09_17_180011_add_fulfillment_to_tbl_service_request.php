<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MDRRMO feedback, 2026-09-18: pickup/delivery beyond equipment borrowing,
// starting with relief goods — ambulance and conduction don't map onto
// this concept at all, so this stays on tbl_service_request rather than
// spreading it onto every table. Mirrors tbl_equipment_borrowing's own
// fulfillment_method / delivery_address pair exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->enum('fulfillment_method', ['Pickup', 'Delivery'])->nullable()->after('landmark');
            $table->string('delivery_address')->nullable()->after('fulfillment_method');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_method', 'delivery_address']);
        });
    }
};
