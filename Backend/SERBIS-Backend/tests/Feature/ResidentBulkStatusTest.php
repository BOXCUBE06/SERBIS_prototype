<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * The panel's bulk Activate / Deactivate is a loop of PUT /residents/{id}, one
 * per ticked row (UsersView.vue runBulk), so what the server owes it is that
 * each call stands alone: a status change per row, tokens revoked on
 * deactivation, and one refused row not stopping the rest.
 */
class ResidentBulkStatusTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        Sanctum::actingAs($this->makeLimitedStaff(['residents']));
    }

    private function resident(int $n, string $status): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Res'.chr(64 + $n),
            'last_name' => 'Test',
            'phone_number' => '+63917000000'.$n,
            'password' => Hash::make('Password123'),
            'status' => $status,
        ]);
    }

    /** The full body the panel sends for a row. */
    private function setStatus(Resident $r, string $status)
    {
        return $this->putJson("/api/residents/{$r->getKey()}", [
            'first_name' => $r->first_name,
            'last_name' => $r->last_name,
            'phone_number' => $r->phone_number,
            'barangay_id' => $r->barangay_id,
            'status' => $status,
        ]);
    }

    public function test_activating_several_deactivated_accounts(): void
    {
        $rows = [$this->resident(1, 'Deactivated'), $this->resident(2, 'Deactivated'), $this->resident(3, 'Deactivated')];

        foreach ($rows as $r) {
            $this->setStatus($r, 'Active')->assertOk()->assertJsonPath('status', 'Active');
        }

        $this->assertSame(3, Resident::where('status', 'Active')->count());
    }

    public function test_deactivating_several_accounts_revokes_their_tokens(): void
    {
        $rows = [$this->resident(1, 'Active'), $this->resident(2, 'Inactive')];
        foreach ($rows as $r) {
            $r->createToken('resident-token');
        }

        foreach ($rows as $r) {
            $this->setStatus($r, 'Deactivated')->assertOk();
        }

        $this->assertSame(0, Resident::where('status', '!=', 'Deactivated')->count());
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());
    }

    public function test_one_refused_row_does_not_stop_the_others(): void
    {
        $a = $this->resident(1, 'Deactivated');
        $b = $this->resident(2, 'Deactivated');
        $c = $this->resident(3, 'Deactivated');

        $this->setStatus($a, 'Active')->assertOk();
        $this->setStatus($b, 'Not a status')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->setStatus($c, 'Active')->assertOk();

        $this->assertSame('Active', $a->fresh()->status);
        $this->assertSame('Deactivated', $b->fresh()->status);
        $this->assertSame('Active', $c->fresh()->status);
    }

    public function test_bulk_activate_approves_pending_accounts_with_the_same_write(): void
    {
        $r = $this->resident(1, 'Inactive');

        $this->setStatus($r, 'Active')->assertOk()->assertJsonPath('status', 'Active');

        $this->assertSame('Active', $r->fresh()->status);
    }

    public function test_an_account_that_is_already_in_the_state_is_left_as_it_is(): void
    {
        $r = $this->resident(1, 'Active');

        $this->setStatus($r, 'Active')->assertOk();

        $this->assertSame('Active', $r->fresh()->status);
    }

    public function test_a_row_deleted_meanwhile_is_a_404_for_that_row_only(): void
    {
        $gone = $this->resident(1, 'Deactivated');
        $kept = $this->resident(2, 'Deactivated');
        $goneRow = clone $gone;
        $gone->delete();

        $this->setStatus($goneRow, 'Active')->assertNotFound();
        $this->setStatus($kept, 'Active')->assertOk();
    }

    public function test_bulk_status_changes_need_the_residents_section(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff(['logs'], 'other@test.local'));
        $r = $this->resident(1, 'Deactivated');

        $this->setStatus($r, 'Active')->assertForbidden();

        $this->assertSame('Deactivated', $r->fresh()->status);
    }
}
