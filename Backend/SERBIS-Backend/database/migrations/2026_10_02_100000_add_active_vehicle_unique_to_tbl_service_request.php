<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One vehicle, at most one Responding request — enforced by the schema, behind
 * VehicleDispatch::lockFree(). active_vehicle_id is vehicle_id while the row is
 * Responding and NULL otherwise; a unique index allows any number of NULLs.
 *
 * The vehicle_id FK goes from SET NULL to RESTRICT first: MySQL refuses SET
 * NULL / CASCADE on a base column of a stored generated column. Deleting a
 * unit any request still references is refused by VehicleController::destroy().
 *
 * No nullable(): MariaDB rejects an explicit NULL on a generated column, and
 * Laravel adds no modifier unless asked. Pre-checked like 2026_08_31_150000,
 * so a dirty table aborts with ids instead of a raw 1062.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoSharedUnits();

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->foreign('vehicle_id')->references('vehicle_id')->on('tbl_vehicles')->restrictOnDelete();
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->unsignedBigInteger('active_vehicle_id')
                ->storedAs("CASE WHEN status = 'Responding' THEN vehicle_id END")
                ->unique('tbl_service_request_active_vehicle_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropUnique('tbl_service_request_active_vehicle_unique');
            $table->dropColumn('active_vehicle_id');
        });

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->foreign('vehicle_id')->references('vehicle_id')->on('tbl_vehicles')->nullOnDelete();
        });
    }

    private function assertNoSharedUnits(): void
    {
        $shared = DB::table('tbl_service_request')
            ->where('status', 'Responding')
            ->whereNotNull('vehicle_id')
            ->select('vehicle_id', DB::raw('COUNT(*) as request_count'))
            ->groupBy('vehicle_id')
            ->having('request_count', '>', 1)
            ->get();

        if ($shared->isEmpty()) {
            return;
        }

        $described = $shared->map(fn ($row) => "vehicle_id {$row->vehicle_id} (requests: "
            .DB::table('tbl_service_request')
                ->where('status', 'Responding')
                ->where('vehicle_id', $row->vehicle_id)
                ->orderBy('request_id')
                ->pluck('request_id')
                ->implode(', ').')'
        )->implode('; ');

        throw new RuntimeException(
            'Cannot add the unique index on tbl_service_request.active_vehicle_id: '
            .'these vehicles are held by more than one Responding request. Decide which '
            .'request keeps each unit, resolve or reassign the rest, then migrate again. '
            .$described
        );
    }
};
