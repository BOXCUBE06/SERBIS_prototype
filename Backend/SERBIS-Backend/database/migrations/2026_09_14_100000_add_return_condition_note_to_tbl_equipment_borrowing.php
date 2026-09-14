<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional text recorded alongside the return photo — what staff noticed
 * about the item's condition when it came back, typed rather than left to
 * live only in the photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->string('return_condition_note', 500)->nullable()->after('return_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn('return_condition_note');
        });
    }
};
