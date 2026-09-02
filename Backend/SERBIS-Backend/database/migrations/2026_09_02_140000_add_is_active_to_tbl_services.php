<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Services are fixed and their intake forms are hardcoded against
// tbl_services.code (mobile's formKindForServiceCode, this controller's
// AMBULANCE_SERVICE_CODE) — deleting a row breaks that. Disabling is the
// safe equivalent: existing requests keep their service_id, new ones are
// refused server-side, and the row stays in place for anything still
// keyed on its code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_services', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_services', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
