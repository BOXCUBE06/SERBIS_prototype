<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things a borrowing record could never answer.
 *
 * `due_date` — the table timestamped `released_at` and `returned_at` but never
 * recorded when an item was expected back, so "is this return late?" had no
 * definition anywhere in the system. The admin panel could not show an overdue
 * state because there was nothing to compare against, and the only alternative
 * was for the frontend to invent a loan period and present the result as fact.
 * Nullable because every existing row predates the column and a borrowed item
 * with no agreed return date is a real state, not a data error — the panel
 * renders those as "No due date" rather than guessing one.
 *
 * `denial_reason` — denying a request recorded the refusal and nothing else.
 * The resident is told no with no explanation, and staff reviewing the history
 * later cannot tell a stock shortage from an ineligible request. Kept short and
 * nullable: it is a one-line note, not a correspondence thread, and denials
 * made before this column existed have no reason to supply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('quantity');
            $table->string('denial_reason', 255)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'denial_reason']);
        });
    }
};
