<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceTranslationSeeder extends Seeder
{
    /**
     * Tagalog names for the ten seeded services, keyed on the English name so a
     * re-ordered ServiceSeeder cannot silently shift every translation by one
     * row. Reviewed and confirmed as Tagalog by the project owner, 2026-07-26.
     *
     * "Paglinis ng Daan" is not a new coinage: it is the existing
     * `type.road.title` string in the app's own translations.dart, reused so the
     * label does not change spelling depending on which screen shows it.
     *
     * Yogad is planned but deliberately absent -- nobody on the team speaks it,
     * and inventing emergency terminology is worse than falling back to English.
     */
    private const FILIPINO = [
        'Flood Evacuation' => 'Paglikas sa Baha',
        'Fire Rescue' => 'Pagsagip sa Sunog',
        'Ambulance/Medical Response' => 'Ambulansya / Tugong Medikal',
        'Relief Goods Distribution' => 'Pamamahagi ng Relief Goods',
        'Road Clearing' => 'Paglinis ng Daan',
        'Search and Rescue' => 'Paghahanap at Pagsagip',
        'Power Line Repair' => 'Pagkumpuni ng Linya ng Kuryente',
        'Debris Removal' => 'Pag-aalis ng Debris',
        'Animal Rescue' => 'Pagsagip sa Hayop',
        'Sandbagging' => 'Paglalagay ng Sandbags',
    ];

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->command->warn('ServiceTranslationSeeder skipped: not a local environment.');
            return;
        }

        $services = DB::table('tbl_services')->get(['service_id', 'service_name']);

        if ($services->isEmpty()) {
            $this->command->warn('ServiceTranslationSeeder skipped: no services to translate.');
            return;
        }

        $rows = [];
        $now = now();

        foreach ($services as $service) {
            // English comes from the column itself, so the fallback locale is
            // always populated even for a service this seeder has no Tagalog for.
            $rows[] = [
                'service_id' => $service->service_id,
                'locale' => 'en',
                'name' => $service->service_name,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (isset(self::FILIPINO[$service->service_name])) {
                $rows[] = [
                    'service_id' => $service->service_id,
                    'locale' => 'fil',
                    'name' => self::FILIPINO[$service->service_name],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Keyed on the same (service_id, locale) the table is unique on, so a
        // re-run corrects a changed translation instead of aborting on the
        // duplicate key or accumulating a second row.
        DB::table('tbl_service_translations')->upsert(
            $rows,
            ['service_id', 'locale'],
            ['name', 'updated_at']
        );

        $this->command->info('Seeded '.count($rows).' service translations.');
    }
}
