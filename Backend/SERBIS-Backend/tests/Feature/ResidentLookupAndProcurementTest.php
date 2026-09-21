<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * The two narrow endpoints that let a page draw a list without holding the
 * section that owns the wider data: the resident picker on the request boards,
 * and the Procurement Reference page.
 */
class ResidentLookupAndProcurementTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private Resident $resident;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'street_address' => 'Purok 3',
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'phone_number' => '+639171234567',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->equipment = Equipment::create([
            'item_name' => 'Generator',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);
    }

    // ---- residents/lookup --------------------------------------------------

    public function test_the_lookup_returns_an_id_a_name_and_a_barangay_and_nothing_else(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $row = $this->getJson('/api/residents/lookup')->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['resident_id', 'first_name', 'last_name', 'barangay_id', 'barangay'],
            array_keys($row)
        );
        $this->assertSame('San Fabian', $row['barangay']['barangay_name']);
        $this->assertSame($this->resident->resident_id, $row['resident_id']);
    }

    public function test_the_lookup_never_carries_contact_details(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::AMBULANCE]));

        $body = $this->getJson('/api/residents/lookup')->assertOk()->getContent();

        foreach (['+639171234567', 'maria@test.local', 'Purok 3', 'account_type', 'password'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }

    public function test_the_lookup_is_sorted_by_name(): void
    {
        Resident::create([
            'barangay_id' => $this->resident->barangay_id,
            'first_name' => 'Ana',
            'last_name' => 'Abad',
            'phone_number' => '+639170000002',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $names = collect($this->getJson('/api/residents/lookup')->json('data'))->pluck('last_name')->all();

        $this->assertSame(['Abad', 'Cruz'], $names);
    }

    public function test_the_lookup_is_open_to_the_pages_that_need_it_and_no_others(): void
    {
        foreach ([AdminSections::RESIDENTS, AdminSections::REQUESTS, AdminSections::AMBULANCE] as $section) {
            Sanctum::actingAs($this->makeLimitedStaff([$section], "{$section}@test.local"));
            $this->getJson('/api/residents/lookup')->assertOk();
        }

        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::BORROWINGS, AdminSections::SMS], 'other@test.local'));
        $this->getJson('/api/residents/lookup')->assertStatus(403);
    }

    public function test_holding_a_request_board_does_not_open_the_resident_directory(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS, AdminSections::AMBULANCE]));

        $this->getJson('/api/residents')->assertStatus(403)->assertJsonPath('code', 'section_forbidden');
    }

    public function test_lookup_is_not_read_as_a_resident_id(): void
    {
        // Registered ahead of the resource's {id} route; a swap would 404 here.
        Sanctum::actingAs($this->makeStaff());

        $this->getJson('/api/residents/lookup')->assertOk()->assertJsonStructure(['data']);
    }

    // ---- procurement/other-equipment ---------------------------------------

    private function borrowing(array $overrides): EquipmentBorrowing
    {
        return EquipmentBorrowing::create(array_merge([
            'resident_id' => $this->resident->resident_id,
            'quantity' => 2,
            'purpose' => 'Barangay flood drill',
            'status' => 'Pending',
        ], $overrides));
    }

    public function test_procurement_lists_only_the_requests_for_items_the_catalogue_lacks(): void
    {
        $this->borrowing(['equipment_id' => $this->equipment->equipment_id]);
        $wanted = $this->borrowing(['other_equipment_text' => 'Chainsaw with a 20-inch bar']);

        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::PROCUREMENT]));

        $rows = $this->getJson('/api/procurement/other-equipment')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($wanted->borrow_id, $rows[0]['borrow_id']);
        $this->assertSame('Chainsaw with a 20-inch bar', $rows[0]['other_equipment_text']);
    }

    public function test_procurement_carries_only_what_the_page_draws(): void
    {
        $this->borrowing(['other_equipment_text' => 'Chainsaw']);
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::PROCUREMENT]));

        $response = $this->getJson('/api/procurement/other-equipment')->assertOk();
        $row = $response->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['borrow_id', 'resident_id', 'other_equipment_text', 'purpose', 'quantity', 'status', 'created_at', 'resident'],
            array_keys($row)
        );
        $this->assertSame('San Fabian', $row['resident']['barangay']['barangay_name']);

        $body = $response->getContent();
        foreach (['+639171234567', 'maria@test.local', 'Purok 3'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }

    public function test_procurement_is_newest_first(): void
    {
        $older = $this->borrowing(['other_equipment_text' => 'Older']);
        $older->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->borrowing(['other_equipment_text' => 'Newer']);

        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::PROCUREMENT]));

        $items = collect($this->getJson('/api/procurement/other-equipment')->json('data'))->pluck('other_equipment_text')->all();

        $this->assertSame(['Newer', 'Older'], $items);
    }

    public function test_procurement_needs_its_own_section_and_borrowings_does_not_open_it(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::BORROWINGS]));

        $this->getJson('/api/procurement/other-equipment')->assertStatus(403)->assertJsonPath('code', 'section_forbidden');
    }

    public function test_holding_procurement_does_not_open_the_borrowings_list(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::PROCUREMENT]));

        $this->getJson('/api/borrowings')->assertStatus(403)->assertJsonPath('code', 'section_forbidden');
    }
}
