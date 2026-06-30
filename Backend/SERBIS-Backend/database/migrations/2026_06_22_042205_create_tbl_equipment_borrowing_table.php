<?php

// xxxx_xx_xx_create_tbl_equipment_borrowing_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->id('borrow_id');
            $table->foreignId('resident_id')->references('resident_id')->on('tbl_residents')->cascadeOnDelete();
            $table->foreignId('equipment_id')->references('equipment_id')->on('tbl_equipments')->cascadeOnDelete();
            $table->integer('quantity');
            $table->enum('status', ['Pending', 'Approved', 'Released', 'Returned', 'Denied'])->default('Pending');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tbl_equipment_borrowing');
    }
};