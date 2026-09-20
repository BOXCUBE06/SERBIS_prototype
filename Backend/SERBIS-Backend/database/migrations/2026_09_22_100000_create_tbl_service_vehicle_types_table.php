<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which kinds of unit may be sent on which service. A row means "a unit of this
 * type may be assigned to this service"; the admin panel's Service Vehicles page
 * adds and removes rows, so MDRRMO changes the mapping without a deploy.
 *
 * Keyed on the service `code`, like tbl_service_audience, so renaming a service
 * in the panel does not detach it.
 *
 * A service with no rows takes any non-ambulance unit — what the dispatch picker
 * did before this table existed, and what a service created later in the panel
 * gets. Ambulance requests are not mapped here at all: they take an Ambulance
 * and nothing else, a fixed rule, and programs (trainings, drills,
 * certification) take no vehicle.
 *
 * Seeded here, not in a seeder, so a fresh database and the live one end up with
 * the same mapping. Power Line Repair is left unmapped on purpose: the office
 * named no unit type for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_service_vehicle_types', function (Blueprint $table) {
            $table->id('mapping_id');
            $table->string('service_code', 100);
            $table->string('vehicle_type', 50);
            $table->timestamps();

            $table->unique(['service_code', 'vehicle_type']);
        });

        $mapping = [
            'animal-rescue' => ['Rescue Vehicle', 'Boat'],
            'relief-goods-distribution' => ['Rescue Vehicle'],
            'road-clearing' => ['Rescue Vehicle'],
            'debris-removal' => ['Rescue Vehicle'],
            'sandbagging' => ['Rescue Vehicle'],
        ];

        $now = now();
        $rows = [];

        foreach ($mapping as $code => $types) {
            foreach ($types as $type) {
                $rows[] = [
                    'service_code' => $code,
                    'vehicle_type' => $type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('tbl_service_vehicle_types')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_service_vehicle_types');
    }
};
