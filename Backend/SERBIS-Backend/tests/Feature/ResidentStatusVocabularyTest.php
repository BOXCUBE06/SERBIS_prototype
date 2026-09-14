<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * tbl_residents.status — the admin CRUD is the only thing that writes it, and
 * until now it accepted any string at all.
 *
 * The column carries three values. Self-registration writes 'Inactive'
 * (AuthController::register), the admin toggle writes 'Deactivated', and
 * activation writes 'Active'. Nothing else is meaningful, but both
 * ResidentController::store() and update() validated 'required|string', so
 * 'banana' stored as cleanly as 'Active' did.
 *
 * That mattered because of who reads the column. SmsController::sendBlast()
 * is the only functional reader and it matches 'Active' exactly, so a junk or
 * miscased status silently removes the resident from every MDRRMO text blast
 * — no error, no log, and an admin list that still shows the row. Resident
 * login deliberately does not check status at all (AuthController::login),
 * so the account keeps working and nobody discovers the problem from the
 * resident's side either.
 *
 * Both write paths in the panel land on update(): the list's status toggle
 * and the edit form's radio, both PUT /api/residents/{id}. The matching
 * vocabulary lives in Web/serbis-admin-vue/src/composables/residentStatus.ts.
 */
class ResidentStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        // 'Admin', not 'admin'. AdminController writes the capitalised value
        // and User::isAdmin() compares against it; a lowercase fixture makes
        // the guard behave differently under test than in production.
        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    /** @return array<string, mixed> */
    private function createPayload(string $status): array
    {
        return [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'jose@test.local',
            'password' => 'Password123',
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(string $status): array
    {
        return [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'barangay_id' => $this->barangay->barangay_id,
            'status' => $status,
        ];
    }

    /** @return array<string, array{string}> */
    public static function validStatuses(): array
    {
        return [
            'active' => ['Active'],
            'inactive' => ['Inactive'],
            'deactivated' => ['Deactivated'],
        ];
    }

    /**
     * Miscasing is the realistic failure, not gibberish. 'active' looks
     * correct everywhere it is displayed and is invisible to the blast query.
     *
     * @return array<string, array{string}>
     */
    public static function rejectedStatuses(): array
    {
        return [
            'lowercase active' => ['active'],
            'uppercase active' => ['ACTIVE'],
            'lowercase deactivated' => ['deactivated'],
            'a label rather than a value' => ['Pending'],
            'nonsense' => ['banana'],
            'empty' => [''],
        ];
    }

    #[DataProvider('validStatuses')]
    public function test_create_accepts_each_value_the_column_actually_carries(string $status): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/residents', $this->createPayload($status))
            ->assertStatus(201);

        $this->assertSame(
            $status,
            Resident::where('email_address', 'jose@test.local')->first()->status
        );
    }

    #[DataProvider('rejectedStatuses')]
    public function test_create_rejects_anything_outside_the_vocabulary(string $status): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/residents', $this->createPayload($status))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertNull(Resident::where('email_address', 'jose@test.local')->first());
    }

    #[DataProvider('validStatuses')]
    public function test_update_accepts_each_value_the_column_actually_carries(string $status): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/residents/'.$this->resident->getKey(), $this->updatePayload($status))
            ->assertOk();

        $this->assertSame($status, $this->resident->fresh()->status);
    }

    #[DataProvider('rejectedStatuses')]
    public function test_update_rejects_anything_outside_the_vocabulary(string $status): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/residents/'.$this->resident->getKey(), $this->updatePayload($status))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // The stored value is what SmsController::sendBlast() matches on, so
        // the point of the rule is that a rejected request leaves it alone.
        $this->assertSame('Active', $this->resident->fresh()->status);
    }
}
