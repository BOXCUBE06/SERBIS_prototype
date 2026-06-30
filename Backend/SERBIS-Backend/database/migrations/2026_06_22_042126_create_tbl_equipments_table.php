<?php

// xxxx_xx_xx_create_tbl_equipments_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('tbl_equipments', function (Blueprint $table) {
            $table->id('equipment_id');
            $table->string('item_name')->unique();
            $table->integer('total_quantity');
            $table->integer('available_quantity');
            $table->enum('status', ['Available', 'Unavailable'])->default('Available');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tbl_equipments');
    }
};
