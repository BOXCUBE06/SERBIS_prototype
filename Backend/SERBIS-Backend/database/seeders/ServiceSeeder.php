<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceSeeder extends Seeder
{
    /** Removed services, by code: deleted if unused, else deactivated. */
    public const RETIRED = ['animal-rescue'];

    /**
     * The services the MDRRMO offers: the six response services, then the
     * programs it runs. Runs on production; this is their data, not test data.
     * Idempotent: safe to re-run on a filled table.
     *
     * Animal Rescue was removed at the office's request (see RETIRED).
     *
     * Flood Evacuation, Fire Rescue and Search and Rescue were removed
     * deliberately. Each is a life-threatening event that belongs on a phone
     * call, not in a request queue that an admin reviews, approves and
     * schedules -- and SERBIS is explicitly not an emergency-response system.
     * The app already ships an SOS sheet of emergency hotlines, which is the
     * correct channel for all three. Tellingly, they were also the only three
     * with no guided intake form: every one fell through to the generic
     * description box, because nobody could design a form for them that made
     * sense.
     *
     * Do not add them back without deciding what happens between a resident
     * filing "my house is on fire" and someone reading it.
     *
     * Written through the model, not DB::table()->insert(). `tbl_services.code`
     * is NOT NULL and is filled by a `creating` hook on App\Models\Service,
     * and a query-builder insert fires no Eloquent events — so the bulk insert
     * this used to do now fails outright with "Field 'code' doesn't have a
     * default value", on a fresh database, at deploy time.
     */
    public function run(): void
    {
        $services = [
            ['service_name' => 'Ambulance/Medical Response', 'category' => 'medical', 'description' => 'Ambulance transport for non-life-threatening medical needs.'],
            ['service_name' => 'Relief Goods Distribution', 'category' => 'relief', 'description' => 'Distribution of essential relief goods during disasters.'],
            ['service_name' => 'Road Clearing', 'category' => 'infrastructure', 'description' => 'Clearing roads of debris and obstacles after natural calamities.'],
            ['service_name' => 'Power Line Repair', 'category' => 'infrastructure', 'description' => 'Emergency repair of downed power lines.'],
            ['service_name' => 'Debris Removal', 'category' => 'infrastructure', 'description' => 'Removal of hazardous debris from public areas.'],
            ['service_name' => 'Sandbagging', 'category' => 'rescue', 'description' => 'Provision and placement of sandbags for flood prevention.'],
            ['service_name' => 'DRRM Trainings and Seminars', 'category' => 'programs', 'description' => 'Disaster risk reduction and management trainings and seminars (IEC) for barangays and organizations.'],
            ['service_name' => 'Simulation Drills / NSED', 'category' => 'programs', 'description' => 'Simulation drills, including the Nationwide Simultaneous Earthquake Drill (NSED), for barangays and organizations.'],
            ['service_name' => 'MDRRMO Certification', 'category' => 'programs', 'description' => 'Certification issued by the MDRRMO.'],
        ];

        // Matched by code, which never changes after creation: a service the
        // office renamed or edited in Manage Services is found and left as is.
        foreach ($services as $service) {
            if (! Service::where('code', Service::slugify($service['service_name']))->exists()) {
                Service::create($service);
            }
        }

        $this->retire();
    }

    private function retire(): void
    {
        // Side tables are keyed by code, not a foreign key, so their rows can
        // outlive the service; cleared whether or not the service row exists.
        DB::table('tbl_service_audience')->whereIn('service_code', self::RETIRED)->delete();
        DB::table('tbl_service_vehicle_types')->whereIn('service_code', self::RETIRED)->delete();

        foreach (Service::whereIn('code', self::RETIRED)->get() as $service) {
            if (DB::table('tbl_service_request')->where('service_id', $service->service_id)->exists()) {
                $service->update(['is_active' => false]);
                $this->command?->warn("Service '{$service->service_name}' has requests: deactivated.");

                continue;
            }

            $service->delete();
            $this->command?->info("Service '{$service->service_name}' removed.");
        }
    }
}
