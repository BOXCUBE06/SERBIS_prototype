<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional Filipino name, set in Manage Services. The app translates the
 * services it ships with off `code` (translations.dart); a service staff add in
 * the panel has no entry there, so this is where its Filipino name lives.
 * Null means no Filipino name: the app falls back to its bundled translation,
 * then to service_name. Builds released before this column ignore it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_services', function (Blueprint $table) {
            $table->string('service_name_fil', 255)->nullable()->after('service_name');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_services', function (Blueprint $table) {
            $table->dropColumn('service_name_fil');
        });
    }
};
