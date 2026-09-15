<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DELETE /api/residents/{id} — every RESTRICT key onto tbl_residents answers
 * 422 with a count, never the uncaught 500 the foreign key used to raise.
 */
class ResidentDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    private Resident $resident;

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

        $this->resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
    }

    public function test_a_resident_with_no_references_deletes(): void
    {
        $this->deleteJson("/api/residents/{$this->resident->resident_id}")->assertStatus(200);

        $this->assertNull(Resident::find($this->resident->resident_id));
    }

    public function test_an_activity_log_row_blocks_the_delete(): void
    {
        // What the resident's own write leaves behind — a borrowing, a profile edit.
        DB::table('tbl_system_logs')->insert([
            'resident_id' => $this->resident->resident_id,
            'action_type' => 'updated',
            'auditable_type' => Resident::class,
            'auditable_id' => $this->resident->resident_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/residents/{$this->resident->resident_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete — 1 activity log record(s) still reference this resident. Set their status to Deactivated instead.');

        $this->assertNotNull(Resident::find($this->resident->resident_id));
    }

    public function test_an_sms_delivery_row_blocks_the_delete(): void
    {
        $smsLogId = DB::table('tbl_sms_logs')->insertGetId([
            'sender_id' => $this->admin->admin_id,
            'target_area_id' => $this->barangay->barangay_id,
            'message_body' => 'Evacuate now.',
            'status' => 'Sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tbl_recipients')->insert([
            'sms_log_id' => $smsLogId,
            'resident_id' => $this->resident->resident_id,
            'status' => 'Sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/residents/{$this->resident->resident_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete — 1 SMS delivery record(s) still reference this resident. Set their status to Deactivated instead.');

        $this->assertNotNull(Resident::find($this->resident->resident_id));
    }
}
