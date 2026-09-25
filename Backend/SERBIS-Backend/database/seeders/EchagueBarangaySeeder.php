<?php

namespace Database\Seeders;

use App\Support\AnalyticsCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * All 64 barangays of Echague, Isabela (PSGC municipality 0203112000).
 *
 * Idempotent, and never destructive: rows are matched by psgc_code first, then
 * by normalised name among rows that have no code yet, so a barangay that
 * already exists keeps its id and every resident and SMS blast pointing at it.
 * A row that matches no official barangay is left alone and reported.
 *
 * The list is a one-time copy of https://psgc.gitlab.io/api (municipality
 * 023112000). Nothing fetches it at runtime.
 */
class EchagueBarangaySeeder extends Seeder
{
    // [psgc 10-digit code, PSGC name]
    private const BARANGAYS = [
        ['0203112001', 'Angoluan'],
        ['0203112002', 'Annafunan'],
        ['0203112003', 'Arabiat'],
        ['0203112004', 'Aromin'],
        ['0203112005', 'Babaran'],
        ['0203112006', 'Bacradal'],
        ['0203112007', 'Benguet'],
        ['0203112008', 'Buneg'],
        ['0203112009', 'Busilelao'],
        ['0203112010', 'Caniguing'],
        ['0203112011', 'Carulay'],
        ['0203112012', 'Castillo'],
        ['0203112013', 'Dammang East'],
        ['0203112014', 'Dammang West'],
        ['0203112015', 'Dicaraoyan'],
        ['0203112016', 'Dugayong'],
        ['0203112017', 'Fugu'],
        ['0203112018', 'Garit Norte'],
        ['0203112019', 'Garit Sur'],
        ['0203112020', 'Gucab'],
        ['0203112021', 'Gumbauan'],
        ['0203112023', 'Ipil'],
        ['0203112024', 'Libertad'],
        ['0203112025', 'Mabbayad'],
        ['0203112026', 'Mabuhay'],
        ['0203112027', 'Madadamian'],
        ['0203112028', 'Magleticia'],
        ['0203112029', 'Malibago'],
        ['0203112030', 'Maligaya'],
        ['0203112031', 'Malitao'],
        ['0203112032', 'Narra'],
        ['0203112033', 'Nilumisu'],
        ['0203112034', 'Pag-asa'],
        ['0203112036', 'Pangal Norte'],
        ['0203112037', 'Pangal Sur'],
        ['0203112039', 'Rumang-ay'],
        ['0203112040', 'Salay'],
        ['0203112041', 'Salvacion'],
        ['0203112042', 'San Antonio Ugad'],
        ['0203112043', 'San Antonio Minit'],
        ['0203112044', 'San Carlos'],
        ['0203112045', 'San Fabian'],
        ['0203112046', 'San Felipe'],
        ['0203112047', 'San Juan'],
        ['0203112048', 'San Manuel'],
        ['0203112049', 'San Miguel'],
        ['0203112050', 'San Salvador'],
        ['0203112051', 'Santa Ana'],
        ['0203112052', 'Santa Cruz'],
        ['0203112053', 'Santa Maria'],
        ['0203112054', 'Santa Monica'],
        ['0203112055', 'Santo Domingo'],
        ['0203112056', 'Silauan Sur (Pob.)'],
        ['0203112057', 'Silauan Norte (Pob.)'],
        ['0203112058', 'Sinabbaran'],
        ['0203112059', 'Soyung'],
        ['0203112060', 'Taggappan'],
        ['0203112061', 'Tuguegarao'],
        ['0203112062', 'Villa Campo'],
        ['0203112063', 'Villa Fermin'],
        ['0203112064', 'Villa Rey'],
        ['0203112065', 'Villa Victoria'],
        ['0203112066', 'Cabugao (Pob.)'],
        ['0203112067', 'Diasan'],
    ];

    public function run(): void
    {
        $now = now();
        $unmatched = [];

        DB::transaction(function () use ($now, &$unmatched) {
            $byCode = DB::table('tbl_barangay')->whereNotNull('psgc_code')->pluck('barangay_id', 'psgc_code');
            $free = DB::table('tbl_barangay')->whereNull('psgc_code')->orderBy('barangay_id')->get(['barangay_id', 'barangay_name']);

            $freeByName = [];
            foreach ($free as $row) {
                $freeByName[self::normalise($row->barangay_name)][] = $row;
            }

            foreach (self::BARANGAYS as [$code, $name]) {
                if (isset($byCode[$code])) {
                    continue;
                }

                $key = self::normalise($name);
                $existing = isset($freeByName[$key]) ? array_shift($freeByName[$key]) : null;

                if ($existing !== null) {
                    DB::table('tbl_barangay')->where('barangay_id', $existing->barangay_id)
                        ->update(['psgc_code' => $code, 'updated_at' => $now]);

                    continue;
                }

                DB::table('tbl_barangay')->insert([
                    'barangay_name' => $name,
                    'psgc_code' => $code,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Whatever is still free after every official name has had its turn.
            foreach ($freeByName as $rows) {
                foreach ($rows as $row) {
                    $unmatched[] = "#{$row->barangay_id} {$row->barangay_name}";
                }
            }
        });

        // DB::table skips the model events that normally clear this.
        AnalyticsCache::flush();

        $this->command?->info('EchagueBarangaySeeder: '.DB::table('tbl_barangay')->whereNotNull('psgc_code')->count().' barangays carry a PSGC code.');

        if ($unmatched !== []) {
            $this->command?->warn('Left untouched, matches no official barangay: '.implode(', ', $unmatched));
        }
    }

    /** "Cabugao (Pob.)" and "cabugao" are the same barangay; so are "Sta. Ana" and "Santa Ana". */
    private static function normalise(string $name): string
    {
        $name = strtolower(preg_replace('/\(pob\.?\)|\bpoblacion\b|\bbrgy\.?\b|\bbarangay\b/i', '', $name));
        $name = preg_replace(['/\bsta\b\.?/', '/\bsto\b\.?/', '/[^a-z0-9]+/'], ['santa', 'santo', ' '], $name);

        return trim($name);
    }
}
