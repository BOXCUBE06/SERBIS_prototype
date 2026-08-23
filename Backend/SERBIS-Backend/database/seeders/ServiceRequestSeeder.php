<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ServiceRequestSeeder extends Seeder
{
    public function run(): void
    {
        // Thirty invented requests attributed to whichever residents happen to
        // hold ids 1-10. On a real deployment that is fabricated case history
        // in the MDRRMO's own records, so the guard is not optional.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'ServiceRequestSeeder skipped: refuses to seed simulated requests outside local/testing (env: '
                . app()->environment() . ').'
            );

            return;
        }

        $statuses = ['Pending', 'Responding', 'Resolved'];
        $records = [];

        for ($i = 1; $i <= 30; $i++) {
            $status = $statuses[array_rand($statuses)];
            $randomDate = Carbon::now()->subDays(rand(0, 30))->subHours(rand(0, 23));

            $records[] = [
                'resident_id' => rand(1, 10), // Assumes you have at least 10 residents seeded
                'service_id' => rand(1, 5),   // Assumes you have at least 5 services seeded
                'vehicle_id' => null,
                'processed_by' => null,
                'description' => 'Simulated dashboard test data ' . $i,
                'valid_id' => null,
                'status' => $status,
                'remarks' => $status === 'Resolved' ? 'Resolved by response team.' : null,
                'created_at' => $randomDate,
                'updated_at' => $randomDate,
            ];
        }

        DB::table('tbl_service_request')->insert($records);
    }
}

