<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Adviser asked for walk-in requests: someone who shows up at the office in
// person, with or without a registered account. resident_id therefore has to
// become nullable — a walk-in with no account is identified instead by
// walk_in_name/walk_in_contact_number, filled in by whichever staffer took
// the request. doctrine/dbal is not installed, so the nullability change is
// a raw ALTER rather than Blueprint::change().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->string('walk_in_name')->nullable()->after('resident_id');
            $table->string('walk_in_contact_number')->nullable()->after('walk_in_name');
        });

        DB::statement('ALTER TABLE tbl_service_request MODIFY resident_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tbl_service_request MODIFY resident_id BIGINT UNSIGNED NOT NULL');

        Schema::table('tbl_service_request', function (Blueprint $table) {
            $table->dropColumn(['walk_in_name', 'walk_in_contact_number']);
        });
    }
};
