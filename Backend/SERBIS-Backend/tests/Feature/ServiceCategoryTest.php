<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A service's category is a column the office sets, not something worked out
 * from its name. The migration backfills it once from the panel's old keyword
 * patterns so nothing moved on the day it shipped.
 */
class ServiceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_the_backfill_keeps_every_current_service_in_the_category_it_was_shown_under(): void
    {
        $migration = require base_path('database/migrations/2026_09_21_100000_add_category_to_tbl_services.php');

        $expected = [
            'Ambulance/Medical Response' => 'medical',
            'Relief Goods Distribution' => 'relief',
            'Road Clearing' => 'infrastructure',
            'Power Line Repair' => 'infrastructure',
            'Debris Removal' => 'infrastructure',
            'Animal Rescue' => 'rescue',
            'Sandbagging' => 'rescue',
            'DRRM Trainings and Seminars' => 'programs',
            'Simulation Drills / NSED' => 'programs',
            'MDRRMO Certification' => 'programs',
            // No pattern matches: the panel's old fallback.
            'Tree Trimming' => 'relief',
        ];

        foreach ($expected as $name => $category) {
            $this->assertSame($category, $migration->categoryFor($name), $name);
        }
    }

    public function test_a_service_created_without_a_category_is_relief(): void
    {
        $service = Service::create(['service_name' => 'Tree Trimming', 'description' => 'x']);

        $this->assertSame('relief', $service->fresh()->category);
    }

    public function test_the_services_api_exposes_the_category_to_admin_and_resident(): void
    {
        Service::create(['service_name' => 'Road Clearing', 'description' => 'x', 'category' => 'infrastructure']);

        $this->actingAs($this->admin)->getJson('/api/services')
            ->assertOk()->assertJsonPath('data.0.category', 'infrastructure');

        $resident = new Resident([
            'barangay_id' => Barangay::create(['barangay_name' => 'San Miguel'])->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        $resident->save();

        $this->actingAs($resident)->getJson('/api/services')
            ->assertOk()->assertJsonPath('data.0.category', 'infrastructure');
    }

    public function test_the_category_is_set_when_adding_and_locked_after(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/services', [
            'service_name' => 'Flood Drill Kits',
            'category' => 'rescue',
        ])->assertStatus(201)->assertJsonPath('category', 'rescue')->json('service_id');

        $this->actingAs($this->admin)->putJson("/api/services/{$id}", ['category' => 'relief'])
            ->assertStatus(422)->assertJsonValidationErrors(['category']);

        // Resending the stored value is not a change.
        $this->actingAs($this->admin)->putJson("/api/services/{$id}", ['category' => 'rescue'])
            ->assertOk()->assertJsonPath('category', 'rescue');

        $this->assertSame('rescue', Service::find($id)->category);
    }

    public function test_the_name_is_locked_after_adding(): void
    {
        $service = Service::create(['service_name' => 'Road Clearing', 'category' => 'infrastructure']);

        $this->actingAs($this->admin)->putJson("/api/services/{$service->service_id}", ['service_name' => 'Road Works'])
            ->assertStatus(422)->assertJsonValidationErrors(['service_name']);

        $this->actingAs($this->admin)->putJson("/api/services/{$service->service_id}", ['service_name' => 'Road Clearing'])
            ->assertOk();

        $this->assertSame('Road Clearing', $service->fresh()->service_name);
    }

    public function test_the_description_can_still_be_edited(): void
    {
        $service = Service::create(['service_name' => 'Road Clearing', 'category' => 'infrastructure', 'description' => 'Old']);

        $this->actingAs($this->admin)->putJson("/api/services/{$service->service_id}", ['description' => 'Fallen trees and debris'])
            ->assertOk()->assertJsonPath('description', 'Fallen trees and debris');

        $this->actingAs($this->admin)->putJson("/api/services/{$service->service_id}", ['description' => null])
            ->assertOk()->assertJsonPath('description', null);
    }

    public function test_an_unknown_category_is_refused(): void
    {
        $this->actingAs($this->admin)->postJson('/api/services', [
            'service_name' => 'Anything',
            'category' => 'office',
        ])->assertStatus(422)->assertJsonValidationErrors(['category']);

        $service = Service::create(['service_name' => 'Road Clearing', 'description' => 'x']);

        $this->actingAs($this->admin)->putJson("/api/services/{$service->service_id}", ['category' => 'banana'])
            ->assertStatus(422)->assertJsonValidationErrors(['category']);
    }
}
