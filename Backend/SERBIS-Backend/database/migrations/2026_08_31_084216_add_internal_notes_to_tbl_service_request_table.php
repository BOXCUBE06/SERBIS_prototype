<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `remarks` has always done two jobs at once: the resident-facing rejection
// reason / approval note (read by the Flutter app as `note`, sometimes
// texted via PhilSMS), and the admin panel's own "Admin remarks" scratch
// pad. The panel pre-filled the second from the first, so an operator's
// internal shorthand could reach a resident's phone unedited. This column
// is the operator-only half, split out so `remarks` can go back to meaning
// only "what the requester is told."
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });
    }
};
