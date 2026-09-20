<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MDRRMO feedback, 2026-09-18: the "verified" boolean said MDRRMO checked a
// material, not who — any admin could flip it, indistinguishably from an
// actual expert reviewing it. These three name that fact instead of just
// flagging it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_info_materials', function (Blueprint $table) {
            $table->string('verified_by_name')->nullable()->after('verified');
            $table->string('verified_by_role')->nullable()->after('verified_by_name');
            $table->dateTime('verified_at')->nullable()->after('verified_by_role');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_info_materials', function (Blueprint $table) {
            $table->dropColumn(['verified_by_name', 'verified_by_role', 'verified_at']);
        });
    }
};
