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
 * POST /api/borrowings — whether the item is collected or delivered.
 *
 * The address is the part worth pinning. tbl_residents carries a barangay and
 * no street address, so a delivery that arrives without one cannot be filled
 * from anything already on file.
 */
class EquipmentBorrowingFulfillmentTest extends TestCase
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

    public function test_a_request_that_says_nothing_about_fulfilment_is_a_pickup(): void
    {
        // The old mobile build sends exactly this. It must keep working, and
        // must mean what it always meant.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('fulfillment_method', 'Pickup');

        $this->assertNull(EquipmentBorrowing::firstOrFail()->delivery_address);
    }

    public function test_a_delivery_records_the_address_it_was_given(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'fulfillment_method' => 'Delivery',
                'delivery_address' => 'Purok 3, San Antonio Ugad, near the chapel',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('fulfillment_method', 'Delivery')
            ->assertJsonPath('delivery_address', 'Purok 3, San Antonio Ugad, near the chapel');
    }

    public function test_a_delivery_without_an_address_is_refused(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'fulfillment_method' => 'Delivery',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_address']);

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    public function test_a_pickup_does_not_keep_an_address_that_was_typed_and_abandoned(): void
    {
        // A form where the borrower filled the address, then switched back to
        // pickup, must not leave a delivery instruction on the record.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'fulfillment_method' => 'Pickup',
                'delivery_address' => 'Purok 3, San Antonio Ugad',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('delivery_address', null);

        $this->assertNull(EquipmentBorrowing::firstOrFail()->delivery_address);
    }

    public function test_an_unknown_fulfilment_method_is_refused(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'fulfillment_method' => 'Courier',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fulfillment_method']);
    }

    public function test_the_office_sees_the_method_and_address_on_the_record(): void
    {
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Barangay flood drill',
            'fulfillment_method' => 'Delivery',
            'delivery_address' => 'Purok 3, San Antonio Ugad',
            'status' => 'Pending',
        ]);

        $this->actingAs($this->resident)
            ->getJson("/api/borrowings/{$borrowing->borrow_id}")
            ->assertOk()
            ->assertJsonPath('fulfillment_method', 'Delivery')
            ->assertJsonPath('delivery_address', 'Purok 3, San Antonio Ugad');
    }
}
