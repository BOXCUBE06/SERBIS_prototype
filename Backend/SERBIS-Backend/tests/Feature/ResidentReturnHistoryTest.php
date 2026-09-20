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
 * GET /api/residents/{id}/return-history — how a resident's past loans came
 * back, for the admin resident detail panel. Display only: nothing here blocks
 * a borrow, so the tests pin what is shown and who may see it.
 */
class ResidentReturnHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Resident $other;

    private User $admin;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        $this->resident = $this->makeResident($barangay, 'Maria', '09171111111');
        $this->other = $this->makeResident($barangay, 'Jose', '09172222222');

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

    private function makeResident(Barangay $barangay, string $name, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => $name,
            'last_name' => 'Test',
            'phone_number' => $phone,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    private function loan(Resident $resident, string $status, array $extra = []): EquipmentBorrowing
    {
        // The photo path is not fillable — only the upload endpoint writes it.
        $photoPath = $extra['return_photo_path'] ?? null;
        unset($extra['return_photo_path']);

        $borrowing = EquipmentBorrowing::create($extra + [
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Barangay flood drill',
            'status' => $status,
        ]);

        if ($photoPath !== null) {
            $borrowing->forceFill(['return_photo_path' => $photoPath])->save();
        }

        return $borrowing;
    }

    public function test_lists_returned_loans_newest_first_with_condition_note_and_counts(): void
    {
        $this->loan($this->resident, 'Returned', [
            'returned_at' => '2026-08-01 10:00:00',
            'return_condition' => 'Good',
            'return_condition_note' => 'Clean, complete.',
        ]);
        $newest = $this->loan($this->resident, 'Returned', [
            'returned_at' => '2026-09-10 10:00:00',
            'return_condition' => 'Bad',
            'return_condition_note' => 'Torn on one side.',
            'return_photo_path' => 'borrowing-photos/1/return.jpg',
        ]);
        // Returned before the condition column existed.
        $this->loan($this->resident, 'Returned', ['returned_at' => '2026-07-01 10:00:00']);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/residents/{$this->resident->getKey()}/return-history")->assertOk();

        $response->assertJsonPath('summary', ['total' => 3, 'good' => 1, 'bad' => 1, 'unrecorded' => 1]);
        $response->assertJsonPath('data.0.borrow_id', $newest->borrow_id);
        $response->assertJsonPath('data.0.item', 'Rubber Boat');
        $response->assertJsonPath('data.0.return_condition', 'Bad');
        $response->assertJsonPath('data.0.return_condition_note', 'Torn on one side.');
        $response->assertJsonPath('data.0.has_return_photo', true);
        $response->assertJsonPath('data.1.has_return_photo', false);
    }

    public function test_leaves_out_loans_that_are_not_returned_and_other_residents(): void
    {
        $this->loan($this->resident, 'Released');
        $this->loan($this->resident, 'Denied');
        $this->loan($this->other, 'Returned', ['return_condition' => 'Bad', 'return_condition_note' => 'Broken.']);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/residents/{$this->resident->getKey()}/return-history")
            ->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_names_an_uncatalogued_item_by_what_the_resident_typed(): void
    {
        $this->loan($this->resident, 'Returned', [
            'equipment_id' => null,
            'other_equipment_text' => 'Extension ladder',
            'return_condition' => 'Good',
            'return_condition_note' => 'Fine.',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/residents/{$this->resident->getKey()}/return-history")
            ->assertOk()
            ->assertJsonPath('data.0.item', 'Extension ladder');
    }

    public function test_never_exposes_the_stored_photo_path(): void
    {
        $this->loan($this->resident, 'Returned', [
            'return_condition' => 'Good',
            'return_condition_note' => 'Fine.',
            'return_photo_path' => 'borrowing-photos/1/secret-name.jpg',
        ]);

        Sanctum::actingAs($this->admin);

        $body = $this->getJson("/api/residents/{$this->resident->getKey()}/return-history")->assertOk()->getContent();

        $this->assertStringNotContainsString('secret-name', $body);
        $this->assertStringNotContainsString('return_photo_path', $body);
    }

    public function test_a_resident_cannot_read_it(): void
    {
        Sanctum::actingAs($this->resident);

        $this->getJson("/api/residents/{$this->resident->getKey()}/return-history")->assertForbidden();
    }

    public function test_an_unknown_resident_is_a_404(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/residents/999999/return-history')->assertNotFound();
    }
}
