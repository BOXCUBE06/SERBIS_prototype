<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second, optional upload on a service request: the photo of the site itself.
 * The road-clearing form used to show an "Attach photo" box that went nowhere,
 * and mobile M24 deleted it rather than ship a control that discarded what it
 * collected. This is the column that lets it come back honestly.
 *
 * Separate from `valid_id` on purpose. That is a government ID — identity
 * evidence, served only through an owner-scoped endpoint. This is a picture of
 * a blocked road, useful to whoever is dispatched. They are stored the same way
 * because both belong to one resident's request, but conflating the two columns
 * would mean an upload of one could overwrite the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->string('site_photo')->nullable()->after('valid_id');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn('site_photo');
        });
    }
};
