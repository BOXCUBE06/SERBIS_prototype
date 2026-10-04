<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DELETE /api/barangays/{id} — a barangay still named by a resident, a service
 * request or an SMS blast answers 422 with a count instead of the foreign key's 500.
 */
class BarangayDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);
        Sanctum::actingAs($this->admin);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    public function test_an_unused_barangay_deletes(): void
    {
        $this->deleteJson("/api/barangays/{$this->barangay->barangay_id}")->assertStatus(200);

        $this->assertNull(Barangay::find($this->barangay->barangay_id));
    }

    public function test_a_resident_blocks_the_delete(): void
    {
        Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->deleteJson("/api/barangays/{$this->barangay->barangay_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete — 1 resident(s) still reference this barangay.');

        $this->assertNotNull(Barangay::find($this->barangay->barangay_id));
    }

    public function test_an_sms_blast_blocks_the_delete(): void
    {
        DB::table('tbl_sms_logs')->insert([
            'sender_id' => $this->admin->admin_id,
            'target_area_id' => $this->barangay->barangay_id,
            'message_body' => 'Evacuate now.',
            'status' => 'Sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/barangays/{$this->barangay->barangay_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete — 1 SMS blast(s) still reference this barangay.');

        $this->assertNotNull(Barangay::find($this->barangay->barangay_id));
    }

    public function test_a_request_filed_there_blocks_the_delete_after_the_resident_moves(): void
    {
        $elsewhere = Barangay::create(['barangay_name' => 'San Miguel']);
        $resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        ServiceRequest::create(['resident_id' => $resident->getKey(), 'status' => 'Pending']);
        // Nobody lives there now; the request still points at it.
        $resident->update(['barangay_id' => $elsewhere->barangay_id]);

        $this->deleteJson("/api/barangays/{$this->barangay->barangay_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete — 1 service request(s) were filed under this barangay.');

        $this->assertNotNull(Barangay::find($this->barangay->barangay_id));
    }
}
