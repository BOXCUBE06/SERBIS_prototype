<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/analytics — section 7, loan turnaround and overdue.
 *
 * The case that matters most: "currently overdue" must NOT be blind to a loan
 * that went out last quarter and never came back, so unlike the windowed
 * days-out and returned-late figures, it ignores the date filter entirely —
 * the same choice aging() makes for open service requests.
 */
class AnalyticsLoanTurnaroundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->equipment = Equipment::create(['item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available']);

        $this->actingAs($this->admin);
    }

    private function borrowing(array $overrides, string $createdAtUtc): EquipmentBorrowing
    {
        $borrowing = EquipmentBorrowing::create(array_merge([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Pending',
        ], $overrides));

        DB::table('tbl_equipment_borrowing')
            ->where('borrow_id', $borrowing->borrow_id)
            ->update(['created_at' => $createdAtUtc]);

        return $borrowing->fresh();
    }

    private function report(array $query = []): array
    {
        return $this->getJson('/api/admin/analytics?'.http_build_query($query))
            ->assertOk()
            ->json()['loans'];
    }

    public function test_median_days_out_excludes_loans_still_released(): void
    {
        // Released and returned 3 days later.
        $this->borrowing([
            'status' => 'Returned',
            'due_date' => '2026-09-20',
            'released_at' => '2026-09-05 00:00:00',
            'returned_at' => '2026-09-08 00:00:00',
        ], '2026-09-05 00:00:00');

        // Still out — must not count toward the median.
        $this->borrowing([
            'status' => 'Released',
            'due_date' => '2026-09-25',
            'released_at' => '2026-09-06 00:00:00',
        ], '2026-09-06 00:00:00');

        $loans = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(1, $loans['daysOut']['n']);
        $this->assertEqualsWithDelta(3, $loans['daysOut']['medianDays'], 0.01);
    }

    public function test_returned_late_is_measured_against_the_due_date(): void
    {
        $this->borrowing([
            'status' => 'Returned',
            'due_date' => '2026-09-10',
            'released_at' => '2026-09-01 00:00:00',
            'returned_at' => '2026-09-12 00:00:00',
        ], '2026-09-01 00:00:00');

        $this->borrowing([
            'status' => 'Returned',
            'due_date' => '2026-09-10',
            'released_at' => '2026-09-01 00:00:00',
            'returned_at' => '2026-09-09 00:00:00',
        ], '2026-09-01 00:00:00');

        $loans = $this->report(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(1, $loans['returnedLate']['count']);
        $this->assertSame(2, $loans['returnedLate']['of']);
        $this->assertSame(50, $loans['returnedLate']['percent']);
    }

    /**
     * The load-bearing test: a loan created well outside the queried window
     * (and therefore invisible to daysOut/returnedLate) must still be caught
     * by currentlyOverdue, because that figure ignores the window on purpose.
     */
    public function test_currently_overdue_ignores_the_date_window(): void
    {
        $this->borrowing([
            'status' => 'Released',
            'due_date' => now()->subDays(5)->format('Y-m-d'),
            'released_at' => now()->subDays(20)->toDateTimeString(),
        ], now()->subDays(20)->toDateTimeString());

        // Released, due in the future — not overdue.
        $this->borrowing([
            'status' => 'Released',
            'due_date' => now()->addDays(5)->format('Y-m-d'),
            'released_at' => now()->subDay()->toDateTimeString(),
        ], now()->subDay()->toDateTimeString());

        // Way outside any sane "this month" window — the point of the test.
        $loans = $this->report(['preset' => 'month']);

        $this->assertSame(1, $loans['currentlyOverdue']);
    }

    public function test_a_returned_loan_is_never_counted_as_overdue(): void
    {
        $this->borrowing([
            'status' => 'Returned',
            'due_date' => now()->subDays(5)->format('Y-m-d'),
            'released_at' => now()->subDays(10)->toDateTimeString(),
            'returned_at' => now()->subDays(6)->toDateTimeString(),
        ], now()->subDays(10)->toDateTimeString());

        $loans = $this->report(['preset' => 'year']);

        $this->assertSame(0, $loans['currentlyOverdue'], 'a returned loan already came back and cannot be overdue');
    }

    public function test_the_barangay_filter_narrows_all_three_figures(): void
    {
        $otherBarangay = Barangay::create(['barangay_name' => 'Angang']);
        $otherResident = Resident::create([
            'barangay_id' => $otherBarangay->barangay_id,
            'first_name' => 'Jose', 'last_name' => 'Cruz',
            'phone_number' => '09172222222', 'email_address' => 'jose@test.local',
            'password' => Hash::make('password123'), 'status' => 'Active',
        ]);

        EquipmentBorrowing::create([
            'resident_id' => $otherResident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Released',
            'due_date' => now()->subDays(3)->format('Y-m-d'),
            'released_at' => now()->subDays(10)->toDateTimeString(),
        ]);

        $filtered = $this->getJson('/api/admin/analytics?'.http_build_query(['preset' => 'year', 'barangay_id' => $this->resident->barangay_id]))
            ->assertOk()->json()['loans'];

        $this->assertSame(0, $filtered['currentlyOverdue'], 'the other barangay\'s overdue loan must not count here');
    }
}
