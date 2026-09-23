<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MDRRMO responders (EMS crew, rescue personnel) who can be assigned to a
 * request. No soft deletes — same convention as Vehicle: a responder who has
 * left is set 'off_duty', never removed, so past assignments still resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_responders', function (Blueprint $table) {
            $table->id('responder_id');
            $table->string('name');
            $table->string('contact_no');
            $table->string('position');
            // Public disk (InfoMaterial pattern) — not sensitive like a
            // government ID scan, and the resident-facing API returns this
            // directly as a URL. Written only by uploadPhoto(); excluded
            // from $fillable on the model.
            $table->string('photo_path')->nullable();
            $table->enum('status', ['available', 'deployed', 'off_duty'])->default('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_responders');
    }
};
