<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number to ring about *this patient*, which is not always the number on
 * the account that filed the request.
 *
 * Until now createConductionStub() derived the trip's contact from
 * resident->phone_number ?: walk_in_contact_number — the requester's number.
 * That is the right fallback and stays the fallback, but it is wrong whenever
 * a head of the family files for someone else in the household who is the one
 * actually travelling, which is the case the "head of the family" account
 * model makes common rather than rare.
 *
 * Nullable: every row that exists predates this column, and the intake forms
 * leave it optional. A null here means "no separate number given", and the
 * derivation is what answers instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->string('patient_contact_number', 32)->nullable()->after('patient_address');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn('patient_contact_number');
        });
    }
};
