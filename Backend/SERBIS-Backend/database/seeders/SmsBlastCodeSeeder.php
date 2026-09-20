<?php

namespace Database\Seeders;

use App\Models\SmsBlastCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/testing counterpart to ProductionSmsBlastCodeSeeder: plants a
 * publicly-known code, so it must never reach a real deployment.
 */
class SmsBlastCodeSeeder extends Seeder
{
    public const DEV_CODE = '123456';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'SmsBlastCodeSeeder skipped: refuses to seed a default code outside local/testing (env: '
                .app()->environment().').'
            );

            return;
        }

        if (SmsBlastCode::query()->exists()) {
            $this->command?->info('SmsBlastCodeSeeder skipped: a code already exists; left untouched.');

            return;
        }

        SmsBlastCode::create(['code_hash' => Hash::make(self::DEV_CODE)]);

        $this->command?->info('SmsBlastCodeSeeder: set the default text blast code ('.self::DEV_CODE.').');
    }
}
