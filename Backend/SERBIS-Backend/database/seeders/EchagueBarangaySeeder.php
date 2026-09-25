<?php

namespace Database\Seeders;

use App\Services\BarangaySync;
use Illuminate\Database\Seeder;

/**
 * All 64 barangays of Echague, Isabela. The matching and writing live in
 * BarangaySync, which `php artisan barangays:sync` shares, so the two cannot
 * disagree. Idempotent and never destructive; see that class.
 */
class EchagueBarangaySeeder extends Seeder
{
    public function run(): void
    {
        $plan = app(BarangaySync::class)->apply();

        $this->command?->info('EchagueBarangaySeeder: '.count($plan['inserts']).' inserted, '
            .count(array_filter($plan['matched'], fn ($m) => $m['type'] === 'name')).' existing rows stamped with a PSGC code.');

        if ($plan['unmatched'] !== []) {
            $this->command?->warn('Left untouched, matches no official barangay: '
                .implode(', ', array_map(fn ($u) => "#{$u['id']} {$u['name']}", $plan['unmatched'])));
        }
    }
}
