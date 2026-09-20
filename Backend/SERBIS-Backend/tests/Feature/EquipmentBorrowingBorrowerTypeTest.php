<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/borrowings — whether the item is for the account holder or for an
 * institution. The type now comes from the account (see AccountTypeTest); this
 * file keeps the head-of-the-family default and how the office reads the record.
 */
class EquipmentBorrowingBorrowerTypeTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Equipment $equipment;

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

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Barangay flood drill',
        ], $overrides);
    }

    public function test_a_request_that_says_nothing_about_the_borrower_is_a_resident(): void
    {
        // The old mobile build sends exactly this. It must keep working, and
        // must mean what it always meant.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('borrower_type', 'Resident');

        $this->assertNull(EquipmentBorrowing::firstOrFail()->organization_name);
    }

    public function test_the_office_sees_the_borrower_type_and_name_on_the_record(): void
    {
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Barangay flood drill',
            'borrower_type' => 'Organization',
            'organization_name' => 'SK San Antonio Ugad',
            'status' => 'Pending',
        ]);

        $this->actingAs($this->resident)
            ->getJson("/api/borrowings/{$borrowing->borrow_id}")
            ->assertOk()
            ->assertJsonPath('borrower_type', 'Organization')
            ->assertJsonPath('organization_name', 'SK San Antonio Ugad');
    }

    public function test_delivery_does_not_disturb_the_borrower_type(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'fulfillment_method' => 'Delivery',
                'delivery_address' => 'Purok 3, near the chapel',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('delivery_address', 'Purok 3, near the chapel')
            ->assertJsonPath('borrower_type', 'Resident');
    }
}
