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
        Schema::create('tbl_recipients', function (Blueprint $table) {
            $table->id('recipient_id');
            $table->foreignId('sms_log_id')->constrained('tbl_sms_logs', 'sms_log_id');
            $table->foreignId('resident_id')->constrained('tbl_residents', 'resident_id');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_recipients');
    }
};
