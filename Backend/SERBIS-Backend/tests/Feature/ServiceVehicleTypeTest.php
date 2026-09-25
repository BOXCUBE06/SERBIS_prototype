<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\ServiceVehicleType;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AssignsResponders;
use Tests\TestCase;

/**
 * Which kinds of unit may be sent on which service. The seeded mapping is the
 * office's own (road clearing takes a Rescue Vehicle, animal rescue a Rescue
 * Vehicle or a Boat, ...); the panel can change it without a
 * deploy, and PUT /service-requests/{id} enforces it whatever the picker shows.
 */
class ServiceVehicleTypeTest extends TestCase
{
    use AssignsResponders, RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $roadClearing;

    private Service $powerLine;

    private Service $program;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->roadClearing = Service::create(['service_name' => 'Road Clearing', 'description' => 'x', 'category' => 'infrastructure']);
        $this->powerLine = Service::create(['service_name' => 'Power Line Repair', 'description' => 'x', 'category' => 'infrastructure']);
        $this->program = Service::create(['service_name' => 'DRRM Trainings and Seminars', 'description' => 'x', 'category' => 'programs']);
    }

    private function unit(string $type, string $id): Vehicle
    {
        return Vehicle::create(['unit_identifier' => $id, 'type' => $type, 'status' => 'Available']);
    }

    private function request(Service $service): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $service->getKey(),
            'description' => 'Fallen tree across the road',
            'status' => 'Pending',
        ]);
    }

    private function dispatch(ServiceRequest $request, Vehicle $unit)
    {
        $this->assignResponder($request);

        return $this->actingAs($this->admin)->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Responding',
            'vehicle_id' => $unit->vehicle_id,
        ]);
    }

    // ------------------------------------------------------------- seeding

    public function test_the_migration_seeds_the_offices_mapping(): void
    {
        $this->assertSame(['Rescue Vehicle'], ServiceVehicleType::typesFor('road-clearing'));
        $this->assertSame(['Rescue Vehicle'], ServiceVehicleType::typesFor('debris-removal'));
        $this->assertSame(['Rescue Vehicle'], ServiceVehicleType::typesFor('sandbagging'));
        $this->assertSame(['Rescue Vehicle'], ServiceVehicleType::typesFor('relief-goods-distribution'));
        $this->assertEqualsCanonicalizing(['Rescue Vehicle', 'Boat'], ServiceVehicleType::typesFor('animal-rescue'));
        $this->assertSame([], ServiceVehicleType::typesFor('power-line-repair'));
    }

    // --------------------------------------------------------- enforcement

    public function test_a_mapped_unit_type_is_accepted(): void
    {
        $rescue = $this->unit('Rescue Vehicle', 'RES-01');
        $request = $this->request($this->roadClearing);

        $this->dispatch($request, $rescue)->assertOk();

        $this->assertSame($rescue->vehicle_id, $request->fresh()->vehicle_id);
    }

    public function test_an_unmapped_unit_type_is_refused_and_nothing_changes(): void
    {
        $boat = $this->unit('Boat', 'BOT-01');
        $request = $this->request($this->roadClearing);

        $this->dispatch($request, $boat)
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);
        $this->assertSame('Pending', $request->fresh()->status);
        $this->assertSame('Available', $boat->fresh()->status);
    }

    public function test_a_service_with_no_mapping_takes_any_non_ambulance_unit(): void
    {
        $fireTruck = $this->unit('Fire Truck', 'FTR-01');
        $request = $this->request($this->powerLine);

        $this->dispatch($request, $fireTruck)->assertOk();
    }

    public function test_an_ambulance_is_still_refused_on_a_non_ambulance_service(): void
    {
        $ambulance = $this->unit('Ambulance', 'AMB-01');
        $request = $this->request($this->powerLine);

        $this->dispatch($request, $ambulance)->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    }

    public function test_a_program_takes_no_vehicle(): void
    {
        $rescue = $this->unit('Rescue Vehicle', 'RES-01');
        $request = $this->request($this->program);

        $this->dispatch($request, $rescue)->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $this->assertNull($request->fresh()->vehicle_id);
    }

    public function test_a_changed_mapping_takes_effect_at_once(): void
    {
        $boat = $this->unit('Boat', 'BOT-01');
        $request = $this->request($this->roadClearing);

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/service-vehicle-types/road-clearing', ['vehicle_types' => ['Boat']])->assertOk();

        $this->dispatch($request, $boat)->assertOk();
    }

    // ------------------------------------------------------------ admin API

    public function test_the_list_leaves_out_the_ambulance_service_and_programs(): void
    {
        Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'x', 'category' => 'medical']);

        Sanctum::actingAs($this->admin);

        $codes = collect($this->getJson('/api/service-vehicle-types')->assertOk()->json('data'))->pluck('code');

        $this->assertTrue($codes->contains('road-clearing'));
        $this->assertFalse($codes->contains('ambulance-medical-response'));
        $this->assertFalse($codes->contains('drrm-trainings-and-seminars'));
    }

    public function test_the_list_offers_every_type_but_ambulance(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/service-vehicle-types')
            ->assertOk()
            ->assertJsonPath('types', ['Rescue Vehicle', 'Fire Truck', 'Boat']);
    }

    public function test_an_empty_list_clears_the_mapping_back_to_any_non_ambulance_unit(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/service-vehicle-types/road-clearing', ['vehicle_types' => []])
            ->assertOk()
            ->assertJsonPath('vehicle_types', []);

        $this->assertSame([], ServiceVehicleType::typesFor('road-clearing'));
    }

    public function test_ambulance_cannot_be_mapped_and_unknown_types_are_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/service-vehicle-types/road-clearing', ['vehicle_types' => ['Ambulance']])
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_types.0');
        $this->putJson('/api/service-vehicle-types/road-clearing', ['vehicle_types' => ['Helicopter']])
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_types.0');
    }

    public function test_an_unknown_service_an_ambulance_service_and_a_program_are_404(): void
    {
        Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'x', 'category' => 'medical']);

        Sanctum::actingAs($this->admin);

        $this->putJson('/api/service-vehicle-types/nope', ['vehicle_types' => ['Boat']])->assertNotFound();
        $this->putJson('/api/service-vehicle-types/ambulance-medical-response', ['vehicle_types' => ['Boat']])->assertNotFound();
        $this->putJson('/api/service-vehicle-types/drrm-trainings-and-seminars', ['vehicle_types' => ['Boat']])->assertNotFound();
    }

    public function test_a_resident_cannot_read_or_change_it(): void
    {
        Sanctum::actingAs($this->resident);

        $this->getJson('/api/service-vehicle-types')->assertForbidden();
        $this->putJson('/api/service-vehicle-types/road-clearing', ['vehicle_types' => ['Boat']])->assertForbidden();
    }

    // ------------------------------------------------------- fleet types

    public function test_only_the_known_kinds_are_valid_fleet_types(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/vehicles', [
            'unit_identifier' => 'RES-09',
            'type' => 'Rescue Vehicle',
            'status' => 'Available',
        ])->assertCreated();

        $this->postJson('/api/vehicles', [
            'unit_identifier' => 'HEL-01',
            'type' => 'Helicopter',
            'status' => 'Available',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }
}
