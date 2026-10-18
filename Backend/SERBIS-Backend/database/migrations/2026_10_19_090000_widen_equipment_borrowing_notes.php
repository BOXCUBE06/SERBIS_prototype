<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intake notes are limited to 500 characters (App\Support\Text::MAX_NOTE), but
 * a borrowing's purpose and denial reason were varchar(255). Without this a
 * 300-character purpose passed validation and MySQL answered error 1406.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->string('purpose', 500)->nullable()->change();
            $table->string('denial_reason', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing would truncate (or refuse) longer notes already saved.
    }
};
