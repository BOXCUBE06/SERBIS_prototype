<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the borrower takes delivery of the item: collecting it from the MDRRMO
 * office, or having it brought to them.
 *
 * Until now there was one way, and it was never written down — every request
 * meant "come and get it", so the office had nothing to plan a run around and a
 * resident who could not travel had no way to say so.
 *
 * `fulfillment_method` is NOT NULL with a 'Pickup' default, unlike `purpose`
 * which was left nullable. The difference is what a backfill would be claiming:
 * every row filed before this column existed genuinely was a pickup, because
 * that was the only thing the system could do, so defaulting them states a fact.
 * Backfilling a purpose would have been inventing a resident's words.
 *
 * `delivery_address` is where to bring it. It has to live on the borrowing row
 * rather than being read off the account, because `tbl_residents` has no address
 * column at all — a resident record carries a barangay and nothing finer, which
 * is not something a driver can deliver to. Nullable, because a pickup has no
 * address to record; EquipmentBorrowingController::store() requires it when the
 * method is Delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->enum('fulfillment_method', ['Pickup', 'Delivery'])
                ->default('Pickup')
                ->after('purpose');

            $table->string('delivery_address', 255)->nullable()->after('fulfillment_method');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_method', 'delivery_address']);
        });
    }
};
