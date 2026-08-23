<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stable identifier for a service, independent of its display name.
 *
 * `service_id` cannot serve: it is a positional auto-increment, so the same
 * service holds a different id on a database seeded in a different order.
 * `service_name` cannot serve either: it carries no unique constraint and an
 * admin can rewrite it through PUT /api/services/{id}. A client that keys
 * anything on the display name is one rename away from silently misbehaving.
 *
 * Added nullable, backfilled, then tightened — a column cannot be born NOT NULL
 * on a table that already has rows.
 *
 * The slug rule is duplicated here rather than called from App\Models\Service.
 * A migration records what the schema did on the day it ran; reaching into a
 * model would let a later edit to that model silently rewrite this history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded because the backfill below can throw, and MySQL does not roll
        // DDL back: a failed run leaves the nullable column in place. Without
        // this check the retry the error message asks for would die on a
        // duplicate column instead of finishing the job.
        if (! Schema::hasColumn('tbl_services', 'code')) {
            Schema::table('tbl_services', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->after('service_name');
            });
        }

        $this->backfill();

        // Two statements, in this order. A unique index over a column that
        // still holds NULLs would be accepted — MySQL permits repeated NULLs
        // under one — and the NOT NULL is what makes the index mean every
        // service has exactly one code.
        Schema::table('tbl_services', function (Blueprint $table) {
            $table->string('code', 50)->nullable(false)->change();
        });

        Schema::table('tbl_services', function (Blueprint $table) {
            $table->unique('code', 'tbl_services_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_services', function (Blueprint $table) {
            $table->dropUnique('tbl_services_code_unique');
            $table->dropColumn('code');
        });
    }

    /**
     * Existing rows get a slug of their current name. Collisions abort the
     * migration: two services whose names slugify the same are a naming
     * problem for a person to resolve, and disambiguating them here — a -2
     * suffix, say — would mint a permanent identifier out of an accident of
     * insertion order.
     */
    private function backfill(): void
    {
        $services = DB::table('tbl_services')->orderBy('service_id')->get(['service_id', 'service_name', 'code']);

        // Two passes. Codes a previous failed run already wrote are claimed
        // first, so a row further down the table that slugifies onto one of
        // them is reported here rather than blowing up as a raw duplicate-key
        // error when the unique index goes on.
        $seen = [];

        foreach ($services as $service) {
            if (is_string($service->code) && $service->code !== '') {
                $seen[$service->code] = $service->service_id;
            }
        }

        $collisions = [];
        $empty = [];

        foreach ($services as $service) {
            if (is_string($service->code) && $service->code !== '') {
                continue;
            }

            $code = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($service->service_name)), '-');

            if ($code === '') {
                $empty[] = 'service_id ' . $service->service_id . ' ("' . $service->service_name . '")';
                continue;
            }

            if (isset($seen[$code])) {
                $collisions[] = '"' . $code . '" from service_id ' . $seen[$code] . ' and ' . $service->service_id;
                continue;
            }

            $seen[$code] = $service->service_id;

            DB::table('tbl_services')->where('service_id', $service->service_id)->update(['code' => $code]);
        }

        if ($empty !== []) {
            throw new RuntimeException(
                'Cannot backfill tbl_services.code: these services have no alphanumeric characters in their '
                . 'name, so they slugify to an empty string. Rename them, then migrate again. '
                . implode('; ', $empty)
            );
        }

        if ($collisions !== []) {
            throw new RuntimeException(
                'Cannot backfill tbl_services.code: these services slugify to the same code. '
                . 'Rename one of each pair, then migrate again. ' . implode('; ', $collisions)
            );
        }
    }
};
