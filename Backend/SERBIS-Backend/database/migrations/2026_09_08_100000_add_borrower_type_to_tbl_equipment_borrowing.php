<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the item is actually for: the account holder, or a group they are
 * borrowing on behalf of.
 *
 * A barangay hall borrowing ten rubber boats for a drill and a household
 * borrowing one were the same record, so the office could not tell an
 * institutional loan from a personal one, and the quantity was the only hint.
 *
 * There are two values, not three. `resident_id` is a required non-nullable FK,
 * so every borrower is already an account holder — an 'Individual' value would
 * be indistinguishable in the data from 'Resident'. Representing a genuine
 * non-resident walk-in means making `resident_id` nullable, which is a larger
 * change and only worth making if MDRRMO asks to serve non-residents. Nothing
 * here assumes they will.
 *
 * NOT NULL with a 'Resident' default, for the same reason
 * `fulfillment_method` defaults to 'Pickup': every row filed before this column
 * existed genuinely was a resident borrowing for themselves, because that was
 * the only thing the system could record. Defaulting them states a fact rather
 * than inventing one, so no data cleanup follows this migration.
 *
 * `organization_name` is free text and deliberately not a FK. There is no
 * organizations table and this does not create one — a real table would need an
 * owner, a lifecycle and someone to maintain it, and none of that has been
 * asked for. The cost is accepted knowingly: 'Brgy San Antonio SK' and 'SK San
 * Antonio' are two different organisations to this column forever, and if a
 * real table is ever added, matching these strings to it is a cleanup job.
 * Nullable, because a resident borrowing for themselves has no organisation to
 * record; EquipmentBorrowingController::store() requires it when the type is
 * Organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->enum('borrower_type', ['Resident', 'Organization'])
                ->default('Resident')
                ->after('delivery_address');

            $table->string('organization_name', 150)->nullable()->after('borrower_type');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn(['borrower_type', 'organization_name']);
        });
    }
};
