<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff sign in with a username; no email domain is owned, so the old
     * "name@serbis.com" addresses were usernames in disguise. Each account is
     * backfilled from the part of its email before the @, made to fit the
     * username rule and de-duplicated. The email column stays, nullable, so
     * nothing that still reads it breaks.
     */
    public function up(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('last_name');
        });

        $taken = [];

        foreach (DB::table('tbl_user')->orderBy('admin_id')->get(['admin_id', 'email_address']) as $row) {
            $username = self::uniqueUsername(self::usernameFrom((string) $row->email_address, $row->admin_id), $taken);
            $taken[$username] = true;

            DB::table('tbl_user')->where('admin_id', $row->admin_id)->update(['username' => $username]);

            // So whoever runs the deploy can tell each staff member their new login.
            $line = "Staff #{$row->admin_id}: {$row->email_address} -> {$username}";
            Log::info($line);
            if (! app()->runningUnitTests()) {
                fwrite(STDOUT, "  {$line}\n");
            }
        }

        Schema::table('tbl_user', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->change();
            $table->string('email_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }

    /** Lowercase local part, only a-z 0-9 . _, 3 to 30 characters. */
    public static function usernameFrom(string $email, int $id): string
    {
        $local = strtolower(strstr($email, '@', true) ?: $email);
        $name = substr(preg_replace('/[^a-z0-9._]/', '', $local), 0, 30);

        return strlen($name) >= 3 ? $name : substr($name.'staff'.$id, 0, 30);
    }

    /** Adds _2, _3, … until the name is free, still within 30 characters. */
    public static function uniqueUsername(string $base, array $taken): string
    {
        $candidate = $base;
        for ($n = 2; isset($taken[$candidate]); $n++) {
            $suffix = "_{$n}";
            $candidate = substr($base, 0, 30 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }
};
