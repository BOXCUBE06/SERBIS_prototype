<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the request last moved to its current status. The dashboard's "stale"
 * list needs it: a request Responding for six days is stale, one filed six
 * days ago and answered an hour ago is not, and created_at cannot tell them
 * apart.
 *
 * tbl_system_logs also records status changes, but only for writes that go
 * through Eloquent, and its JSON would have to be searched per request. A
 * column written by ServiceRequest::stampLifecycle() is one read.
 *
 * Null means "never moved since it was filed"; readers fall back to
 * created_at. Existing rows are backfilled from updated_at, which is the
 * last write of any kind and so can only make a row look fresher than it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable();
        });

        DB::table('tbl_service_request')->update(['status_changed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
