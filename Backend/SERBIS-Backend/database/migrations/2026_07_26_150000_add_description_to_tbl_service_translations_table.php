<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable: a locale may have a translated name and no translated blurb, and
     * that should fall back to the English description rather than block the
     * name from being localised.
     */
    public function up(): void
    {
        Schema::table('tbl_service_translations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_translations', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
