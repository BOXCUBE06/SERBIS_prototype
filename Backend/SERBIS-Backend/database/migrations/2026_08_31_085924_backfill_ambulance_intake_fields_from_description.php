<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// One-off data migration, separate from the schema change that added the
// columns (2026_08_31_085105) so this can be reverted on its own without
// dropping them. Ports parseAmbulanceDescription (ConductionRequestView.vue)
// to PHP rather than sharing it — that function is being deleted in this
// same change, and a migration must still be able to run against a
// database years from now, long after the Vue file it was copied from has
// moved on.
return new class extends Migration
{
    private const PLACEHOLDERS = [
        'patient_name' => 'Not specified',
        'origin' => 'Address not specified',
        'destination' => 'destination not specified',
        'condition' => 'Not described',
    ];

    public function up(): void
    {
        $ambulanceServiceId = DB::table('tbl_services')->where('code', 'ambulance-medical-response')->value('service_id');

        // Nothing seeded to backfill against — a fresh database, or one
        // where the service was renamed before this ran. Either way there
        // is nothing this migration can do.
        if (!$ambulanceServiceId) {
            return;
        }

        $rows = DB::table('tbl_service_request')
            ->where('service_id', $ambulanceServiceId)
            ->whereNull('patient_name')
            ->whereNotNull('description')
            ->get(['request_id', 'description']);

        $touchedIds = [];
        $unparsed = [];

        foreach ($rows as $row) {
            $parsed = self::parse($row->description);

            // Partial data is worse than none here: a row half-filled from a
            // guess looks the same in the admin panel as one staff actually
            // took down. Anything the parser could not fully read is left
            // alone and reported instead.
            if ($parsed['patient_name'] === '' || $parsed['origin'] === '' || $parsed['destination'] === '' || $parsed['condition'] === '') {
                $unparsed[] = $row;
                continue;
            }

            // patient_address has no equivalent in the old free-text format
            // — the paper form and AmbulanceFormData never asked for a
            // separate one — so it defaults to the pickup location, which is
            // the patient's own address in the overwhelming common case (a
            // dispatch from home). Staff can correct it by hand same as any
            // other field; this is a starting point, not a claim of fact.
            DB::table('tbl_service_request')->where('request_id', $row->request_id)->update([
                'patient_name' => $parsed['patient_name'],
                'patient_address' => $parsed['origin'],
                'pickup_location' => $parsed['origin'],
                'destination' => $parsed['destination'],
                'condition_notes' => $parsed['condition'],
            ]);

            $touchedIds[] = $row->request_id;
        }

        if ($unparsed) {
            $csv = fopen(storage_path('logs/dispatch-backfill-unparsed.csv'), 'w');
            fputcsv($csv, ['request_id', 'description']);
            foreach ($unparsed as $row) {
                fputcsv($csv, [$row->request_id, $row->description]);
            }
            fclose($csv);
        }

        // Read back by down() below, so a rollback undoes exactly the rows
        // this run touched — not every row that happens to be non-null by
        // the time someone rolls back, which would also erase a genuine
        // submission filed after this migration ran.
        file_put_contents(storage_path('logs/dispatch-backfill-touched-ids.json'), json_encode($touchedIds));
    }

    public function down(): void
    {
        $markerPath = storage_path('logs/dispatch-backfill-touched-ids.json');

        if (!file_exists($markerPath)) {
            return;
        }

        $ids = json_decode(file_get_contents($markerPath), true) ?: [];

        if ($ids) {
            DB::table('tbl_service_request')->whereIn('request_id', $ids)->update([
                'patient_name' => null,
                'patient_address' => null,
                'pickup_location' => null,
                'destination' => null,
                'condition_notes' => null,
            ]);
        }

        unlink($markerPath);
        // dispatch-backfill-unparsed.csv is left in place — it is a record
        // of what needed manual entry, not a marker this migration owns.
    }

    /** Same rules as parseAmbulanceDescription, minus Contact: — the new columns have no equivalent, since a walk-in's contact already lives structurally on the row. */
    private static function parse(string $description): array
    {
        $result = ['patient_name' => '', 'origin' => '', 'destination' => '', 'condition' => ''];

        foreach (explode("\n", $description) as $rawLine) {
            $line = trim($rawLine);

            if (preg_match('/^Patient:\s*(.*)$/', $line, $m)) {
                $result['patient_name'] = self::cleanParsed($m[1], self::PLACEHOLDERS['patient_name']);
                continue;
            }

            if (preg_match('/^Condition:\s*(.*)$/', $line, $m)) {
                $result['condition'] = self::cleanParsed($m[1], self::PLACEHOLDERS['condition']);
                continue;
            }

            if (str_contains($line, '→')) {
                [$from, $to] = array_pad(explode('→', $line, 2), 2, '');
                $result['origin'] = self::cleanParsed($from, self::PLACEHOLDERS['origin']);
                $result['destination'] = self::cleanParsed($to, self::PLACEHOLDERS['destination']);
            }
        }

        return $result;
    }

    private static function cleanParsed(?string $value, string $placeholder): string
    {
        $trimmed = trim($value ?? '');

        return ($trimmed === '' || $trimmed === $placeholder) ? '' : $trimmed;
    }
};
