<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tables that have never held a row and that no code reads or writes.
 *
 * `tbl_disaster` was a disaster taxonomy named in the original brief. Its only
 * referent is `tbl_sms_logs.disaster_id`, which was made nullable on
 * 2026-08-03 rather than invent that taxonomy to satisfy a NOT NULL foreign
 * key; SmsController has hardcoded `null` there ever since. The constraint
 * stayed behind and is what makes the table undroppable, so it goes first.
 *
 * `users` is Laravel's default table. Admins live in `tbl_user` — App\Models\User
 * sets `protected $table = 'tbl_user'` — so the `users` *provider* in
 * config/auth.php never touches the `users` *table*.
 *
 * The `disaster_id` COLUMN stays. SmsController::advisories() still names it in
 * its select list and the mobile advisory fixture still expects it in the
 * payload; dropping it is a four-file change against a shipped client contract,
 * not schema housekeeping. Without the constraint it is an ordinary nullable
 * bigint that nothing enforces.
 *
 * `password_reset_tokens` is NOT dropped even though it is equally empty:
 * config/auth.php still declares it as the `users` broker's token table.
 *
 * 0001_01_01_000000_create_users_table.php is left alone — it also creates
 * `sessions`, which is live under SESSION_DRIVER=database. On a `migrate:fresh`
 * that migration creates `users` and this one drops it again, which is the
 * correct ordering and costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The constraint must go before the table it points at; MariaDB and
        // MySQL both refuse to drop a table a foreign key still references.
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->dropForeign('tbl_sms_logs_disaster_id_foreign');
        });

        Schema::dropIfExists('tbl_disaster');
        Schema::dropIfExists('users');
    }

    public function down(): void
    {
        Schema::create('tbl_disaster', function (Blueprint $table) {
            $table->id('disaster_id');
            $table->string('disaster_name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // Re-addable only because every disaster_id is null — the column has
        // never been written with a value. A row pointing at a disaster that no
        // longer exists would fail this, which is the constraint doing its job.
        Schema::table('tbl_sms_logs', function (Blueprint $table) {
            $table->foreign('disaster_id', 'tbl_sms_logs_disaster_id_foreign')
                ->references('disaster_id')
                ->on('tbl_disaster');
        });
    }
};
