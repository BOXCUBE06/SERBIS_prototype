<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-backed translations are gone. Filipino now lives in the Flutter
 * app's own translations.dart, keyed on `tbl_services.code`, so the twenty rows
 * here duplicate strings the client already ships and can no longer influence
 * what a resident sees.
 *
 * The move is already complete on the client: ServiceCatalogItem stopped
 * reading `name_localized` and `description_localized` before this migration
 * was written, so nothing goes untranslated when the columns behind them
 * disappear.
 *
 * A third language is now an app release rather than a migration plus a seeder
 * — which is the trade this makes, and the reason the per-locale-row design
 * that this table was chosen for no longer earns its keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tbl_service_translations');
    }

    /**
     * Recreated at its final shape — the original create migration plus the
     * description column that followed it — so a rollback lands on the schema
     * that existed the moment before this ran.
     *
     * The rows are not restored. They were seed data, and the seeder that wrote
     * them went with this migration; the English strings are still in
     * `tbl_services`, and the Filipino ones are in the app.
     */
    public function down(): void
    {
        Schema::create('tbl_service_translations', function (Blueprint $table) {
            $table->id('service_translation_id');

            $table->foreignId('service_id')
                ->constrained('tbl_services', 'service_id')
                ->cascadeOnDelete();

            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['service_id', 'locale']);
        });
    }
};
