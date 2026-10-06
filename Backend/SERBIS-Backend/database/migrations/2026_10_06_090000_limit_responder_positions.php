<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Responder positions are now Responder::POSITIONS only: Team Leader,
 * Assistant Leader, Logistics, Driver. Rows written before that rule carry
 * free text. Drivers by another name become Driver, an official name in the
 * wrong case or with stray spaces gets its proper spelling, and everything
 * else becomes Logistics for staff to re-pick in the admin panel.
 *
 * Not reversible: the old free text is not kept anywhere.
 */
return new class extends Migration
{
    private const OFFICIAL = ['Team Leader', 'Assistant Leader', 'Logistics', 'Driver'];

    private const DRIVERS = ['ambulance driver', 'boat operator'];

    public function up(): void
    {
        // Every row, compared in PHP: MySQL's collation would call "driver"
        // equal to "Driver" and leave the wrong spelling in place.
        $rows = DB::table('tbl_responders')->get(['responder_id', 'position']);

        foreach ($rows as $row) {
            $text = strtolower(trim((string) $row->position));
            $official = collect(self::OFFICIAL)->first(fn ($p) => strtolower($p) === $text);
            $position = $official ?? (in_array($text, self::DRIVERS, true) ? 'Driver' : 'Logistics');

            if ($position !== $row->position) {
                DB::table('tbl_responders')->where('responder_id', $row->responder_id)->update(['position' => $position]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to restore: the old free text was not kept.
    }
};
