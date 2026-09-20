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
 * Account types: head of the family, barangay, organization. Only an admin can
 * create the last two; everything an existing account was stays a head of the
 * family.
 */
class AccountTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Miguel']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Rosa',
            'last_name' => 'Dizon',
            'phone_number' => '09173333333',
            'email_address' => 'rosa@test.local',
            'password' => 'Password123',
            'status' => 'Active',
        ], $overrides);
    }

    private function account(array $overrides = []): Resident
    {
        $resident = new Resident(array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ], $overrides['fill'] ?? []));

        foreach ($overrides['type'] ?? [] as $column => $value) {
            $resident->{$column} = $value;
        }
        $resident->save();

        return $resident->fresh();
    }

    public function test_an_account_with_no_type_is_a_head_of_the_family(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('account_type', 'head_of_family')
            ->assertJsonPath('organization_name', null);
    }

    public function test_admin_can_create_a_barangay_account(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', $this->payload([
            'account_type' => 'barangay',
        ]))->assertStatus(201)->assertJsonPath('account_type', 'barangay');
    }

    public function test_a_barangay_can_only_have_one_account(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', $this->payload([
            'account_type' => 'barangay',
        ]))->assertStatus(201);

        $this->actingAs($this->admin)->postJson('/api/residents', $this->payload([
            'account_type' => 'barangay',
            'email_address' => 'second@test.local',
            'phone_number' => '09174444444',
        ]))->assertStatus(422)->assertJsonValidationErrors(['account_type']);

        $this->assertSame(1, Resident::where('account_type', 'barangay')->count());
    }

    public function test_an_organization_needs_a_name(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', $this->payload([
            'account_type' => 'organization',
        ]))->assertStatus(422)->assertJsonValidationErrors(['organization_name']);

        $this->actingAs($this->admin)->postJson('/api/residents', $this->payload([
            'account_type' => 'organization',
            'organization_name' => 'Isabela State University',
        ]))->assertStatus(201)->assertJsonPath('organization_name', 'Isabela State University');
    }

    public function test_an_unknown_account_type_is_refused(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', $this->payload([
            'account_type' => 'office',
        ]))->assertStatus(422)->assertJsonValidationErrors(['account_type']);
    }

    public function test_switching_away_from_organization_clears_the_name(): void
    {
        $org = $this->account(['type' => [
            'account_type' => 'organization',
            'organization_name' => 'PNP Echague',
        ]]);

        $this->actingAs($this->admin)->putJson("/api/residents/{$org->resident_id}", $this->payload([
            'email_address' => 'maria@test.local',
            'account_type' => 'head_of_family',
        ]))->assertOk();

        $this->assertNull($org->fresh()->organization_name);
    }

    public function test_an_update_that_omits_the_type_keeps_it(): void
    {
        $org = $this->account(['type' => [
            'account_type' => 'organization',
            'organization_name' => 'PNP Echague',
        ]]);

        $this->actingAs($this->admin)->putJson("/api/residents/{$org->resident_id}", $this->payload([
            'email_address' => 'maria@test.local',
            'first_name' => 'Renamed',
        ]))->assertOk();

        $this->assertSame('organization', $org->fresh()->account_type);
        $this->assertSame('PNP Echague', $org->fresh()->organization_name);
    }

    public function test_self_registration_cannot_choose_a_type(): void
    {
        // account_type is not fillable and /register never reads it: whatever a
        // client sends, the row that results is a head of the family.
        $resident = new Resident($this->payload(['account_type' => 'barangay']));
        $resident->password = Hash::make('password123');
        $resident->save();

        $this->assertSame('head_of_family', $resident->fresh()->account_type);
    }

    public function test_barangay_and_organization_accounts_are_not_text_blast_recipients(): void
    {
        $this->account();
        $this->account(['fill' => ['email_address' => 'hall@test.local', 'phone_number' => '09175555555'],
            'type' => ['account_type' => 'barangay']]);
        $this->account(['fill' => ['email_address' => 'isu@test.local', 'phone_number' => '09176666666'],
            'type' => ['account_type' => 'organization', 'organization_name' => 'ISU']]);

        $this->actingAs($this->admin)
            ->getJson('/api/sms/recipient-count?barangays[]='.$this->barangay->barangay_id)
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_the_dashboard_resident_count_is_heads_of_the_family_only(): void
    {
        $this->account();
        $this->account(['fill' => ['email_address' => 'hall@test.local', 'phone_number' => '09175555555'],
            'type' => ['account_type' => 'barangay']]);

        $this->actingAs($this->admin)->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Total Residents', 'value' => '1']);
    }

    public function test_borrower_type_comes_from_the_account_not_the_request(): void
    {
        $equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);
        $body = ['equipment_id' => $equipment->getKey(), 'quantity' => 1, 'purpose' => 'Drill'];

        $head = $this->account();
        $this->actingAs($head)->postJson('/api/borrowings', $body + [
            'borrower_type' => 'Organization', 'organization_name' => 'Claimed Group',
        ])->assertStatus(201)->assertJsonPath('borrower_type', 'Resident')->assertJsonPath('organization_name', null);

        $hall = $this->account(['fill' => ['email_address' => 'hall@test.local', 'phone_number' => '09175555555'],
            'type' => ['account_type' => 'barangay']]);
        $this->actingAs($hall)->postJson('/api/borrowings', $body)
            ->assertStatus(201)
            ->assertJsonPath('borrower_type', 'Organization')
            ->assertJsonPath('organization_name', 'Barangay San Miguel');

        $isu = $this->account(['fill' => ['email_address' => 'isu@test.local', 'phone_number' => '09176666666'],
            'type' => ['account_type' => 'organization', 'organization_name' => 'Isabela State University']]);
        $this->actingAs($isu)->postJson('/api/borrowings', $body)
            ->assertStatus(201)
            ->assertJsonPath('organization_name', 'Isabela State University');

        $this->assertSame(3, EquipmentBorrowing::count());
    }
}
