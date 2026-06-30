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
        Schema::create('tbl_sms_logs', function (Blueprint $table) {
            $table->id('sms_log_id');
            $table->foreignId('sender_id')->constrained('tbl_user', 'admin_id');
            $table->foreignId('target_area_id')->constrained('tbl_barangay', 'barangay_id');
            $table->foreignId('disaster_id')->constrained('tbl_disaster', 'disaster_id');
            $table->string('api_job_id')->nullable();
            $table->text('message_body');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_sms_logs');
    }
};
