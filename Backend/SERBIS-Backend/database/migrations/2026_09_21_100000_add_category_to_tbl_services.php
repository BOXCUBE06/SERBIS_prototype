<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real category for each service.
 *
 * Until now the admin panel worked a category out from the service's display
 * name with a handful of keyword patterns, and anything that matched nothing
 * fell into Relief. An admin renaming "Road Clearing" to "Street Works" moved
 * it to Relief without any sign it had happened. The category is now a column
 * the office sets in Manage Services.
 *
 * The values are the five the panel already showed. Existing rows are
 * backfilled by running the panel's own keyword patterns once, in the panel's
 * own order, so every service keeps the category it was displayed under and
 * nothing changes on screen. The patterns are copied here rather than shared:
 * a migration records what the schema did on the day it ran, and must not move
 * when a later edit changes them.
 *
 * NOT NULL with a 'relief' default, matching the panel's old fallback, so a
 * service created any other way (a seeder, a test) still has a category.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tbl_services', 'category')) {
            Schema::table('tbl_services', function (Blueprint $table) {
                $table->enum('category', ['rescue', 'medical', 'relief', 'infrastructure', 'programs'])
                    ->default('relief')
                    ->after('description');
            });
        }

        foreach (DB::table('tbl_services')->get(['service_id', 'service_name']) as $service) {
            DB::table('tbl_services')
                ->where('service_id', $service->service_id)
                ->update(['category' => $this->categoryFor((string) $service->service_name)]);
        }
    }

    public function down(): void
    {
        Schema::table('tbl_services', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * The panel's ServicesConfigView.categoryOf(), as it stood: first match
     * wins, programs first, then medical, rescue, infrastructure, else relief.
     */
    public function categoryFor(string $name): string
    {
        $n = strtolower($name);

        return match (true) {
            (bool) preg_match('/(training|seminar|drill|nsed|certif)/', $n) => 'programs',
            (bool) preg_match('/(medical|ambulance|health|first aid)/', $n) => 'medical',
            (bool) preg_match('/(rescue|evacuat|search|fire|sandbag)/', $n) => 'rescue',
            (bool) preg_match('/(road|power|line|debris|clearing|repair|water|infrastructure)/', $n) => 'infrastructure',
            default => 'relief',
        };
    }
};
