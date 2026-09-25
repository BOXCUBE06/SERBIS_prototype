<?php

namespace App\Services;

use App\Support\AnalyticsCache;
use Illuminate\Support\Facades\DB;

/**
 * Brings tbl_barangay in line with the 64 official barangays of Echague, Isabela
 * (PSGC municipality 0203112000). Shared by EchagueBarangaySeeder and the
 * barangays:sync command so both decide exactly the same way.
 *
 * Never destructive: rows are matched by psgc_code first, then by normalised
 * name among rows that have no code yet, so an existing barangay keeps its id
 * and every resident and SMS blast pointing at it. A row that matches no
 * official barangay is left alone and reported.
 *
 * The list is a one-time copy of https://psgc.gitlab.io/api (municipality
 * 023112000). Nothing fetches it at runtime.
 */
class BarangaySync
{
    // [psgc 10-digit code, PSGC name]
    public const BARANGAYS = [
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

    /**
     * What a sync would do, without doing it.
     *
     * @return array{
     *     matched: list<array{id: int, current: string, official: string, code: string, type: string}>,
     *     inserts: list<array{code: string, name: string}>,
     *     unmatched: list<array{id: int, name: string}>
     * } matched type is "code" (already synced) or "name" (will be stamped with its code)
     */
    public function plan(): array
    {
        $rows = DB::table('tbl_barangay')->orderBy('barangay_id')->get(['barangay_id', 'barangay_name', 'psgc_code']);

        $byCode = $rows->whereNotNull('psgc_code')->keyBy('psgc_code');

        $freeByName = [];
        foreach ($rows->whereNull('psgc_code') as $row) {
            $freeByName[self::normalise($row->barangay_name)][] = $row;
        }

        $matched = [];
        $inserts = [];

        foreach (self::BARANGAYS as [$code, $name]) {
            if ($existing = $byCode->get($code)) {
                $matched[] = $this->match($existing, $name, $code, 'code');

                continue;
            }

            $key = self::normalise($name);
            $existing = isset($freeByName[$key]) ? array_shift($freeByName[$key]) : null;

            if ($existing !== null) {
                $matched[] = $this->match($existing, $name, $code, 'name');
            } else {
                $inserts[] = ['code' => $code, 'name' => $name];
            }
        }

        // Whatever is still free after every official name has had its turn.
        $unmatched = [];
        foreach ($freeByName as $left) {
            foreach ($left as $row) {
                $unmatched[] = ['id' => $row->barangay_id, 'name' => $row->barangay_name];
            }
        }

        return ['matched' => $matched, 'inserts' => $inserts, 'unmatched' => $unmatched];
    }

    /**
     * Writes the plan in one transaction and returns it, so the caller can print
     * what changed: the "name" matches and the inserts. Planning happens inside
     * the transaction, so it cannot go stale between the read and the write.
     */
    public function apply(): array
    {
        $plan = DB::transaction(function () {
            $plan = $this->plan();
            $now = now();

            foreach ($plan['matched'] as $row) {
                if ($row['type'] === 'name') {
                    DB::table('tbl_barangay')->where('barangay_id', $row['id'])
                        ->update(['psgc_code' => $row['code'], 'updated_at' => $now]);
                }
            }

            foreach ($plan['inserts'] as $row) {
                DB::table('tbl_barangay')->insert([
                    'barangay_name' => $row['name'],
                    'psgc_code' => $row['code'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $plan;
        });

        // DB::table skips the model events that normally clear this.
        AnalyticsCache::flush();

        return $plan;
    }

    private function match(object $existing, string $official, string $code, string $type): array
    {
        return [
            'id' => $existing->barangay_id,
            'current' => $existing->barangay_name,
            'official' => $official,
            'code' => $code,
            'type' => $type,
        ];
    }

    /** "Cabugao (Pob.)" and "cabugao" are the same barangay; so are "Sta. Ana" and "Santa Ana". */
    public static function normalise(string $name): string
    {
        $name = strtolower(preg_replace('/\(pob\.?\)|\bpoblacion\b|\bbrgy\.?\b|\bbarangay\b/i', '', $name));
        $name = preg_replace(['/\bsta\b\.?/', '/\bsto\b\.?/', '/[^a-z0-9]+/'], ['santa', 'santo', ' '], $name);

        return trim($name);
    }
}
