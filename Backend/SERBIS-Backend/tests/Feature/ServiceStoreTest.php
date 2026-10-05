<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adding a service from Manage Services: it starts switched off, so it cannot
 * reach residents before staff set its audience, and a name that collides
 * with another service's code is a field error, not a 500.
 */
class ServiceStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]));
    }

    public function test_a_new_service_is_created_switched_off_with_a_code(): void
    {
        $this->postJson('/api/services', [
            'service_name' => 'Tarpaulin Lending',
            'description' => 'Tarpaulins for damaged roofs.',
            'category' => 'relief',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'tarpaulin-lending')
            ->assertJsonPath('category', 'relief')
            ->assertJsonPath('is_active', false);

        $this->assertFalse(Service::where('code', 'tarpaulin-lending')->firstOrFail()->is_active);
    }

    public function test_a_client_cannot_create_it_switched_on(): void
    {
        $this->postJson('/api/services', ['service_name' => 'Tarpaulin Lending', 'is_active' => true])->assertCreated();

        $this->assertFalse(Service::where('code', 'tarpaulin-lending')->firstOrFail()->is_active);
    }

    public function test_a_resident_does_not_see_the_new_service_until_it_is_switched_on(): void
    {
        $this->postJson('/api/services', ['service_name' => 'Tarpaulin Lending', 'category' => 'relief'])->assertCreated();
        $service = Service::where('code', 'tarpaulin-lending')->firstOrFail();

        $resident = new Resident([
            'barangay_id' => Barangay::create(['barangay_name' => 'San Miguel'])->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        $resident->save();
        Sanctum::actingAs($resident);

        $this->getJson('/api/services')->assertOk()->assertJsonMissing(['code' => 'tarpaulin-lending']);

        $service->update(['is_active' => true]);

        $this->getJson('/api/services')->assertOk()->assertJsonFragment(['code' => 'tarpaulin-lending']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function collidingNames(): array
    {
        return [
            'same name' => ['Road Clearing'],
            'other case' => ['road clearing'],
            'extra spaces' => ['  Road   Clearing '],
            'punctuation' => ['Road-Clearing!'],
            'all caps' => ['ROAD_CLEARING'],
        ];
    }

    #[DataProvider('collidingNames')]
    public function test_a_name_that_differs_only_by_case_spacing_or_punctuation_is_a_field_error(string $name): void
    {
        Service::create(['service_name' => 'Road Clearing', 'category' => 'infrastructure']);

        $this->postJson('/api/services', ['service_name' => $name, 'category' => 'infrastructure'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_name' => 'A service with this name already exists: Road Clearing.']);

        $this->assertSame(1, Service::count());
    }

    public function test_a_renamed_service_is_still_matched_by_its_current_name(): void
    {
        // Renamed before names were locked: the code is the old one.
        Service::create(['service_name' => 'Old Name', 'category' => 'relief'])->forceFill(['service_name' => 'Flood Pumping'])->save();

        $this->postJson('/api/services', ['service_name' => 'flood pumping'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_name' => 'A service with this name already exists: Flood Pumping.']);
    }

    public function test_the_names_of_the_app_choices_are_refused(): void
    {
        $this->postJson('/api/services', ['service_name' => 'Others'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_name');

        $this->postJson('/api/services', ['service_name' => 'Equipment borrowing'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_name');
    }

    public function test_a_name_with_no_letters_or_numbers_is_a_field_error_not_a_500(): void
    {
        $this->postJson('/api/services', ['service_name' => '— / —'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_name' => 'The name needs at least one letter or number.']);
    }

    public function test_a_new_service_cannot_be_a_program(): void
    {
        $this->postJson('/api/services', ['service_name' => 'Flood Drill Kits', 'category' => 'programs'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category' => 'A new service cannot be a Program: programs need their own form in the app.']);

        $this->assertSame(0, Service::count());
    }

    public function test_an_existing_program_can_still_be_edited_and_switched(): void
    {
        $program = Service::create(['service_name' => 'DRRM Trainings and Seminars', 'category' => 'programs']);

        $this->putJson("/api/services/{$program->service_id}", ['description' => 'Seminars for barangays.', 'is_active' => false, 'category' => 'programs'])
            ->assertOk()
            ->assertJsonPath('category', 'programs');

        $this->assertSame('programs', $program->fresh()->category);
    }

    public function test_the_filipino_name_is_optional_stored_returned_and_editable(): void
    {
        $id = $this->postJson('/api/services', ['service_name' => 'Tarpaulin Lending', 'service_name_fil' => 'Pagpapahiram ng Trapal'])
            ->assertCreated()
            ->assertJsonPath('service_name_fil', 'Pagpapahiram ng Trapal')
            ->json('service_id');

        $this->getJson('/api/services')->assertOk()->assertJsonPath('data.0.service_name_fil', 'Pagpapahiram ng Trapal');

        $this->putJson("/api/services/{$id}", ['service_name_fil' => 'Hiraman ng Trapal'])
            ->assertOk()
            ->assertJsonPath('service_name_fil', 'Hiraman ng Trapal');

        $this->putJson("/api/services/{$id}", ['service_name_fil' => null])->assertOk();
        $this->assertNull(Service::find($id)->service_name_fil);

        $this->postJson('/api/services', ['service_name' => 'Rope Lending'])
            ->assertCreated()
            ->assertJsonPath('service_name_fil', null);

        $this->postJson('/api/services', ['service_name' => 'Boat Lending', 'service_name_fil' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_name_fil');
    }

    public function test_a_different_name_is_accepted(): void
    {
        Service::create(['service_name' => 'Road Clearing', 'category' => 'infrastructure']);

        $this->postJson('/api/services', ['service_name' => 'Road Clearing Crew', 'category' => 'infrastructure'])
            ->assertCreated()
            ->assertJsonPath('code', 'road-clearing-crew');
    }
}
