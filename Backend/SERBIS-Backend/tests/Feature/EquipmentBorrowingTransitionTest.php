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
 * PUT /api/borrowings/{id} — which status moves are legal.
 *
 * The rule used to be `in:Pending,Approved,Released,Returned,Denied`, which
 * constrained the status word and never the move, so any record could be pushed
 * into any state. The one that cost real data was Returned -> Released: the
 * release branch fired on `$oldStatus !== 'Released'`, which a Returned record
 * satisfies, so an item already back on the shelf had its stock deducted a
 * second time and the count never recovered.
 *
 * These tests run the bad transitions rather than reading the code. Every
 * illegal move in the table is attempted here, and the double-deduction case
 * additionally asserts the stock did not move — a 422 alone would not prove the
 * decrement was skipped, only that the response said no.
 */
class EquipmentBorrowingTransitionTest extends TestCase
{
    use RefreshDatabase;

    private const STATUSES = ['Pending', 'Approved', 'Released', 'Returned', 'Denied'];

    /** Mirrors EquipmentBorrowingController::TRANSITIONS. */
    private const LEGAL = [
        'Pending' => ['Approved', 'Denied'],
        'Approved' => ['Released', 'Denied'],
        'Released' => ['Returned'],
        'Returned' => [],
        'Denied' => [],
    ];

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
            'role' => 'Admin',
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

    private function borrowingAt(string $status): EquipmentBorrowing
    {
        return EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => $status,
        ]);
    }

    private function move(EquipmentBorrowing $borrowing, string $status)
    {
        return $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => $status]);
    }

    // ---------------------------------------------------------------- illegal

    /**
     * Every illegal pair in the table, run for real. Twenty cross-status moves
     * exist and five of them are legal, so fifteen are attempted here.
     * Same-status pairs are excluded: resending the current status is a no-op,
     * not a transition.
     */
    public function test_every_illegal_transition_is_rejected(): void
    {
        $checked = 0;

        foreach (self::STATUSES as $from) {
            foreach (self::STATUSES as $to) {
                if ($from === $to || in_array($to, self::LEGAL[$from], true)) {
                    continue;
                }

                $borrowing = $this->borrowingAt($from);

                $this->move($borrowing, $to)
                    ->assertStatus(422)
                    ->assertJsonPath('message', "A borrowing that is {$from} cannot be moved to {$to}.");

                $this->assertSame($from, $borrowing->fresh()->status, "{$from} -> {$to} changed the status anyway");

                $checked++;
            }
        }

        // Guards the loop itself: a table that accidentally legalised everything
        // would otherwise run zero assertions and still pass.
        $this->assertSame(15, $checked);
    }

    /**
     * The bug this whole change exists for, exercised rather than read.
     *
     * A Returned record pushed back to Released used to satisfy
     * `$oldStatus !== 'Released'` and decrement the stock a second time for an
     * item that was physically on the shelf.
     */
    public function test_returned_cannot_go_back_to_released_and_the_stock_is_untouched(): void
    {
        $borrowing = $this->borrowingAt('Approved');

        $this->move($borrowing, 'Released')->assertOk();
        $this->move($borrowing, 'Returned')->assertOk();

        $this->equipment->refresh();
        $this->assertSame(4, $this->equipment->available_quantity, 'the round trip should leave stock where it started');

        $this->move($borrowing, 'Released')->assertStatus(422);

        $this->equipment->refresh();
        $this->assertSame(4, $this->equipment->available_quantity, 'the rejected move deducted stock anyway');
        $this->assertSame('Returned', $borrowing->fresh()->status);
    }

    /**
     * Released -> Denied was never meant to exist. It had its own branch that
     * returned the stock but never set `returned_at`, so the item was back with
     * no record of when. The branch is gone and the move is refused.
     */
    public function test_released_cannot_be_denied(): void
    {
        $borrowing = $this->borrowingAt('Approved');
        $this->move($borrowing, 'Released')->assertOk();

        $this->equipment->refresh();
        $this->assertSame(3, $this->equipment->available_quantity);

        $this->move($borrowing, 'Denied')->assertStatus(422);

        $this->equipment->refresh();
        $this->assertSame(3, $this->equipment->available_quantity, 'the refused denial returned the stock anyway');
        $this->assertNull($borrowing->fresh()->returned_at);
    }

    public function test_terminal_states_cannot_be_revived(): void
    {
        $returned = $this->borrowingAt('Returned');
        $denied = $this->borrowingAt('Denied');

        $this->move($returned, 'Pending')->assertStatus(422);
        $this->move($denied, 'Approved')->assertStatus(422);

        $this->assertSame('Returned', $returned->fresh()->status);
        $this->assertSame('Denied', $denied->fresh()->status);
    }

    /*
     * There is deliberately no test for a status outside the five. The
     * controller's `?? []` fails closed on one, but `status` is an ENUM column
     * and MySQL truncates anything else on write — `SQLSTATE[01000] ... Data
     * truncated for column 'status'` — so the state cannot be reached to be
     * tested. The guard stays as defence in case the column ever widens; it is
     * simply unreachable today, and a test that cannot fail is worth less than
     * this comment.
     */

    // ------------------------------------------------------------------ legal

    public function test_the_happy_path_still_moves_the_stock_correctly(): void
    {
        $borrowing = $this->borrowingAt('Pending');

        $this->move($borrowing, 'Approved')->assertOk();
        $this->assertSame(4, $this->equipment->fresh()->available_quantity, 'approval alone must not touch stock');

        $this->move($borrowing, 'Released')->assertOk();
        $this->assertSame(3, $this->equipment->fresh()->available_quantity);
        $this->assertNotNull($borrowing->fresh()->released_at);

        $this->move($borrowing, 'Returned')->assertOk();
        $this->assertSame(4, $this->equipment->fresh()->available_quantity);
        $this->assertNotNull($borrowing->fresh()->returned_at);
    }

    public function test_both_denial_routes_are_open(): void
    {
        $fromPending = $this->borrowingAt('Pending');
        $this->move($fromPending, 'Denied')->assertOk();
        $this->assertSame('Denied', $fromPending->fresh()->status);

        $fromApproved = $this->borrowingAt('Approved');
        $this->move($fromApproved, 'Denied')->assertOk();
        $this->assertSame('Denied', $fromApproved->fresh()->status);

        // Neither denial had released anything, so neither returns anything.
        $this->assertSame(4, $this->equipment->fresh()->available_quantity);
    }

    /**
     * `status` is required, so a call that only edits the due date has to
     * resend the status it already has. That must not be read as a transition —
     * Pending -> Pending is absent from the table and would otherwise 422.
     */
    public function test_resending_the_current_status_is_a_no_op_not_a_transition(): void
    {
        $borrowing = $this->borrowingAt('Approved');
        // Relative: `due_date` is bounded to [today, +1 year], so a hardcoded
        // literal starts failing on a date unrelated to what this asserts.
        $due = now()->addDays(9)->format('Y-m-d');

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Approved',
                'due_date' => $due,
            ])
            ->assertOk();

        $borrowing->refresh();
        $this->assertSame($due, $borrowing->due_date->format('Y-m-d'));
        $this->assertSame(4, $this->equipment->fresh()->available_quantity, 'a no-op must not move stock');
    }
}
