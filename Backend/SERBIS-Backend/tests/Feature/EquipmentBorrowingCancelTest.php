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
 * PATCH /api/borrowings/{id}/cancel — the resident withdraws their own request.
 *
 * Until this route existed a filed borrowing was read-only to the resident who
 * filed it: `borrowings` exposed update/destroy to `is.admin` only, so someone
 * who no longer needed the item had to phone the office and MDRRMO had to Deny
 * it, which records a refusal that never happened.
 *
 * The line sits at Released — Pending and Approved may be withdrawn, everything
 * from Released on may not — because Released is when the item is physically in
 * the resident's hands. It is deliberately NOT a stock line, and one test here
 * exists only to pin that: cancelling an Approved request must leave
 * available_quantity alone, because nothing was ever reserved for it.
 *
 * Who may call the route at all is pinned in OwnershipScopeTest alongside the
 * four other endpoints that share ScopesToOwner.
 */
class EquipmentBorrowingCancelTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private User $admin;

    private Equipment $equipment;

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

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);
    }

    private function borrowingAt(string $status): EquipmentBorrowing
    {
        return EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Flood evacuation',
            'status' => $status,
        ]);
    }

    private function cancel(EquipmentBorrowing $borrowing)
    {
        return $this->patchJson("/api/borrowings/{$borrowing->getKey()}/cancel");
    }

    // --------------------------------------------------------------- allowed

    /**
     * Both cancellable statuses, run for real rather than read off the
     * constant, and each one re-read from the database: a 200 proves the
     * response said yes, not that the row was written.
     */
    public function test_pending_and_approved_can_be_cancelled_by_the_owner(): void
    {
        Sanctum::actingAs($this->resident);

        foreach (['Pending', 'Approved'] as $status) {
            $borrowing = $this->borrowingAt($status);

            $this->cancel($borrowing)
                ->assertOk()
                ->assertJsonPath('status', 'Cancelled');

            $this->assertSame('Cancelled', $borrowing->fresh()->status, "{$status} was not cancelled");
        }
    }

    /**
     * The whole reason the Released line is drawn where it is. available_quantity
     * moves only on Released and Returned, so an Approved request holds no
     * stock and cancelling it must not hand anything back — an increment here
     * would invent inventory the office does not have.
     */
    public function test_cancelling_an_approved_request_does_not_touch_stock(): void
    {
        Sanctum::actingAs($this->resident);

        $borrowing = $this->borrowingAt('Approved');

        $this->cancel($borrowing)->assertOk();

        $this->assertSame(4, $this->equipment->fresh()->available_quantity);
    }

    // --------------------------------------------------------------- refused

    /**
     * Every status past the line, with the row re-read each time. Released is
     * the one that matters — the item is already out — and the two terminal
     * statuses are here because a closed request has nothing left to withdraw.
     */
    public function test_released_returned_and_denied_cannot_be_cancelled(): void
    {
        Sanctum::actingAs($this->resident);

        $checked = 0;

        foreach (['Released', 'Returned', 'Denied'] as $status) {
            $borrowing = $this->borrowingAt($status);

            $this->cancel($borrowing)
                ->assertStatus(422)
                ->assertJsonPath(
                    'message',
                    'Only a pending or approved request can be cancelled. Call the office instead.'
                );

            $this->assertSame($status, $borrowing->fresh()->status, "{$status} was cancelled anyway");

            $checked++;
        }

        // Guards the loop: a CANCELLABLE_FROM that accidentally listed
        // everything would otherwise run zero assertions and still pass.
        $this->assertSame(3, $checked);
    }

    public function test_a_request_that_is_already_cancelled_cannot_be_cancelled_again(): void
    {
        Sanctum::actingAs($this->resident);

        $borrowing = $this->borrowingAt('Pending');

        $this->cancel($borrowing)->assertOk();
        $this->cancel($borrowing)->assertStatus(422);
    }

    public function test_a_borrowing_that_does_not_exist_is_a_404(): void
    {
        Sanctum::actingAs($this->resident);

        $this->patchJson('/api/borrowings/99999/cancel')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Borrowing record not found');
    }

    public function test_the_route_needs_a_token(): void
    {
        $borrowing = $this->borrowingAt('Pending');

        $this->patchJson("/api/borrowings/{$borrowing->getKey()}/cancel")->assertStatus(401);

        $this->assertSame('Pending', $borrowing->fresh()->status);
    }

    // -------------------------------------------------- and the panel after

    /**
     * Cancelled is terminal in TRANSITIONS, so the admin panel cannot revive a
     * withdrawn request into the pipeline. Without the key in that table the
     * lookup would fall to `?? []` and reject the move for the wrong reason —
     * this pins the message, which is what an operator actually reads.
     */
    public function test_staff_cannot_move_a_cancelled_request_back_into_the_pipeline(): void
    {
        Sanctum::actingAs($this->resident);
        $borrowing = $this->borrowingAt('Pending');
        $this->cancel($borrowing)->assertOk();

        Sanctum::actingAs($this->admin);

        foreach (['Approved', 'Released', 'Returned', 'Denied'] as $status) {
            $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => $status])
                ->assertStatus(422)
                ->assertJsonPath('message', "A borrowing that is Cancelled cannot be moved to {$status}.");
        }

        $this->assertSame('Cancelled', $borrowing->fresh()->status);
    }

    /**
     * Cancelling is the resident's word, not a status staff can apply on their
     * behalf: update()'s `in:` rule never accepted 'Cancelled' and must keep
     * refusing it, or the panel gains a second writer for this status with
     * none of cancel()'s rules.
     */
    public function test_staff_cannot_set_cancelled_through_the_admin_update(): void
    {
        Sanctum::actingAs($this->admin);

        $borrowing = $this->borrowingAt('Pending');

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Cancelled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('Pending', $borrowing->fresh()->status);
    }
}
