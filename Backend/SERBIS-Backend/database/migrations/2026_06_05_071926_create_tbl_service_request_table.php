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
        Schema::create('tbl_service_request', function (Blueprint $table) {
            $table->id('request_id');
            $table->foreignId('resident_id')->constrained('tbl_residents', 'resident_id');
            $table->foreignId('service_id')->constrained('tbl_services', 'service_id');
            $table->foreignId('processed_by')->nullable()->constrained('tbl_user', 'admin_id');
            $table->text('description')->nullable();
            $table->string('valid_id')->nullable();
            $table->string('status');
            $table->text('remarks')->nullable();
            $table->timestamps(); // Automatically handles created_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_service_request');
    }
};
