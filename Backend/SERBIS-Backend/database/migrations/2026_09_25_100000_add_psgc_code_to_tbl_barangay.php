<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 10-digit PSGC code (e.g. 0203112001) is the one stable identity a
     * barangay has: names differ between sources ("Cabugao (Pob.)"), and the map
     * boundaries are keyed on the code. Nullable, so the rows that already exist
     * keep their ids and resident links until EchagueBarangaySeeder stamps them.
     */
    public function up(): void
    {
        Schema::table('tbl_barangay', function (Blueprint $table) {
            $table->string('psgc_code', 10)->nullable()->unique()->after('barangay_name');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_barangay', function (Blueprint $table) {
            $table->dropUnique(['psgc_code']);
            $table->dropColumn('psgc_code');
        });
    }
};
