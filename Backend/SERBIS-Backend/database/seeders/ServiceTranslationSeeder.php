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
        'Flood Evacuation' => [
            'name' => 'Paglikas sa Baha',
            'description' => 'Tulong at paglikas tuwing may baha.',
        ],
        'Fire Rescue' => [
            'name' => 'Pagsagip sa Sunog',
            'description' => 'Pang-emerhensiyang pagsagip sa sunog.',
        ],
        'Ambulance/Medical Response' => [
            'name' => 'Ambulansya / Tugong Medikal',
            'description' => 'Pang-emerhensiyang tugong medikal at serbisyong ambulansya.',
        ],
        'Relief Goods Distribution' => [
            'name' => 'Pamamahagi ng Relief Goods',
            'description' => 'Pamamahagi ng mahahalagang relief goods tuwing may sakuna.',
        ],
        'Road Clearing' => [
            'name' => 'Paglinis ng Daan',
            'description' => 'Paglilinis ng mga daan mula sa debris at balakid pagkatapos ng kalamidad.',
        ],
        'Search and Rescue' => [
            'name' => 'Paghahanap at Pagsagip',
            'description' => 'Paghahanap at pagsagip sa mga nawawalang tao.',
        ],
        'Power Line Repair' => [
            'name' => 'Pagkumpuni ng Linya ng Kuryente',
            'description' => 'Pang-emerhensiyang pagkumpuni ng mga bumagsak na linya ng kuryente.',
        ],
        'Debris Removal' => [
            'name' => 'Pag-aalis ng Debris',
            'description' => 'Pag-aalis ng mapanganib na debris sa mga pampublikong lugar.',
        ],
        'Animal Rescue' => [
            'name' => 'Pagsagip sa Hayop',
            'description' => 'Pagsagip sa mga naipit o nasugatang hayop.',
        ],
        'Sandbagging' => [
            'name' => 'Paglalagay ng Sandbags',
            'description' => 'Paglalaan at paglalagay ng sandbags upang maiwasan ang baha.',
        ],
    ];

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->command->warn('ServiceTranslationSeeder skipped: not a local environment.');
            return;
        }

        $services = DB::table('tbl_services')->get(['service_id', 'service_name', 'description']);

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
                'description' => $service->description,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (isset(self::FILIPINO[$service->service_name])) {
                $rows[] = [
                    'service_id' => $service->service_id,
                    'locale' => 'fil',
                    'name' => self::FILIPINO[$service->service_name]['name'],
                    'description' => self::FILIPINO[$service->service_name]['description'],
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
            ['name', 'description', 'updated_at']
        );

        $this->command->info('Seeded '.count($rows).' service translations.');
    }
}
