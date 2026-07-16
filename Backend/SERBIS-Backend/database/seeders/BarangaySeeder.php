<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BarangaySeeder extends Seeder
{
    /**
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
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'BarangaySeeder skipped: refuses to seed test barangays outside local/testing (env: '
                . app()->environment() . ').'
            );

            return;
        }

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
                'created_at'    => $now,
                'updated_at'    => $now,
            ], self::BARANGAYS)
        );

        $this->command?->info('BarangaySeeder: created ' . count(self::BARANGAYS) . ' barangays.');
    }
}
