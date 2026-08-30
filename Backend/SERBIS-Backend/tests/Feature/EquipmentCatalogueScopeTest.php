<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/equipments — who is allowed to see an item withdrawn from lending.
 *
 * `status` was written by the admin panel and read by nobody. The resident
 * borrow screen gates only on `available_quantity > 0`, so an item marked
 * `Unavailable` — under repair, condemned, reserved for an operation — kept
 * appearing in the catalogue with a working Borrow button for as long as its
 * count stayed above zero. The request would then be filed, approved, and only
 * discovered at release.
 */
class EquipmentCatalogueScopeTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        Equipment::create([
            'item_name' => 'Wheelchair',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);

        // In stock, but withdrawn from lending. Stock alone must not put it in
        // front of a resident.
        Equipment::create([
            'item_name' => 'Hospital Bed',
            'total_quantity' => 3,
            'available_quantity' => 3,
            'status' => 'Unavailable',
        ]);
    }

    public function test_a_resident_is_not_offered_an_unavailable_item(): void
    {
        $response = $this->actingAs($this->resident)
            ->getJson('/api/equipments')
            ->assertOk();

        $names = array_column($response->json(), 'item_name');

        $this->assertContains('Wheelchair', $names);
        $this->assertNotContains('Hospital Bed', $names, 'stock above zero is not a licence to lend');
    }

    public function test_an_admin_still_sees_the_whole_inventory(): void
    {
        $admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        Sanctum::actingAs($admin);

        $names = array_column($this->getJson('/api/equipments')->assertOk()->json(), 'item_name');

        // The panel has to render and edit exactly the rows a resident must not
        // see, so this list is deliberately unfiltered.
        $this->assertContains('Wheelchair', $names);
        $this->assertContains('Hospital Bed', $names);
    }
}
