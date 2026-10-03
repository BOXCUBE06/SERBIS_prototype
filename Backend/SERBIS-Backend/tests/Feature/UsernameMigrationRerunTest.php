<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_30_110000 re-run after a boot that died half way. Runs its own
 * migrate:fresh (DDL commits implicitly on MySQL, so RefreshDatabase's
 * transaction cannot wrap it), same as AmbulanceBookingsMigrationTest.
 */
class UsernameMigrationRerunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        // Tells the next RefreshDatabase class to migrate fresh for itself.
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_up_completes_when_the_column_exists_and_some_usernames_are_null(): void
    {
        foreach (['kept@serbis.com', 'ana@serbis.com', 'kept@other.test'] as $i => $email) {
            DB::table('tbl_user')->insert([
                'first_name' => 'Staff', 'last_name' => (string) $i, 'role' => 'Admin',
                'username' => "pre{$i}", 'email_address' => $email, 'password' => 'x',
            ]);
        }

        // The state a crash leaves: column there and nullable, no index, only
        // the first row backfilled.
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->dropUnique(['username']);
        });
        Schema::table('tbl_user', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->change();
        });
        DB::table('tbl_user')->where('username', 'pre0')->update(['username' => 'kept']);
        DB::table('tbl_user')->whereIn('username', ['pre1', 'pre2'])->update(['username' => null]);

        (require database_path('migrations/2026_09_30_110000_add_username_to_tbl_user.php'))->up();

        $this->assertSame(
            ['kept', 'ana', 'kept_2'],
            DB::table('tbl_user')->orderBy('admin_id')->pluck('username')->all(),
        );
        $this->assertTrue(Schema::hasIndex('tbl_user', 'tbl_user_username_unique'));
        $this->assertFalse(
            collect(Schema::getColumns('tbl_user'))->firstWhere('name', 'username')['nullable']
        );
    }
}
