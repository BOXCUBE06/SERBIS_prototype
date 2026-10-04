<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const OLD = 'Emergency medical response and ambulance services.';

    public const NEW = 'Ambulance transport for non-life-threatening medical needs.';

    /**
     * SERBIS is not an emergency service, and the old seeded text said it was.
     * Only a description still equal to the seeded text is replaced, so staff
     * edits made in the admin panel are left alone.
     */
    public function up(): void
    {
        DB::table('tbl_services')
            ->where('code', 'ambulance-medical-response')
            ->where('description', self::OLD)
            ->update(['description' => self::NEW, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('tbl_services')
            ->where('code', 'ambulance-medical-response')
            ->where('description', self::NEW)
            ->update(['description' => self::OLD, 'updated_at' => now()]);
    }
};
