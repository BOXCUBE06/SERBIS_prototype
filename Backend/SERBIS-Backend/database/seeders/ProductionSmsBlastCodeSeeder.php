<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The one shared text-blast code, for a deployment where tbl_sms_blast_code
 * starts empty.
 *
 * Same shape as ProductionAdminSeeder: reads SMS_BLAST_CODE_SEED from the
 * environment and refuses to run without it, so no code capable of sending a
 * billed blast is ever committed to the repository.
 *
 * Idempotent on "a row already exists". A second run leaves the current code
 * alone rather than resetting it to whatever the env var happens to hold —
 * after an admin rotates it in the panel, a redeploy must not put the old one
 * back.
 */
class ProductionSmsBlastCodeSeeder extends Seeder
{
    public function run(): void
    {
        $code = env('SMS_BLAST_CODE_SEED');

        if (! is_string($code) || $code === '') {
            $this->command?->error(
                'ProductionSmsBlastCodeSeeder skipped: SMS_BLAST_CODE_SEED is not set. '
                .'Set it in the host dashboard and re-run, or no admin will be able to send a text blast.'
            );

            return;
        }

        if (DB::table('tbl_sms_blast_code')->exists()) {
            $this->command?->info(
                'ProductionSmsBlastCodeSeeder skipped: a code already exists; left untouched.'
            );

            return;
        }

        $now = now();

        DB::table('tbl_sms_blast_code')->insert([
            'code_hash' => Hash::make($code),
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info('ProductionSmsBlastCodeSeeder: set the initial text blast code.');
    }
}
