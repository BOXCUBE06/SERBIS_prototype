<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who an account belongs to: a head of the family, a barangay hall, or an
 * organization (a school, PNP, BFP, another council stakeholder).
 *
 * NOT NULL with a 'head_of_family' default for the same reason
 * `borrower_type` defaults to 'Resident': every row that exists today genuinely
 * is a head of the family, because that was the only account the system could
 * hold. Defaulting them states a fact, so no data cleanup follows.
 *
 * `first_name` and `last_name` stay NOT NULL and carry the contact person for a
 * barangay or organization account. `barangay_id` is the affiliation: the
 * barangay itself for a barangay account, where the group is based for an
 * organization. `organization_name` is only meaningful for the third type and
 * is free text, like the same column on tbl_equipment_borrowing.
 *
 * The values are lowercase snake_case on purpose, unlike `status`: this is a
 * closed vocabulary that clients switch on, not a label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->enum('account_type', ['head_of_family', 'barangay', 'organization'])
                ->default('head_of_family')
                ->after('status');

            $table->string('organization_name', 150)->nullable()->after('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'organization_name']);
        });
    }
};
