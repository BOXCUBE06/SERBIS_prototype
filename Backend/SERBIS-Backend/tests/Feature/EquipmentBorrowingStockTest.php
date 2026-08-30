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
 * POST /api/borrowings — how much a resident is allowed to ask for.
 *
 * The rules only ever bounded the shape: an existing equipment id and an
 * integer of at least one. Nothing looked at how many the office actually has,
 * so a request for fifty of an item there are four of was accepted, sat in the
 * Pending column looking legitimate, could be approved, and only failed at
 * release time — after someone had already told the resident yes.
 *
 * The second half of this file pins the deliberate non-behaviour: a Pending
 * request reserves nothing. That is why the check is a ceiling on the shelf
 * count rather than on what is left after other open requests.
 */
class EquipmentBorrowingStockTest extends TestCase
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

    private function borrow(int $quantity, ?int $equipmentId = null, mixed $purpose = 'Barangay flood drill')
    {
        $payload = [
            'equipment_id' => $equipmentId ?? $this->equipment->getKey(),
            'quantity' => $quantity,
        ];

        // Distinguishes "sent nothing" from "sent an empty box": the first is a
        // client that predates the field, the second is a resident who skipped
        // it, and both must be rejected.
        if ($purpose !== null) {
            $payload['purpose'] = $purpose;
        }

        return $this->actingAs($this->resident)->postJson('/api/borrowings', $payload);
    }

    public function test_the_purpose_is_stored_with_the_request(): void
    {
        $this->borrow(1, null, 'Evacuation centre setup')
            ->assertStatus(201)
            ->assertJsonPath('purpose', 'Evacuation centre setup');

        $this->assertSame('Evacuation centre setup', EquipmentBorrowing::first()->purpose);
    }

    public function test_a_request_without_a_purpose_is_rejected(): void
    {
        $this->borrow(1, null, null)
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    /** Whitespace is trimmed to null before validation, so it fails the same way. */
    public function test_a_blank_purpose_is_rejected(): void
    {
        $this->borrow(1, null, '   ')
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    public function test_a_purpose_over_the_column_length_is_rejected(): void
    {
        $this->borrow(1, null, str_repeat('a', 256))
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    public function test_a_request_within_stock_is_accepted(): void
    {
        $this->borrow(2)
            ->assertStatus(201)
            ->assertJsonPath('status', 'Pending')
            ->assertJsonPath('quantity', 2);

        $this->assertSame(1, EquipmentBorrowing::count());
        $this->assertSame(4, $this->equipment->fresh()->available_quantity, 'filing must not move stock');
    }

    /** The boundary itself: asking for everything on the shelf is legal. */
    public function test_asking_for_exactly_the_available_quantity_is_accepted(): void
    {
        $this->borrow(4)->assertStatus(201);

        $this->assertSame(1, EquipmentBorrowing::count());
    }

    public function test_asking_for_more_than_is_available_is_rejected(): void
    {
        $this->borrow(5)
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity')
            ->assertJsonPath('errors.quantity.0', 'Only 4 of this item are available to borrow.');

        $this->assertSame(0, EquipmentBorrowing::count(), 'the rejected request was filed anyway');
    }

    /**
     * Stock at zero is the case the old code handled worst: every request was
     * accepted for an item that had none left at all.
     */
    public function test_nothing_can_be_borrowed_when_the_shelf_is_empty(): void
    {
        $this->equipment->update(['available_quantity' => 0]);

        $this->borrow(1)
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Only 0 of this item are available to borrow.');

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    /**
     * The check reads the shelf, not the shelf minus everything already asked
     * for. Two residents may both hold a request for the whole stock; the
     * second one is refused at release, where the lock and the real decrement
     * are. Reserving on Pending would let anyone empty the inventory with
     * requests nobody ever approves.
     */
    public function test_pending_requests_do_not_reserve_stock(): void
    {
        $this->borrow(4)->assertStatus(201);
        $this->borrow(4)->assertStatus(201);

        $this->assertSame(2, EquipmentBorrowing::count());
        $this->assertSame(4, $this->equipment->fresh()->available_quantity);
    }

    /**
     * Stock already out on loan does lower the ceiling, because releasing is
     * what decrements the column this check reads.
     */
    public function test_stock_already_released_lowers_the_ceiling(): void
    {
        $this->equipment->update(['available_quantity' => 1]);

        $this->borrow(2)->assertStatus(422);
        $this->borrow(1)->assertStatus(201);
    }

    /** The pre-existing shape rules still answer first. */
    public function test_the_shape_rules_are_unchanged(): void
    {
        $this->borrow(0)->assertJsonValidationErrors('quantity');
        $this->borrow(1, 999999)->assertJsonValidationErrors('equipment_id');

        $this->assertSame(0, EquipmentBorrowing::count());
    }
}
