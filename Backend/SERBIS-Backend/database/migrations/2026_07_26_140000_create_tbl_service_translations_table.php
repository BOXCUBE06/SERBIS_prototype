<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per service per language. A column per locale was the alternative
     * and it does not survive contact with a third language: Yogad is already
     * planned, and adding it would mean another migration, another column in
     * every query, and a nullable field nobody remembers to populate.
     */
    public function up(): void
    {
        Schema::create('tbl_service_translations', function (Blueprint $table) {
            $table->id('service_translation_id');

            $table->foreignId('service_id')
                ->constrained('tbl_services', 'service_id')
                ->cascadeOnDelete();

            // BCP 47 subtags: 'en', 'fil', later 'yog'. Short on purpose so a
            // regional variant like 'fil-PH' still fits.
            $table->string('locale', 10);
            $table->string('name');

            $table->timestamps();

            // The lookup the API does on every request, and the guard that stops
            // one service accumulating two names for the same language.
            $table->unique(['service_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_service_translations');
    }
};
