<?php

namespace Tests\Feature;

use Database\Seeders\ProductionAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
