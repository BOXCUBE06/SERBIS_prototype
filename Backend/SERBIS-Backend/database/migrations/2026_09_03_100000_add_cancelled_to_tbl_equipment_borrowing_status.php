<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A resident can now withdraw a borrow request they filed, the same way they
// can withdraw a service request. tbl_equipment_borrowing.status is a MySQL
// enum of five words, so without this the new PATCH /borrowings/{id}/cancel
// route would fail at the write with "Data truncated for column 'status'" —
// the controller's transition table alone cannot store a word the column does
// not accept. doctrine/dbal is not installed, so this is a raw ALTER rather
// than Blueprint::change(), matching the walk-in migration.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE tbl_equipment_borrowing MODIFY status "
            ."ENUM('Pending', 'Approved', 'Released', 'Returned', 'Denied', 'Cancelled') "
            ."NOT NULL DEFAULT 'Pending'"
        );
    }

    public function down(): void
    {
        // Narrowing the enum with Cancelled rows still in the table would
        // truncate each of them to an empty string, which reads as neither
        // open nor closed and would put them back in the pipeline as an
        // unknown status. They are moved to the nearest word that survives
        // instead, and the reason column says what actually happened so the
        // rollback is not silently rewriting history.
        DB::table('tbl_equipment_borrowing')
            ->where('status', 'Cancelled')
            ->update([
                'status' => 'Denied',
                'denial_reason' => 'Withdrawn by the resident before pickup.',
            ]);

        DB::statement(
            "ALTER TABLE tbl_equipment_borrowing MODIFY status "
            ."ENUM('Pending', 'Approved', 'Released', 'Returned', 'Denied') "
            ."NOT NULL DEFAULT 'Pending'"
        );
    }
};
