<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two real places stock can go from nothing to something: a return
 * (EquipmentBorrowingController::update) and a manual admin stock edit
 * (EquipmentController::update). Both must reach a resident waiting on the
 * same item, denied earlier for exactly that reason (MDRRMO feedback,
 * 2026-09-18).
 */
class EquipmentAvailabilityNotifierIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

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
            'available_quantity' => 0,
            'status' => 'Available',
        ]);
    }

    private function resident(string $phone = '09171111111'): Resident
    {
        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'email_address' => uniqid('resident').'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    private function deniedForUnavailability(Resident $resident): EquipmentBorrowing
    {
        return EquipmentBorrowing::create([
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Denied',
            'denial_reason' => 'None left this week.',
            'denial_reason_code' => 'Unavailable',
        ]);
    }

    public function test_a_return_notifies_a_resident_waiting_on_the_same_equipment(): void
    {
        $waiting = $this->deniedForUnavailability($this->resident('09171111111'));

        $borrower = $this->resident('09172222222');
        $active = EquipmentBorrowing::create([
            'resident_id' => $borrower->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Released',
            'due_date' => now('Asia/Manila')->addDay()->format('Y-m-d'),
        ]);
        $this->equipment->update(['available_quantity' => 0]);

        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$active->getKey()}", [
                'status' => 'Returned',
                'return_condition_note' => 'Came back in working order.',
            ])
            ->assertOk();

        $this->assertNotNull($waiting->fresh()->availability_reconfirm_sent_at);
    }

    public function test_a_manual_stock_increase_from_zero_notifies_a_waiting_resident(): void
    {
        $waiting = $this->deniedForUnavailability($this->resident());

        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->actingAs($this->admin)
            ->putJson("/api/equipments/{$this->equipment->getKey()}", ['available_quantity' => 3])
            ->assertOk();

        $this->assertNotNull($waiting->fresh()->availability_reconfirm_sent_at);
    }

    public function test_denial_reason_code_is_stored_alongside_the_free_text_reason(): void
    {
        $resident = $this->resident();
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Pending',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Denied',
                'denial_reason' => 'None left this week.',
                'denial_reason_code' => 'Unavailable',
            ])
            ->assertOk();

        $fresh = $borrowing->fresh();
        $this->assertSame('Unavailable', $fresh->denial_reason_code);
        $this->assertSame('None left this week.', $fresh->denial_reason);
    }

    /** Denied is terminal for equipment borrowing (TRANSITIONS['Denied'] => []) — a fresh Pending request can still carry no code at all. */
    public function test_denying_without_a_reason_code_is_still_accepted(): void
    {
        $resident = $this->resident();
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Pending',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/borrowings/{$borrowing->getKey()}", [
                'status' => 'Denied',
                'denial_reason' => 'Not eligible.',
            ])
            ->assertOk();

        $this->assertNull($borrowing->fresh()->denial_reason_code);
    }
}
