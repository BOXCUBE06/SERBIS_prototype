<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds DRRM Trainings and Seminars (IEC) to a catalogue that already exists.
 *
 * Skipped on an empty table on purpose: a fresh database is filled by
 * ServiceSeeder, which refuses to run once tbl_services has any row, so a
 * migration that inserted here would make the seeder drop the original seven.
 * The seeder lists this service too.
 */
return new class extends Migration
{
    private const CODE = 'drrm-trainings-and-seminars';

    public function up(): void
    {
        if (DB::table('tbl_services')->doesntExist() || Service::where('code', self::CODE)->exists()) {
            return;
        }

        Service::create([
            'service_name' => 'DRRM Trainings and Seminars',
            'description' => 'Disaster risk reduction and management trainings and seminars (IEC) for barangays and organizations.',
        ]);
    }

    public function down(): void
    {
        DB::table('tbl_services')->where('code', self::CODE)->delete();
    }
};
