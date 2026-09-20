<?php

// xxxx_xx_xx_create_tbl_sms_blast_code_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // A singleton: exactly one row, holding the one shared code every
        // admin enters to send a text blast. Hashed like a password — never
        // stored or returned in the clear. updated_by/updated_at answer "who
        // set this and when" without a second history table.
        Schema::create('tbl_sms_blast_code', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash');
            $table->foreignId('updated_by')->nullable()
                ->references('admin_id')->on('tbl_user')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tbl_sms_blast_code');
    }
};
