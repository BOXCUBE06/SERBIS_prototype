<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * What an API client is told when a request fails: no class names, route
 * echoes, SQL or file paths. Rendering only, so validation errors and the
 * messages controllers write on purpose are unchanged (asserted below).
 *
 * Debug is forced off: local development keeps the detailed pages, and
 * production refuses to boot with it on (ProductionDebugGuardTest).
 */
class ApiErrorRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        Sanctum::actingAs(User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]));
    }

    public function test_a_missing_model_names_no_class(): void
    {
        $response = $this->deleteJson('/api/barangays/99999999');

        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    public function test_an_unknown_route_does_not_echo_the_path(): void
    {
        $this->getJson('/api/no-such-thing')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_a_server_error_returns_the_generic_message_only(): void
    {
        Route::get('/api/_boom', fn () => throw new RuntimeException('SQLSTATE[42S02]: Base table tbl_secret not found'));

        $response = $this->getJson('/api/_boom');

        $response->assertStatus(500)->assertExactJson(['message' => 'Something went wrong. Please try again.']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('tbl_secret', $response->getContent());
    }

    public function test_validation_errors_are_unchanged(): void
    {
        $this->postJson('/api/barangays', [])->assertUnprocessable()->assertJsonValidationErrors('barangay_name');
    }

    public function test_a_message_a_controller_wrote_on_purpose_is_kept(): void
    {
        $this->getJson('/api/vehicles/99999999')->assertNotFound()->assertExactJson(['message' => 'Vehicle not found']);
    }
}
