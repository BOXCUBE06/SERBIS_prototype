<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /api/borrowings/{id} — the two columns the table never had.
 *
 * A borrowing recorded when an item went out and when it came back, but never
 * when it was due, so "is this return late?" had no answer anywhere in the
 * system and the admin panel could not show an overdue state at all. Denials
 * were recorded with no reason, so a stock shortage and an ineligible request
 * were indistinguishable afterwards.
 *
 * The clearing behaviour is the part worth pinning: a request denied and then
 * re-approved must not keep explaining a refusal that no longer applies.
 */
class EquipmentBorrowingDueDateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Resident $resident;
    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

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

    private function pendingBorrowing(): EquipmentBorrowing
    {
        return EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Pending',
        ]);
    }

    public function test_approving_stores_the_due_date(): void
    {
        $borrowing = $this->pendingBorrowing();

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Approved',
                'due_date' => '2026-08-10',
            ])
            ->assertOk();

        $borrowing->refresh();

        $this->assertSame('Approved', $borrowing->status);
        $this->assertSame('2026-08-10', $borrowing->due_date->format('Y-m-d'));
    }

    public function test_due_date_serialises_without_a_time_component(): void
    {
        $borrowing = $this->pendingBorrowing();

        // The panel compares this against today. A full ISO timestamp with a
        // timezone the date column does not store would have to be trimmed
        // back off on the client before it could be compared.
        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Approved',
                'due_date' => '2026-08-10',
            ])
            ->assertOk()
            ->assertJsonPath('due_date', '2026-08-10');
    }

    public function test_a_status_change_without_a_due_date_is_still_accepted(): void
    {
        $borrowing = $this->pendingBorrowing();

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Approved'])
            ->assertOk();

        $this->assertSame('Approved', $borrowing->fresh()->status);
        $this->assertNull($borrowing->fresh()->due_date);
    }

    public function test_denying_stores_the_reason(): void
    {
        $borrowing = $this->pendingBorrowing();

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Denied',
                'denial_reason' => 'Stock reserved for flood response',
            ])
            ->assertOk();

        $this->assertSame('Stock reserved for flood response', $borrowing->fresh()->denial_reason);
    }

    public function test_moving_off_denied_clears_the_reason(): void
    {
        $borrowing = $this->pendingBorrowing();

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Denied',
                'denial_reason' => 'Stock reserved for flood response',
            ])
            ->assertOk();

        $this->assertNotNull($borrowing->fresh()->denial_reason);

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Approved'])
            ->assertOk();

        $this->assertNull($borrowing->fresh()->denial_reason);
    }

    public function test_a_reason_is_not_stored_on_a_non_denial(): void
    {
        $borrowing = $this->pendingBorrowing();

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Approved',
                'denial_reason' => 'Should not be kept',
            ])
            ->assertOk();

        $this->assertNull($borrowing->fresh()->denial_reason);
    }

    public function test_an_invalid_due_date_is_rejected(): void
    {
        $borrowing = $this->pendingBorrowing();

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Approved',
                'due_date' => 'next tuesday sometime',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
    }
}
