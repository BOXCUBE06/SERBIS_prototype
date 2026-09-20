<?php

use App\Support\PhoneBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The phone number is the login for a resident account, so it has to identify
 * exactly one of them and be stored in exactly one form: +639XXXXXXXXX.
 *
 * Refuses to run, changing nothing, when a row cannot be made canonical (blank,
 * a landline, malformed) or two rows would end up with the same number. That is
 * deliberate: a deploy runs migrations at boot, and silently picking a winner
 * between two accounts, or blanking one, is not a decision a migration should
 * make. The message names resident ids, not numbers — the deploy log is not a
 * place for personal data. Production was checked by hand on 2026-09-20 and had
 * neither problem.
 *
 * Rewrites go through the query builder, not the model: nothing here should
 * appear in tbl_system_logs as if a person edited 20 accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plan = PhoneBackfill::plan(DB::table('tbl_residents')->pluck('phone_number', 'resident_id')->all());

        if ($plan['invalid'] !== [] || $plan['duplicates'] !== []) {
            throw new RuntimeException(PhoneBackfill::describe($plan));
        }

        foreach ($plan['updates'] as $id => $canonical) {
            DB::table('tbl_residents')->where('resident_id', $id)->update(['phone_number' => $canonical]);
        }

        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->unique('phone_number');
        });
    }

    /**
     * Drops the index only. The numbers stay canonical: the original spellings
     * ("0917…" against "+63917…") were never recorded, and the canonical form is
     * accepted everywhere the old ones were.
     */
    public function down(): void
    {
        Schema::table('tbl_residents', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
        });
    }
};
