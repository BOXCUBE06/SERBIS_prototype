<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether MDRRMO has checked this material and stands behind it.
     *
     * Defaults to false, including for every row already in the table: a
     * material uploaded before anyone could verify it has not been verified,
     * and defaulting the backlog to true would be the panel asserting a review
     * that never happened.
     */
    public function up(): void
    {
        Schema::table('tbl_info_materials', function (Blueprint $table) {
            $table->boolean('verified')->default(false)->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_info_materials', function (Blueprint $table) {
            $table->dropColumn('verified');
        });
    }
};
