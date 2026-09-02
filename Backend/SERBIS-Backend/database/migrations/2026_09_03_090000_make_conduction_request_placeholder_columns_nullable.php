<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * createConductionStub() (ServiceRequestController::createConductionStub)
 * used to write literal placeholder text — 'Address not specified',
 * 'Not described', 'destination not specified' — into these NOT NULL
 * columns whenever a booking had no address, diagnosis or destination.
 * Printed on the signed conduction-request.blade.php form, that text reads
 * as if someone typed it there.
 *
 * Making the columns nullable only changes what the stub writes going
 * forward — every row it already wrote still carries the exact placeholder
 * string, so this also nulls out any row that still holds one of them.
 * A plain string match, not a LIKE: these four literals are the only text
 * the stub ever wrote for a blank field, so an exact match cannot catch a
 * genuine value that happens to read the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_conduction_requests', function (Blueprint $table) {
            $table->string('patient_address')->nullable()->change();
            $table->text('medical_diagnosis')->nullable()->change();
            $table->string('origin')->nullable()->change();
            $table->string('destination')->nullable()->change();
        });

        DB::table('tbl_conduction_requests')->where('patient_address', 'Address not specified')->update(['patient_address' => null]);
        DB::table('tbl_conduction_requests')->where('origin', 'Address not specified')->update(['origin' => null]);
        DB::table('tbl_conduction_requests')->where('medical_diagnosis', 'Not described')->update(['medical_diagnosis' => null]);
        DB::table('tbl_conduction_requests')->where('destination', 'destination not specified')->update(['destination' => null]);
    }

    /**
     * Not reversed: the rows up() nulled out never held real data, only the
     * stub's placeholder text, so there is nothing to restore. Re-tightening
     * these columns to NOT NULL here would also fail outright on any row
     * up() just nulled.
     */
    public function down(): void
    {
        //
    }
};
