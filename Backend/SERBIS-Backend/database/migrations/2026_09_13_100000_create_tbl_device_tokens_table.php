<?php

// xxxx_xx_xx_create_tbl_device_tokens_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('tbl_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->references('resident_id')->on('tbl_residents')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tbl_device_tokens');
    }
};
