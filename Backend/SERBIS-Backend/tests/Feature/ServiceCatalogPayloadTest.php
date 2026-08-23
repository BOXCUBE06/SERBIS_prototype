<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Database-backed translations are gone: the app holds the Filipino strings
 * and looks them up by `code`. Nothing asserted the old `name_localized` and
 * `description_localized` keys, so removing them broke no test — which is
 * exactly why they are asserted absent here rather than left uncovered.
 *
 * `?locale=` is no longer read at all. A client that still sends it must get
 * the payload it would have got without it, not a 4xx: the mobile build in the
 * field appends the parameter and cannot be assumed to have updated.
 */
class ServiceCatalogPayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // 'Admin' with a capital A: User::isAdmin() compares against that exact
        // string, and a lowercase fixture would 403 on the route below.
        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_the_services_payload_carries_no_localized_keys(): void
    {
        Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Clearing blocked roads.',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'road-clearing')
            ->assertJsonPath('data.0.service_name', 'Road Clearing')
            ->assertJsonPath('data.0.description', 'Clearing blocked roads.')
            ->assertJsonMissingPath('data.0.name_localized')
            ->assertJsonMissingPath('data.0.description_localized');
    }

    public function test_a_locale_query_parameter_is_ignored_rather_than_rejected(): void
    {
        Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Clearing blocked roads.',
        ]);

        Sanctum::actingAs($this->admin);

        $plain = $this->getJson('/api/services')->assertOk()->json();

        // Both a real subtag and a malformed one: the old localeFrom() told
        // them apart, and nothing downstream should care any more.
        foreach (['fil', 'not-a-locale'] as $locale) {
            $this->getJson('/api/services?locale=' . $locale)
                ->assertOk()
                ->assertExactJson($plain);
        }
    }

    public function test_the_translations_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('tbl_service_translations'));
    }
}
