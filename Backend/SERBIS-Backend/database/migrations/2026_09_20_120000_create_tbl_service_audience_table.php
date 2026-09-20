<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which account types may request which service. A row means "this account
 * type may request this service"; the admin panel's Service Audience page
 * adds and removes rows, so MDRRMO changes it without a deploy.
 *
 * Keyed on the service `code`, not a foreign key to tbl_services, because two
 * of the twelve things a resident can ask for are not service rows: Equipment
 * Borrowing (its own flow, POST /borrowings) and Others (a request with no
 * service_id). They use the reserved codes `equipment-borrowing` and
 * `others`. A surrogate `audience_id` exists only so the change history
 * (TracksHistory) has a key to log against.
 *
 * A service with no rows at all is open to every type. That is what a service
 * created later in the panel gets, and it is why the page refuses to save a
 * service with nothing ticked: "no rows" would mean the opposite of what the
 * empty row of boxes says. Disabling a service is what `is_active` is for.
 *
 * Seeded here, not in a seeder, so a fresh database and the live one end up
 * with the same mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_service_audience', function (Blueprint $table) {
            $table->id('audience_id');
            $table->string('service_code', 100);
            $table->enum('account_type', ['head_of_family', 'barangay', 'organization']);
            $table->timestamps();

            $table->unique(['service_code', 'account_type']);
        });

        $all = ['head_of_family', 'barangay', 'organization'];

        $mapping = [
            'ambulance-medical-response' => $all,
            'animal-rescue' => $all,
            'debris-removal' => $all,
            'power-line-repair' => $all,
            'road-clearing' => $all,
            'sandbagging' => $all,
            'equipment-borrowing' => $all,
            'mdrrmo-certification' => $all,
            'others' => $all,
            'relief-goods-distribution' => ['barangay'],
            'drrm-trainings-and-seminars' => ['barangay', 'organization'],
            'simulation-drills-nsed' => ['barangay', 'organization'],
        ];

        $now = now();
        $rows = [];

        foreach ($mapping as $code => $types) {
            foreach ($types as $type) {
                $rows[] = [
                    'service_code' => $code,
                    'account_type' => $type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('tbl_service_audience')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_service_audience');
    }
};
