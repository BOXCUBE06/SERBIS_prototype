<?php

namespace Database\Seeders;

use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
                .app()->environment().').'
            );

            return;
        }

        // Was `rand(1, 5)` with a comment assuming "at least 5 services". That
        // is an assumption about auto-increment ids, not about the catalogue:
        // removing an entry from ServiceSeeder shifts every id after it, so the
        // range silently starts pointing at different services -- or, once the
        // catalogue is shorter than the literal, at rows that do not exist and
        // an FK violation mid-seed. Codes are stable and immutable by design,
        // so resolve against those instead.
        $serviceIds = Service::whereIn('code', [
            'ambulance-medical-response',
            'relief-goods-distribution',
            'road-clearing',
            'debris-removal',
            'animal-rescue',
        ])->pluck('service_id')->all();

        if (empty($serviceIds)) {
            $this->command?->warn(
                'ServiceRequestSeeder skipped: none of the expected service codes exist -- run ServiceSeeder first.'
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
                'service_id' => $serviceIds[array_rand($serviceIds)],
                'vehicle_id' => null,
                'processed_by' => null,
                'description' => 'Simulated dashboard test data '.$i,
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
