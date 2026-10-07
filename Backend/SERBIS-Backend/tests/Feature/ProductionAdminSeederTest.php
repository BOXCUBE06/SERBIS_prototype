<?php

namespace Tests\Feature;

use Database\Seeders\ProductionAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The entrypoint runs this seeder on every boot under `set -e`, so a second
 * run that tries to insert again crash-loops the API.
 */
class ProductionAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['ADMIN_SEED_PASSWORD'] = 'SeedPass123!';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['ADMIN_SEED_PASSWORD']);

        parent::tearDown();
    }

    public function test_a_second_run_does_not_insert_again(): void
    {
        $this->seed(ProductionAdminSeeder::class);
        $this->seed(ProductionAdminSeeder::class);

        $this->assertSame(1, DB::table('tbl_user')->count());
        $this->assertSame('jilmarferrer29', DB::table('tbl_user')->value('username'));
    }

    public function test_a_run_after_the_admin_username_changed_does_not_insert_again(): void
    {
        $this->seed(ProductionAdminSeeder::class);
        DB::table('tbl_user')->update(['username' => 'jilmar.ferrer']);

        $this->seed(ProductionAdminSeeder::class);

        $this->assertSame(1, DB::table('tbl_user')->count());
        $this->assertSame('jilmar.ferrer', DB::table('tbl_user')->value('username'));
    }

    public function test_the_password_is_required_only_while_no_admin_exists(): void
    {
        unset($_SERVER['ADMIN_SEED_PASSWORD']);

        try {
            $this->seed(ProductionAdminSeeder::class);
            $this->fail('Expected a RuntimeException with no admin and no password.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_SEED_PASSWORD', $e->getMessage());
        }
        $this->assertSame(0, DB::table('tbl_user')->count());

        DB::table('tbl_user')->insert([
            'first_name' => 'Other', 'last_name' => 'Admin', 'role' => 'Admin', 'status' => 'Active',
            'username' => 'otheradmin', 'email_address' => 'other@example.com', 'password' => 'x',
        ]);

        $this->seed(ProductionAdminSeeder::class);

        $this->assertSame(1, DB::table('tbl_user')->count());
    }
}
