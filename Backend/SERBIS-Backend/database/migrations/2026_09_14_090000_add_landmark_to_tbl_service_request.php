<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plain text companion to `site_photo`: a resident often can describe a
 * nearby landmark ("beside the chapel") faster than they can stop to
 * photograph one. Optional, same as the photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->string('landmark', 255)->nullable()->after('site_photo');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn('landmark');
        });
    }
};
