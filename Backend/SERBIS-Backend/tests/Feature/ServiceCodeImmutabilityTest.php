<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `tbl_services.code` is what a client keys behaviour on, so a rename must
 * never move it.
 *
 * Two things hold that line and either one alone would let it through:
 * ServiceController's rules omit `code`, and #[Fillable] on the model omits it
 * too. The rules stop it being validated; the fillable list stops it riding in
 * through the mass assignment even if the rules are ever loosened. The tests
 * below send a `code` anyway and assert the stored value is unchanged.
 *
 * Note that PUT /api/services/{id} sits behind is.admin, so these act as an
 * admin — a resident token gets a 403 before any of this is reached, which
 * would make the assertions pass for the wrong reason.
 */
class ServiceCodeImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // 'Admin' with a capital A: User::isAdmin() compares against that exact
        // string, and a lowercase fixture would 403 on the routes below.
        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_a_new_service_gets_a_code_slugified_from_its_name(): void
    {
        $service = Service::create(['service_name' => 'Ambulance/Medical Response']);

        $this->assertSame('ambulance-medical-response', $service->fresh()->code);
    }

    public function test_updating_a_service_with_a_code_in_the_payload_leaves_the_code_unchanged(): void
    {
        $service = Service::create(['service_name' => 'Road Clearing']);

        $this->assertSame('road-clearing', $service->fresh()->code);

        Sanctum::actingAs($this->admin);

        $this->putJson('/api/services/'.$service->service_id, [
            'description' => 'Debris and obstacles',
            'code' => 'hijacked-code',
        ])->assertOk();

        $service->refresh();

        $this->assertSame('road-clearing', $service->code, 'A code sent in the payload overwrote the stored one.');
        $this->assertSame('Debris and obstacles', $service->description, 'The update itself did not take effect.');
    }

    public function test_a_refused_rename_leaves_name_and_code_unchanged(): void
    {
        $service = Service::create(['service_name' => 'Road Clearing']);

        Sanctum::actingAs($this->admin);

        // Names are locked after creation (ServiceController::update). The
        // regression this still guards: a code re-derived on save would follow
        // a new name and break every client that had stored 'road-clearing'.
        $this->putJson('/api/services/'.$service->service_id, [
            'service_name' => 'Street Clearing',
        ])->assertStatus(422);

        $service->refresh();

        $this->assertSame('Road Clearing', $service->service_name);
        $this->assertSame('road-clearing', $service->code);
    }

    public function test_creating_a_service_through_the_api_with_a_code_ignores_it(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/services', [
            'service_name' => 'Animal Rescue',
            'code' => 'chosen-by-the-client',
        ])->assertCreated();

        $this->assertSame('animal-rescue', Service::where('service_name', 'Animal Rescue')->firstOrFail()->code);
    }

    public function test_the_services_endpoint_returns_the_code(): void
    {
        $service = Service::create(['service_name' => 'Flood Evacuation']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'flood-evacuation')
            ->assertJsonPath('data.0.service_id', $service->service_id);
    }
}
