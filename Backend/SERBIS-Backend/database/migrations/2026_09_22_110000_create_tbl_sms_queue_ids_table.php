<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ids SkySMS returns for a blast, one row each. The bulk reply is
 * {"success":true,"total":2,"queue_ids":[233895,233896],...}: one id per queued
 * message and no batch id, which tbl_sms_logs.api_job_id (one string) could not
 * hold. That column stays for replies that do carry a single batch or job id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_sms_queue_ids', function (Blueprint $table) {
            $table->id('sms_queue_id');
            $table->foreignId('sms_log_id')->constrained('tbl_sms_logs', 'sms_log_id')->cascadeOnDelete();
            $table->string('queue_id', 50)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_sms_queue_ids');
    }
};
