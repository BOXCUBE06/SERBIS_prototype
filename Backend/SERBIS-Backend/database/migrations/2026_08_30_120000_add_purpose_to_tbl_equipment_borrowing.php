<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the resident is borrowing the item *for*.
 *
 * A borrowing arrived as an item and a number and nothing else, so the only
 * thing MDRRMO could weigh when approving or denying was whether the stock
 * happened to be on the shelf. Two requests for the same three lifejackets —
 * one for a barangay drill, one for a weekend trip — were indistinguishable at
 * the point of decision, and the denial reason column could only explain the
 * refusal after the fact.
 *
 * Nullable even though `store()` requires it: every row filed before this
 * column existed has no purpose to supply, and backfilling one would be
 * inventing a resident's words. The panel renders those as "No purpose was
 * recorded" rather than an empty box. 255 to match `denial_reason` — this is a
 * line explaining a request, not an attachment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->string('purpose', 255)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
