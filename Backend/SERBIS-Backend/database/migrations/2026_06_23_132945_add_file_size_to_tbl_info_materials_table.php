<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_info_materials', function (Blueprint $table) {
            // Adding the file_size column after file_type
            $table->integer('file_size')->after('file_type')->comment('Size in bytes');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_info_materials', function (Blueprint $table) {
            $table->dropColumn('file_size');
        });
    }
};