<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may read and cancel a service request, and who may read a borrowing.
 *
 * These four routes sit outside the `is.admin` group so that staff can read any
 * resident's record while a resident reads only their own. For the life of the
 * endpoints the only test performed was `instanceof Resident`, written as an
 * `if` with no `else` — so a token belonging to anything that was not a
 * resident fell straight past the scope and the query ran unfiltered.
 *
 * Two accounts reach that branch. A deactivated admin, whose tokens survive a
 * deactivation applied by direct database edit (IsAdmin's own comment names
 * this case), and a `tbl_user` row whose `role` is not 'admin' — the column is
 * an unconstrained varchar and the hand-written INSERT is still the documented
 * recovery path. `is.admin` refuses both everywhere else.
 *
 * PrivateFileAccessTest covers the same guard on the two file-streaming routes,
 * which had it correctly; both now share App\Traits\ScopesToOwner.
 */
class OwnershipScopeTest extends TestCase
{
    use RefreshDatabase;

    private Resident $owner;

    private Resident $stranger;

    private ServiceRequest $request;

    private EquipmentBorrowing $borrowing;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->owner = $this->resident($barangay->barangay_id, 'maria@test.local', '09171234567');
        $this->stranger = $this->resident($barangay->barangay_id, 'jose@test.local', '09179999999');

        $service = Service::create(['service_name' => 'Ambulance/Medical Response']);

        $this->request = ServiceRequest::create([
            'resident_id' => $this->owner->resident_id,
            'service_id' => $service->service_id,
            'description' => 'Test',
            'internal_notes' => 'Operator scratch pad',
            'status' => 'Pending',
        ]);

        $equipment = Equipment::create([
            'item_name' => 'Generator',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);

        $this->borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->owner->resident_id,
            'equipment_id' => $equipment->equipment_id,
            'quantity' => 1,
            'purpose' => 'Power outage',
            'status' => 'Pending',
        ]);
    }

    private function resident(int $barangayId, string $email, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $barangayId,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'email_address' => $email,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    /** Role defaults to 'Admin', the casing AdminController and the seeders actually write. */
    private function admin(string $email, array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => $email,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ], $overrides));
    }

    /** Deactivated straight on the column, exactly as a direct database edit leaves it: tokens untouched. */
    private function deactivatedAdmin(string $email): User
    {
        $admin = $this->admin($email);
        User::where('admin_id', $admin->admin_id)->update(['status' => 'Inactive']);

        return $admin->fresh();
    }

    // ---- GET /api/service-requests/{id} ------------------------------------

    public function test_the_owner_reads_their_own_request(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/service-requests/{$this->request->request_id}")
            ->assertOk()
            ->assertJsonPath('request_id', $this->request->request_id)
            // Staff-only column, hidden for the resident branch.
            ->assertJsonMissingPath('internal_notes');
    }

    public function test_an_active_admin_reads_any_request(): void
    {
        Sanctum::actingAs($this->admin('active@test.local'));

        $this->getJson("/api/service-requests/{$this->request->request_id}")
            ->assertOk()
            ->assertJsonPath('internal_notes', 'Operator scratch pad');
    }

    public function test_another_resident_gets_a_404_for_a_request_that_is_not_theirs(): void
    {
        Sanctum::actingAs($this->stranger);

        $this->getJson("/api/service-requests/{$this->request->request_id}")->assertStatus(404);
    }

    public function test_a_deactivated_admin_cannot_read_a_request(): void
    {
        Sanctum::actingAs($this->deactivatedAdmin('closed@test.local'));

        $this->getJson("/api/service-requests/{$this->request->request_id}")->assertStatus(403);
    }

    public function test_a_user_row_that_is_not_an_admin_cannot_read_a_request(): void
    {
        Sanctum::actingAs($this->admin('viewer@test.local', ['role' => 'viewer']));

        $this->getJson("/api/service-requests/{$this->request->request_id}")->assertStatus(404);
    }

    // ---- PATCH /api/service-requests/{id}/cancel ---------------------------

    public function test_the_owner_cancels_their_own_request(): void
    {
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/service-requests/{$this->request->request_id}/cancel")->assertOk();

        $this->assertSame('Cancelled', $this->request->fresh()->status);
    }

    public function test_another_resident_cannot_cancel_a_request(): void
    {
        Sanctum::actingAs($this->stranger);

        $this->patchJson("/api/service-requests/{$this->request->request_id}/cancel")->assertStatus(404);

        $this->assertSame('Pending', $this->request->fresh()->status);
    }

    /**
     * The write half of the hole, and the one that mattered most: this account
     * could cancel any resident's pending or booked request outright.
     */
    public function test_a_deactivated_admin_cannot_cancel_a_request(): void
    {
        Sanctum::actingAs($this->deactivatedAdmin('closed-cancel@test.local'));

        $this->patchJson("/api/service-requests/{$this->request->request_id}/cancel")->assertStatus(403);

        $this->assertSame('Pending', $this->request->fresh()->status);
    }

    public function test_a_user_row_that_is_not_an_admin_cannot_cancel_a_request(): void
    {
        Sanctum::actingAs($this->admin('viewer-cancel@test.local', ['role' => 'viewer']));

        $this->patchJson("/api/service-requests/{$this->request->request_id}/cancel")->assertStatus(404);

        $this->assertSame('Pending', $this->request->fresh()->status);
    }

    // ---- GET /api/borrowings -----------------------------------------------

    public function test_a_resident_lists_only_their_own_borrowings(): void
    {
        Sanctum::actingAs($this->stranger);

        // Count, not the shape: an empty list and a full one both come back as
        // a bare JSON array, so only the row count tells them apart.
        $this->getJson('/api/borrowings')->assertOk()->assertJsonCount(0);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/borrowings')->assertOk()->assertJsonCount(1);
    }

    public function test_an_active_admin_lists_every_borrowing(): void
    {
        Sanctum::actingAs($this->admin('active-list@test.local'));

        $this->getJson('/api/borrowings')->assertOk()->assertJsonCount(1);
    }

    public function test_a_deactivated_admin_cannot_list_borrowings(): void
    {
        Sanctum::actingAs($this->deactivatedAdmin('closed-list@test.local'));

        $this->getJson('/api/borrowings')->assertStatus(403);
    }

    public function test_a_user_row_that_is_not_an_admin_cannot_list_borrowings(): void
    {
        Sanctum::actingAs($this->admin('viewer-list@test.local', ['role' => 'viewer']));

        $this->getJson('/api/borrowings')->assertStatus(404);
    }

    // ---- GET /api/borrowings/{id} ------------------------------------------

    public function test_the_owner_reads_their_own_borrowing(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/borrowings/{$this->borrowing->borrow_id}")
            ->assertOk()
            ->assertJsonPath('borrow_id', $this->borrowing->borrow_id);
    }

    public function test_an_active_admin_reads_any_borrowing(): void
    {
        Sanctum::actingAs($this->admin('active-show@test.local'));

        $this->getJson("/api/borrowings/{$this->borrowing->borrow_id}")->assertOk();
    }

    public function test_another_resident_gets_a_404_for_a_borrowing_that_is_not_theirs(): void
    {
        Sanctum::actingAs($this->stranger);

        $this->getJson("/api/borrowings/{$this->borrowing->borrow_id}")->assertStatus(404);
    }

    public function test_a_deactivated_admin_cannot_read_a_borrowing(): void
    {
        Sanctum::actingAs($this->deactivatedAdmin('closed-show@test.local'));

        $this->getJson("/api/borrowings/{$this->borrowing->borrow_id}")->assertStatus(403);
    }

    public function test_a_user_row_that_is_not_an_admin_cannot_read_a_borrowing(): void
    {
        Sanctum::actingAs($this->admin('viewer-show@test.local', ['role' => 'viewer']));

        $this->getJson("/api/borrowings/{$this->borrowing->borrow_id}")->assertStatus(404);
    }

    // ---- PATCH /api/borrowings/{id}/cancel ---------------------------------
    //
    // The fifth route on this guard, and the second one that writes. The
    // status rules themselves live in EquipmentBorrowingCancelTest; what is
    // pinned here is only who gets past scopeToOwner, because an unscoped
    // version of this route would let any non-resident token close any
    // resident's request.

    public function test_the_owner_cancels_their_own_borrowing(): void
    {
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/borrowings/{$this->borrowing->borrow_id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'Cancelled');
    }

    public function test_another_resident_cannot_cancel_a_borrowing_that_is_not_theirs(): void
    {
        Sanctum::actingAs($this->stranger);

        $this->patchJson("/api/borrowings/{$this->borrowing->borrow_id}/cancel")->assertStatus(404);

        $this->assertSame('Pending', $this->borrowing->fresh()->status);
    }

    public function test_a_deactivated_admin_cannot_cancel_a_borrowing(): void
    {
        Sanctum::actingAs($this->deactivatedAdmin('closed-borrow-cancel@test.local'));

        $this->patchJson("/api/borrowings/{$this->borrowing->borrow_id}/cancel")->assertStatus(403);

        $this->assertSame('Pending', $this->borrowing->fresh()->status);
    }

    public function test_a_user_row_that_is_not_an_admin_cannot_cancel_a_borrowing(): void
    {
        Sanctum::actingAs($this->admin('viewer-borrow-cancel@test.local', ['role' => 'viewer']));

        $this->patchJson("/api/borrowings/{$this->borrowing->borrow_id}/cancel")->assertStatus(404);

        $this->assertSame('Pending', $this->borrowing->fresh()->status);
    }
}
