<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Borrowing something the inventory does not list.
 *
 * `equipment_id` is nullable as of 2026_09_08_110000, and exactly one of it and
 * `other_equipment_text` is set on every row. Two things are pinned here that
 * are easy to lose: that the CHECK constraint on the table is real and not
 * decoration, and that an uncatalogued request cannot be Released — there is no
 * stock to deduct, so releasing one would hand out a physical item with nothing
 * in the inventory to say it left.
 */
class EquipmentBorrowingOtherItemTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;
    private User $admin;
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

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
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

    /** A request naming free text instead of a catalogued item. */
    private function uncataloguedRequest(array $overrides = []): array
    {
        $payload = $this->payload($overrides);
        unset($payload['equipment_id']);
        $payload['other_equipment_text'] ??= 'Chainsaw with a 20-inch bar';

        return $payload;
    }

    // ---------------------------------------------------------------- filing

    public function test_a_catalogued_request_still_works(): void
    {
        // The regression that matters most: making equipment_id nullable must
        // not change what a normal request does.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('equipment_id', $this->equipment->getKey())
            ->assertJsonPath('other_equipment_text', null);
    }

    public function test_a_request_can_name_an_item_the_inventory_does_not_have(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->uncataloguedRequest())
            ->assertStatus(201)
            ->assertJsonPath('equipment_id', null)
            ->assertJsonPath('other_equipment_text', 'Chainsaw with a 20-inch bar');
    }

    public function test_a_request_naming_both_an_item_and_free_text_is_refused(): void
    {
        // A request that names the item twice and disagrees with itself.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload([
                'other_equipment_text' => 'Chainsaw with a 20-inch bar',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['other_equipment_text']);

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    public function test_a_request_naming_neither_is_refused(): void
    {
        $payload = $this->payload();
        unset($payload['equipment_id']);

        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipment_id', 'other_equipment_text']);

        $this->assertSame(0, EquipmentBorrowing::count());
    }

    public function test_free_text_longer_than_the_column_is_refused(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->uncataloguedRequest([
                'other_equipment_text' => str_repeat('a', 256),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['other_equipment_text']);
    }

    public function test_an_uncatalogued_request_is_not_bounded_by_stock(): void
    {
        // The satisfiability check compares against an inventory row, and there
        // is none — the whole point of the field is that MDRRMO has not
        // catalogued the thing. Fifty is not refusable here; it is bounded at
        // release time instead.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->uncataloguedRequest(['quantity' => 50]))
            ->assertStatus(201)
            ->assertJsonPath('quantity', 50);
    }

    public function test_a_catalogued_request_is_still_bounded_by_stock(): void
    {
        // The other half of the branch above: skipping the check for free text
        // must not have skipped it for everything.
        $this->actingAs($this->resident)
            ->postJson('/api/borrowings', $this->payload(['quantity' => 50]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    // --------------------------------------------------------------- release

    public function test_an_uncatalogued_request_cannot_be_released(): void
    {
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'other_equipment_text' => 'Chainsaw with a 20-inch bar',
            'quantity' => 1,
            'purpose' => 'Clearing a fallen acacia',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->patchJson("/api/borrowings/{$borrowing->borrow_id}", [
            'status' => 'Released',
        ])->assertStatus(422);

        // The message has to name the item and say what to do, not just refuse
        // — staff reading it need to know the fix is to catalogue the item.
        $this->assertStringContainsString('Chainsaw with a 20-inch bar', $response->json('message'));
        $this->assertStringContainsString('before releasing', $response->json('message'));

        $this->assertSame('Approved', $borrowing->fresh()->status);
    }

    public function test_an_uncatalogued_request_can_still_be_approved_and_denied(): void
    {
        // The refusal is scoped to Released only. The office can still consider
        // the request; they just cannot hand the item over yet.
        $pending = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'other_equipment_text' => 'Chainsaw with a 20-inch bar',
            'quantity' => 1,
            'purpose' => 'Clearing a fallen acacia',
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/borrowings/{$pending->borrow_id}", ['status' => 'Approved'])
            ->assertOk();
        $this->assertSame('Approved', $pending->fresh()->status);

        $this->patchJson("/api/borrowings/{$pending->borrow_id}", [
            'status' => 'Denied',
            'denial_reason' => 'No chainsaw available to source.',
        ])->assertOk();
        $this->assertSame('Denied', $pending->fresh()->status);
    }

    public function test_a_catalogued_request_still_releases_and_moves_stock(): void
    {
        // Guards against the release refusal being written too broadly.
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 2,
            'purpose' => 'Barangay flood drill',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/borrowings/{$borrowing->borrow_id}", ['status' => 'Released'])
            ->assertOk();

        $this->assertSame('Released', $borrowing->fresh()->status);
        $this->assertSame(2, $this->equipment->fresh()->available_quantity);
    }

    // ------------------------------------------------ the constraint itself

    public function test_the_database_refuses_a_row_naming_both(): void
    {
        // Written against the table directly, below the controller. The
        // validation rules are the readable error; this is the floor under a
        // seeder or a tinker session that never reaches them, and it is only
        // worth having if it actually bites.
        $this->expectException(QueryException::class);

        DB::table('tbl_equipment_borrowing')->insert([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'other_equipment_text' => 'Chainsaw with a 20-inch bar',
            'quantity' => 1,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_row_naming_neither(): void
    {
        $this->expectException(QueryException::class);

        DB::table('tbl_equipment_borrowing')->insert([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => null,
            'other_equipment_text' => null,
            'quantity' => 1,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
