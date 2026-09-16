<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the office first answered a request, and when the request ended.
 *
 * tbl_service_request has only created_at and updated_at, and updated_at is
 * rewritten by any later edit — a note added to a closed request moves it —
 * so there was no column anywhere that could answer "how long did this take".
 * The audit log in tbl_system_logs does record status transitions, but it
 * only starts 2026-08-11, loses its rows when a request is deleted, and
 * stores the status inside a JSON blob in a longtext, which is neither
 * indexable nor portable to query.
 *
 * Both columns are nullable and stay null when the event never happened,
 * which is a real state rather than a gap to paper over:
 *
 * - first_responded_at is null while a request is still untouched in the
 *   queue, and stays null forever on a request that was created already
 *   Booked (ServiceRequestController::store/adminStore both write that for a
 *   scheduled booking) — such a request never sat in Pending, so there is no
 *   triage delay to measure and stamping one would invent a zero.
 * - resolved_at is null until the request reaches Resolved, Cancelled or
 *   Disapproved.
 *
 * Charts that read these must exclude nulls and publish their sample size;
 * the backfill covers only what the audit log still holds.
 *
 * No index. Every analytics filter keys on created_at, which is already
 * indexed here and on the composite (status, created_at); these two columns
 * are selected, never filtered on. Adding an index nothing reads is a write
 * cost with no reader — revisit if a query plan ever asks for one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dateTime('first_responded_at')->nullable()->after('status');
            $table->dateTime('resolved_at')->nullable()->after('first_responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn(['first_responded_at', 'resolved_at']);
        });
    }
};
