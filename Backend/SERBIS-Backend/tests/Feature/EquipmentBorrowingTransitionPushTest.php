<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PUT /api/borrowings/{id} — push on Pending -> Approved, Pending/Approved ->
 * Denied, and Approved -> Released. Uses the same Fcm::notifyResident()
 * extracted for ServiceRequestController — no duplicated device-token lookup
 * here.
 */
class EquipmentBorrowingTransitionPushTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

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

        $this->actingAs($this->admin);

        Cache::put('fcm_access_token', 'fake-access-token', 3000);

        $path = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($path, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $path]);

        DeviceToken::create([
            'resident_id' => $this->resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);
    }

    private function borrowingAt(string $status, array $overrides = []): EquipmentBorrowing
    {
        return EquipmentBorrowing::create(array_merge([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => $status,
        ], $overrides));
    }

    public function test_approval_pushes_naming_the_item(): void
    {
        $borrowing = $this->borrowingAt('Pending');

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Approved'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Rubber Boat')
            && str_contains($sent['message']['notification']['body'], 'approved'));
    }

    public function test_denial_from_pending_pushes_with_the_reason(): void
    {
        $borrowing = $this->borrowingAt('Pending');

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", [
            'status' => 'Denied',
            'denial_reason' => 'No units available this week.',
        ])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Rubber Boat')
            && str_contains($sent['message']['notification']['body'], 'not approved')
            && str_contains($sent['message']['notification']['body'], 'No units available this week.'));
    }

    public function test_denial_from_approved_pushes_too(): void
    {
        $borrowing = $this->borrowingAt('Approved');

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Denied'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'not approved'));
    }

    /** denial_reason is optional — the sentence must not carry a dangling "Reason:" when none was given. */
    public function test_denial_with_no_reason_omits_the_reason_clause(): void
    {
        $borrowing = $this->borrowingAt('Approved');

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Denied'])->assertOk();

        Http::assertSent(fn ($sent) => ! str_contains($sent['message']['notification']['body'], 'Reason:'));
    }

    public function test_release_for_pickup_says_pickup(): void
    {
        $borrowing = $this->borrowingAt('Approved', ['fulfillment_method' => 'Pickup']);

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Released'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'ready for pickup'));
    }

    public function test_release_for_delivery_says_delivered(): void
    {
        $borrowing = $this->borrowingAt('Approved', ['fulfillment_method' => 'Delivery']);

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Released'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'delivered')
            && ! str_contains($sent['message']['notification']['body'], 'pickup'));
    }

    /** Returned is transition 12 — explicitly not in scope for this batch. */
    public function test_return_sends_no_push(): void
    {
        $borrowing = $this->borrowingAt('Released', ['released_at' => now()]);

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", [
            'status' => 'Returned',
            'return_condition_note' => 'Came back in working order.',
        ])->assertOk();

        Http::assertNothingSent();
    }

    /** An uncatalogued ("Other") request still gets a push naming what was actually typed in. */
    public function test_approval_of_an_uncatalogued_item_names_the_free_text(): void
    {
        $borrowing = $this->borrowingAt('Pending', ['equipment_id' => null, 'other_equipment_text' => 'Portable generator']);

        $this->putJson("/api/borrowings/{$borrowing->getKey()}", ['status' => 'Approved'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Portable generator'));
    }
}
