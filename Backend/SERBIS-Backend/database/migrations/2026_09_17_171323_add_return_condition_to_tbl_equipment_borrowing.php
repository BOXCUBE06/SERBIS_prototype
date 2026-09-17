<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MDRRMO feedback, 2026-09-18: "required when bad, optional when good" needs
// a marker for good/bad in the first place — return_condition_note alone was
// free text with no structured signal to key a validation rule on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->enum('return_condition', ['Good', 'Bad'])->nullable()->after('return_condition_note');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn('return_condition');
        });
    }
};
