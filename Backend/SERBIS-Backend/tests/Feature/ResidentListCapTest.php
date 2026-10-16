<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * GET /service-requests and GET /borrowings serve residents and staff from one
 * method. The cap is on the resident's own history only: the admin panel counts
 * over the whole table (the board, the dashboard's overdue figure), so a cap in
 * the shared query would silently shorten those.
 */
class ResidentListCapTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    /** The documented cap, written out so a change to the constant has to change this test too. */
    private const CAP = 50;

    private const MORE_THAN_CAP = 60;

    private Resident $resident;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->resident = $this->makeResident('09171111111', $barangay);
        $this->equipment = Equipment::create(['item_name' => 'Generator', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available']);
    }

    private function makeResident(string $phone, Barangay $barangay): Resident
    {
        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    /**
     * $count borrowings, the first the oldest and the last the newest.
     *
     * @return list<int> ids, oldest first
     */
    private function borrowings(Resident $resident, int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $borrowing = EquipmentBorrowing::create([
                'resident_id' => $resident->resident_id,
                'equipment_id' => $this->equipment->equipment_id,
                'quantity' => 1,
                'purpose' => 'Drill',
                'status' => 'Pending',
            ]);
            EquipmentBorrowing::query()->whereKey($borrowing->getKey())->update(['created_at' => now()->subMinutes($count - $i)]);
            $ids[] = $borrowing->getKey();
        }

        return $ids;
    }

    /** @return list<int> ids, oldest first */
    private function requests(Resident $resident, int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $request = ServiceRequest::create(['resident_id' => $resident->resident_id, 'description' => 'Help', 'status' => 'Pending']);
            ServiceRequest::query()->whereKey($request->getKey())->update(['created_at' => now()->subMinutes($count - $i)]);
            $ids[] = $request->getKey();
        }

        return $ids;
    }

    public function test_a_resident_gets_their_latest_fifty_borrowings_newest_first(): void
    {
        $ids = $this->borrowings($this->resident, self::MORE_THAN_CAP);
        // Someone else's rows never count towards, or appear in, the list.
        $this->borrowings($this->makeResident('09172222222', Barangay::first()), 3);

        Sanctum::actingAs($this->resident);
        $response = $this->getJson('/api/borrowings')->assertOk();

        $response->assertJsonCount(self::CAP);
        $this->assertSame(
            array_slice(array_reverse($ids), 0, self::CAP),
            array_column($response->json(), 'borrow_id'),
        );
    }

    public function test_an_admin_still_gets_every_borrowing(): void
    {
        $this->borrowings($this->resident, self::MORE_THAN_CAP);

        Sanctum::actingAs($this->makeSuperAdmin());

        $this->getJson('/api/borrowings')->assertOk()->assertJsonCount(self::MORE_THAN_CAP);
    }

    public function test_a_resident_gets_their_latest_fifty_service_requests_newest_first(): void
    {
        $ids = $this->requests($this->resident, self::MORE_THAN_CAP);

        Sanctum::actingAs($this->resident);
        $response = $this->getJson('/api/service-requests')->assertOk();

        $response->assertJsonCount(self::CAP);
        $this->assertSame(
            array_slice(array_reverse($ids), 0, self::CAP),
            array_column($response->json(), 'request_id'),
        );
    }

    public function test_an_admin_still_gets_every_service_request_from_both_lists(): void
    {
        $this->requests($this->resident, self::MORE_THAN_CAP);

        Sanctum::actingAs($this->makeSuperAdmin());

        $this->getJson('/api/service-requests')->assertOk()->assertJsonCount(self::MORE_THAN_CAP);
        $this->getJson('/api/admin/service-requests')->assertOk()->assertJsonCount(self::MORE_THAN_CAP, 'data');
    }
}
