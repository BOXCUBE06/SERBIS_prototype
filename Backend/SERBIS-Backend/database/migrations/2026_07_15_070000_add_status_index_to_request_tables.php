<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
