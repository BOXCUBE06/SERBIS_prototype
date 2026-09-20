<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds MDRRMO Certification to a catalogue that already exists. Skipped on an
 * empty table for the reason given in the DRRM Trainings migration: a fresh
 * database is filled by ServiceSeeder, which lists this service too.
 */
return new class extends Migration
{
    private const CODE = 'mdrrmo-certification';

    public function up(): void
    {
        if (DB::table('tbl_services')->doesntExist() || Service::where('code', self::CODE)->exists()) {
            return;
        }

        Service::create([
            'service_name' => 'MDRRMO Certification',
            'description' => 'Certification issued by the MDRRMO.',
        ]);
    }

    public function down(): void
    {
        DB::table('tbl_services')->where('code', self::CODE)->delete();
    }
};
