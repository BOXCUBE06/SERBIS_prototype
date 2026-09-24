<?php

namespace Database\Seeders;

use App\Models\Responder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local-only demo responders, assigned to the requests DemoSeeder made.
 * Runs on its own against existing demo data (php artisan db:seed
 * --class=DemoResponderSeeder) and is called at the end of DemoSeeder.
 *
 * Rules it keeps: only Booked (approved) and Resolved requests get responders;
 * nobody is 'deployed' because no seeded request is Responding; assignments
 * are stamped after the request was answered and before it closed.
 */
class DemoResponderSeeder extends Seeder
{
    // [name, position, contact, status]
    private const RESPONDERS = [
        ['Rodel Cabantog', 'Ambulance Driver', '09171234501', 'available'],
        ['Jimmy Alvarez', 'Emergency Medical Technician', '09171234502', 'available'],
        ['Leonardo Tagorda', 'Rescuer', '09171234503', 'available'],
        ['Mario Gaoat', 'Rescuer', '09171234504', 'available'],
        ['Analyn Pagaduan', 'Nurse', '09171234505', 'available'],
        ['Ernesto Dumlao', 'Boat Operator', '09171234506', 'available'],
        ['Marites Cabacungan', 'Emergency Medical Technician', '09171234507', 'available'],
        ['Benjamin Bumanglag', 'Rescuer', '09171234508', 'off_duty'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoResponderSeeder refuses to run unless APP_ENV=local (env: '.app()->environment().').');
        }

        if (DB::table('tbl_responders')->exists()) {
            $this->command?->warn('DemoResponderSeeder skipped: tbl_responders is not empty.');

            return;
        }

        mt_srand(20260925);
        $now = CarbonImmutable::now();
        $admins = DB::table('tbl_user')->pluck('admin_id')->all();
        $logs = [];

        Model::withoutEvents(function () use ($now, $admins, &$logs) {
            $ids = [];
            foreach (self::RESPONDERS as [$name, $position, $contact, $status]) {
                $created = $now->subDays(mt_rand(60, 110));
                $responder = Responder::forceCreate([
                    'name' => $name, 'position' => $position, 'contact_no' => $contact, 'status' => $status,
                    'created_at' => $created, 'updated_at' => $created,
                ]);
                $ids[] = $responder->responder_id;
                $logs[] = $this->log($responder->responder_id, 'created', compact('name', 'position', 'status') + ['contact_no' => $contact], $created, $admins[array_rand($admins)]);
            }

            // Off-duty staff are never assigned; everyone else rotates so the load spreads.
            $onDuty = array_slice($ids, 0, -1);
            $requests = DB::table('tbl_service_request')
                ->where(fn ($q) => $q->where('status', 'Resolved')->orWhere(fn ($b) => $b->where('status', 'Booked')->whereNotNull('processed_by')))
                ->whereNotNull('vehicle_id')
                ->orderBy('request_id')
                ->get();

            foreach ($requests as $i => $request) {
                $picked = array_map(fn ($k) => $onDuty[($i * 2 + $k) % count($onDuty)], range(0, mt_rand(1, 3) - 1));
                $from = CarbonImmutable::parse($request->first_responded_at);
                $to = $request->resolved_at ? CarbonImmutable::parse($request->resolved_at) : $now;
                $assigned = $from->addMinutes(mt_rand(5, 60));
                if ($assigned >= $to) {
                    $assigned = $from->addMinute();
                }

                foreach (array_unique($picked) as $responderId) {
                    DB::table('tbl_request_responders')->insert(['request_id' => $request->request_id, 'responder_id' => $responderId, 'assigned_at' => $assigned]);
                }
                $logs[] = $this->log($request->request_id, 'updated', ['responders' => array_values(array_unique($picked))], $assigned, $request->processed_by, \App\Models\ServiceRequest::class);
            }
        });

        usort($logs, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
        DB::table('tbl_system_logs')->insert($logs);
        $this->command?->info('DemoResponderSeeder: '.count(self::RESPONDERS).' responders, '.(count($logs) - count(self::RESPONDERS)).' requests assigned.');
    }

    private function log(int $id, string $action, array $new, CarbonImmutable $at, ?int $admin, string $model = Responder::class): array
    {
        return [
            'admin_id' => $admin, 'resident_id' => null, 'action_type' => $action,
            'auditable_type' => $model, 'auditable_id' => $id,
            'old_values' => null, 'new_values' => json_encode($new),
            'ip_address' => '127.0.0.1', 'user_agent' => 'DemoSeeder',
            'created_at' => $at->toDateTimeString(), 'updated_at' => $at->toDateTimeString(),
        ];
    }
}
