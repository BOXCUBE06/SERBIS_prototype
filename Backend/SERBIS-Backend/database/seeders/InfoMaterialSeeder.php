<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InfoMaterialSeeder extends Seeder
{
    /**
     * Seeds the disaster-preparedness materials residents download in the mobile
     * app. Before this existed tbl_info_materials had no seeder at all, so every
     * `migrate:fresh` left the table empty with the uploaded files orphaned on
     * disk, and there was nothing to develop the mobile download/offline-cache
     * feature against.
     *
     * Writes real, openable files rather than placeholder bytes — an offline cache
     * cannot be tested against a file no viewer will open.
     */
    public function run(): void
    {
        // Plants files on the public disk under fixed names, so it must never run
        // against a real deployment where those names could collide with uploads.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'InfoMaterialSeeder skipped: refuses to seed materials outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        // Blind inserts would stack duplicates on every re-run: the table has no
        // unique constraint on title or file_path.
        if (DB::table('tbl_info_materials')->exists()) {
            $this->command?->info('InfoMaterialSeeder skipped: tbl_info_materials already has rows.');

            return;
        }

        // uploader_id is a FK to tbl_user.admin_id.
        $uploaderId = DB::table('tbl_user')->min('admin_id');

        if (! $uploaderId) {
            $this->command?->warn('InfoMaterialSeeder skipped: no admin exists — run AdminSeeder first.');

            return;
        }

        $materials = [
            [
                'title' => 'Flood Preparedness Checklist',
                'file' => 'seed-flood-preparedness-checklist.pdf',
                'bytes' => $this->pdf('Flood Preparedness Checklist', [
                    'Before the flood',
                    '- Keep a go-bag with water, food, medicine and IDs.',
                    '- Know the nearest evacuation center and two routes to it.',
                    '- Charge phones and power banks when a warning is raised.',
                    '',
                    'During the flood',
                    '- Move to higher ground immediately. Do not wait for water to rise.',
                    '- Never cross moving water, even ankle deep.',
                    '- Switch off the main power before leaving the house.',
                    '',
                    'After the flood',
                    '- Boil drinking water until authorities declare it safe.',
                    '- Report damaged power lines to the MDRRMO hotline.',
                ]),
            ],
            [
                'title' => 'Earthquake Drill Guide',
                'file' => 'seed-earthquake-drill-guide.pdf',
                'bytes' => $this->pdf('Earthquake Drill Guide', [
                    'Duck, Cover and Hold',
                    '- Drop to the floor before the shaking drops you.',
                    '- Take cover under a sturdy desk or table.',
                    '- Hold on until the shaking stops completely.',
                    '',
                    'After the shaking stops',
                    '- Evacuate calmly using the stairs. Never the elevator.',
                    '- Assemble at the designated open area away from buildings.',
                    '- Expect aftershocks and stay clear of damaged walls.',
                    '',
                    'At home',
                    '- Secure cabinets, shelves and heavy appliances to the wall.',
                    '- Agree on one out-of-town contact the whole family can call.',
                ]),
            ],
            [
                'title' => 'Evacuation Center Map',
                'file' => 'seed-evacuation-center-map.png',
                'bytes' => $this->png(),
            ],
        ];

        $now = now();

        foreach ($materials as $material) {
            $path = 'info_materials/'.$material['file'];

            // Same disk and same 'storage/' prefix InfoMaterialController@store uses,
            // so seeded rows and uploaded rows are indistinguishable to a client.
            // The default 'local' disk roots at storage/app/private and is not
            // web-reachable, which is what broke downloads before.
            Storage::disk('public')->put($path, $material['bytes']);

            DB::table('tbl_info_materials')->insert([
                'uploader_id' => $uploaderId,
                'title' => $material['title'],
                'file_path' => 'storage/'.$path,
                'file_type' => pathinfo($material['file'], PATHINFO_EXTENSION),
                'file_size' => strlen($material['bytes']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->command?->info(
            'InfoMaterialSeeder: created '.count($materials).' materials on the public disk.'
        );
    }

    /**
     * Builds a minimal but structurally valid PDF. The xref table stores real byte
     * offsets, computed as the objects are appended — a hardcoded xref produces a
     * file that some viewers open and others reject, which is worse than no file.
     */
    private function pdf(string $title, array $lines): string
    {
        $content = "BT\n/F1 18 Tf\n72 720 Td\n(".$this->escape($title).") Tj\n/F1 11 Tf\n";

        foreach ($lines as $line) {
            $content .= "0 -22 Td\n(".$this->escape($line).") Tj\n";
        }

        $content .= 'ET';

        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]'
                .'/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>',
            '<</Length '.strlen($content).">>\nstream\n".$content."\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $size = count($objects) + 1;

        $pdf .= "xref\n0 ".$size."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<</Size ".$size."/Root 1 0 R>>\nstartxref\n".$xrefOffset."\n%%EOF\n";

        return $pdf;
    }

    /**
     * Backslash and parentheses terminate a PDF string literal, so they have to be
     * escaped or the file will not parse.
     */
    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * A 16x16 solid PNG. Stands in for a scanned map: enough to prove the image
     * path of the download and offline-cache flow end to end.
     */
    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAPElEQVR42u3NMQEAAAgDoJvc0BvB'
            .'HwaglVzOhAkTJkyYMGHChAkTJkyYMGHChAkTJkyYMGHChAkT9m0LmMgBAaJPGuIAAAAASUVORK5CYII='
        );
    }
}
