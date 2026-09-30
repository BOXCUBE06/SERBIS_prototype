<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BarangaySeeder extends Seeder
{
    /**
     * The three barangays SERBIS first covered (confirmed 2026-08-23). Sign-up
     * has since opened to all 64 of Echague, which the
     * 2026_09_30_090000_add_all_echague_barangays migration adds via
     * BarangaySync, so on a migrated database this seeder finds the table
     * populated and skips. Kept for the 3 ids older data and demos expect.
     *
     * Names are bare, with no "Brgy." prefix: the dashboard choropleth joins
     * these against GeoJSON feature names, which carry the bare name. A prefix
     * here silently breaks that join — every polygon falls through to a zero
     * count and renders grey.
     */
    private const BARANGAYS = [
        'San Fabian',
        'San Miguel',
        'San Antonio Ugad',
    ];

    public function run(): void
    {
        // barangay_name carries no unique constraint, so a blind insert appends
        // a duplicate set on every run and residents scatter across the copies.
        if (DB::table('tbl_barangay')->exists()) {
            $this->command?->warn('BarangaySeeder skipped: tbl_barangay is not empty.');

            return;
        }

        $now = Carbon::now();

        DB::table('tbl_barangay')->insert(
            array_map(fn (string $name) => [
                'barangay_name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ], self::BARANGAYS)
        );

        $this->command?->info('BarangaySeeder: created '.count(self::BARANGAYS).' barangays.');
    }
}
