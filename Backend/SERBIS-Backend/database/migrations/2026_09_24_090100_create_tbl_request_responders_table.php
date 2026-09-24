<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which responders are assigned to which request. Many-to-many: a responder
 * can be on more than one request over time (never two active ones at once —
 * enforced in ServiceRequestController, not here), and a request can carry
 * more than one responder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_request_responders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('tbl_service_request', 'request_id')->cascadeOnDelete();
            $table->foreignId('responder_id')->constrained('tbl_responders', 'responder_id')->cascadeOnDelete();
            $table->timestamp('assigned_at');
            $table->unique(['request_id', 'responder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_request_responders');
    }
};
