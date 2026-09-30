<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency hotlines shown in the app's Library, editable from the admin panel
 * instead of compiled into the app (M28).
 *
 * `numbers` is JSON ([{label, number}]) rather than a second table: a hotline's
 * numbers are only ever read and written together with it.
 *
 * Seeded here, not in a seeder, so production gets the list on deploy — same
 * reason as 2026_09_30_090000_add_all_echague_barangays. The rows match
 * kHotlines in Mobile/lib/data/hotlines.dart, which stays as the app's fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_emergency_hotlines', function (Blueprint $table) {
            $table->id('hotline_id');
            $table->string('label', 100);
            $table->string('label_fil', 100)->nullable();
            $table->json('numbers');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['Echague Rescue Hotline', 'Echague Rescue Hotline', [
                ['label' => 'Landline', 'number' => '(078) 324-5410'],
                ['label' => 'Globe', 'number' => '0917-626-2352'],
                ['label' => 'Smart', 'number' => '0919-991-7115'],
                ['label' => 'Sun', 'number' => '0933-868-2526'],
            ]],
            ['PDRRMO', 'PDRRMO', [
                ['label' => null, 'number' => '(078) 323-0416'],
                ['label' => null, 'number' => '0921-585-2341'],
            ]],
            ['ISELCO I', 'ISELCO I', [['label' => null, 'number' => '0955-698-1059']]],
            ['BFP', 'BFP', [['label' => null, 'number' => '(02) 426-3812']]],
            ['National Emergency', 'Pambansang Emerhensiya', [['label' => null, 'number' => '911']]],
        ];

        foreach ($rows as $i => [$label, $labelFil, $numbers]) {
            DB::table('tbl_emergency_hotlines')->insert([
                'label' => $label,
                'label_fil' => $labelFil,
                'numbers' => json_encode($numbers),
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_emergency_hotlines');
    }
};
