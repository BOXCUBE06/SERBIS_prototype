<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DELETE /api/borrowings/{id} — the route that was registered and never built.
 *
 * `borrowings` was declared with ->only(['update', 'destroy']) while
 * EquipmentBorrowingController has no destroy(), so every call was a 500 rather
 * than a refusal. It is gone rather than implemented: a borrowing is a ledger
 * row — it moved stock, it may carry the handover photographs, and it is the
 * only record of who held an item and when. Every ending a borrowing needs
 * already exists and all of them keep the row (Denied and Returned via
 * update(), Cancelled via cancel()).
 *
 * A 405 with the record still there is the whole assertion. This file exists so
 * the route cannot come back by accident.
 */
class EquipmentBorrowingDeleteRouteTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private User $admin;

    private EquipmentBorrowing $borrowing;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'Silauan Norte']);

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

        $equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 3,
            'status' => 'Available',
        ]);

        $this->borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Flood evacuation',
            'status' => 'Released',
        ]);
    }

    public function test_an_admin_cannot_delete_a_borrowing(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/borrowings/{$this->borrowing->getKey()}")
            ->assertStatus(405);

        $this->assertNotNull($this->borrowing->fresh());
    }

    public function test_a_resident_cannot_delete_a_borrowing_either(): void
    {
        Sanctum::actingAs($this->resident);

        $this->deleteJson("/api/borrowings/{$this->borrowing->getKey()}")
            ->assertStatus(405);

        $this->assertNotNull($this->borrowing->fresh());
    }

    /**
     * The endings that do exist, so the removal above reads as "use these"
     * rather than "a borrowing can never be closed".
     */
    public function test_the_endings_a_borrowing_does_have_still_work(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/borrowings/{$this->borrowing->getKey()}", [
            'status' => 'Returned',
            'return_condition_note' => 'Came back in working order.',
        ])
            ->assertOk();

        $this->assertSame('Returned', $this->borrowing->fresh()->status);
    }
}
