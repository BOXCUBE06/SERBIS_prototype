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
 * POST /api/borrowings — whether the item is for the account holder or for a
 * group they are borrowing on behalf of.
 *
 * The name is the part worth pinning. The account behind every request is a
 * person, so an organisation that arrives without a name cannot be filled in
 * from anything already on file — the borrower's own name is not the group's.
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

    public function test_an_organization_records_the_name_it_was_given(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'borrower_type' => 'Organization',
                'organization_name' => 'SK San Antonio Ugad',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('borrower_type', 'Organization')
            ->assertJsonPath('organization_name', 'SK San Antonio Ugad');
    }

    public function test_an_organization_without_a_name_is_refused(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'borrower_type' => 'Organization',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_name']);

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    public function test_a_resident_does_not_keep_an_organization_that_was_typed_and_abandoned(): void
    {
        // A form where the borrower picked Organization, typed a name, then
        // switched back must not leave the loan recorded as institutional.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'borrower_type' => 'Resident',
                'organization_name' => 'SK San Antonio Ugad',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('organization_name', null);

        $this->assertNull(EquipmentBorrowing::firstOrFail()->organization_name);
    }

    public function test_an_unknown_borrower_type_is_refused(): void
    {
        // 'Individual' specifically: it was considered and dropped, because
        // resident_id is a required FK so it would be indistinguishable from
        // 'Resident' in the data. It must not slip in through validation.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'borrower_type' => 'Individual',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['borrower_type']);
    }

    public function test_an_organization_name_longer_than_the_column_is_refused(): void
    {
        // Caught here rather than truncated by MySQL, which would silently
        // store a clipped organisation name.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'borrower_type' => 'Organization',
                'organization_name' => str_repeat('a', 151),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_name']);
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

    public function test_the_two_new_fields_are_independent_of_fulfilment(): void
    {
        // The two pairs were added one after the other in the same shape, and
        // both null their partner column out. This pins that neither nulls the
        // other's.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'fulfillment_method' => 'Delivery',
                'delivery_address' => 'Purok 3, near the chapel',
                'borrower_type' => 'Organization',
                'organization_name' => 'SK San Antonio Ugad',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('delivery_address', 'Purok 3, near the chapel')
            ->assertJsonPath('organization_name', 'SK San Antonio Ugad');
    }
}
