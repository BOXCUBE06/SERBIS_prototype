<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phone columns are stored as +639XXXXXXXXX now (see StoresCanonicalPhones).
 * Rows saved before that hold 09XXXXXXXXX or 639XXXXXXXXX; only values that
 * match one of those exactly are rewritten. Anything else (landlines, free
 * text) is left alone.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'tbl_residents' => 'phone_number',
        'tbl_user' => 'phone_number',
        'tbl_responders' => 'contact_no',
        'tbl_service_request' => 'walk_in_contact_number',
        'tbl_ambulance_bookings' => 'patient_contact_number',
        'tbl_conduction_requests' => 'patient_contact_number',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            DB::table($table)->whereRaw("`$column` REGEXP '^09[0-9]{9}$'")
                ->update([$column => DB::raw("CONCAT('+63', SUBSTRING(`$column`, 2))")]);
            DB::table($table)->whereRaw("`$column` REGEXP '^639[0-9]{9}$'")
                ->update([$column => DB::raw("CONCAT('+', `$column`)")]);
        }
    }

    public function down(): void {}
};
