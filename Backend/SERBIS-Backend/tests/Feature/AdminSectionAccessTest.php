<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * Which sections an account may open: User::allowedSections() and the two
 * columns behind it.
 */
class AdminSectionAccessTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    public function test_an_account_with_no_permission_list_has_every_assignable_section(): void
    {
        // What every account that existed before permissions did keeps, and what
        // a hand-written INSERT leaves behind.
        $admin = $this->makeStaff();

        $this->assertNull($admin->fresh()->permissions);
        $this->assertSame(AdminSections::ASSIGNABLE, $admin->fresh()->allowedSections());
    }

    public function test_staff_accounts_is_never_reachable_without_being_a_super_admin(): void
    {
        $unrestricted = $this->makeStaff('a@test.local');
        $listed = $this->makeLimitedStaff([AdminSections::STAFF, AdminSections::LOGS], 'b@test.local');

        $this->assertFalse($unrestricted->canAccess(AdminSections::STAFF));
        // Even a hand-edited list naming it grants nothing.
        $this->assertFalse($listed->fresh()->canAccess(AdminSections::STAFF));
        $this->assertTrue($listed->fresh()->canAccess(AdminSections::LOGS));
    }

    public function test_a_permission_list_grants_only_what_it_names(): void
    {
        $admin = $this->makeLimitedStaff([AdminSections::SMS, AdminSections::REQUESTS]);

        // In sidebar order, not the order they were stored in.
        $this->assertSame([AdminSections::REQUESTS, AdminSections::SMS], $admin->fresh()->allowedSections());
        $this->assertTrue($admin->fresh()->canAccess(AdminSections::SMS));
        $this->assertFalse($admin->fresh()->canAccess(AdminSections::LOGS));
    }

    public function test_an_empty_list_grants_nothing(): void
    {
        $admin = $this->makeLimitedStaff([]);

        $this->assertSame([], $admin->fresh()->allowedSections());
    }

    public function test_unknown_keys_in_the_list_are_ignored(): void
    {
        $admin = $this->makeLimitedStaff(['not-a-section', AdminSections::FILES]);

        $this->assertSame([AdminSections::FILES], $admin->fresh()->allowedSections());
    }

    public function test_a_super_admin_has_every_section_including_staff(): void
    {
        $super = $this->makeSuperAdmin();

        $this->assertSame(AdminSections::ALL, $super->fresh()->allowedSections());
        $this->assertTrue($super->fresh()->canAccess(AdminSections::STAFF));
    }

    public function test_a_super_admin_ignores_a_permission_list(): void
    {
        $super = $this->makeSuperAdmin('super@test.local', ['permissions' => []]);

        $this->assertSame(AdminSections::ALL, $super->fresh()->allowedSections());
    }

    public function test_the_flag_and_the_list_cannot_be_mass_assigned(): void
    {
        $admin = User::create([
            'first_name' => 'A',
            'last_name' => 'B',
            'email_address' => 'sneaky@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
            'is_super_admin' => true,
            'permissions' => [AdminSections::STAFF],
        ]);

        $this->assertFalse($admin->fresh()->isSuperAdmin());
        $this->assertNull($admin->fresh()->permissions);
    }

    public function test_a_row_inserted_by_hand_is_unrestricted_and_not_a_super_admin(): void
    {
        // The recovery path when an office locks itself out names its columns
        // and knows nothing about these two.
        DB::table('tbl_user')->insert([
            'first_name' => 'Hand',
            'last_name' => 'Inserted',
            'role' => 'Admin',
            'email_address' => 'hand@serbis.com',
            'password' => Hash::make('Password123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::where('email_address', 'hand@serbis.com')->first();

        $this->assertFalse($admin->isSuperAdmin());
        $this->assertNull($admin->permissions);
        $this->assertSame(AdminSections::ASSIGNABLE, $admin->allowedSections());
    }

    public function test_the_active_super_admin_count_ignores_closed_accounts_and_ordinary_admins(): void
    {
        $this->makeSuperAdmin('one@test.local');
        $this->makeSuperAdmin('closed@test.local', ['status' => 'Inactive']);
        $this->makeStaff('plain@test.local');

        $this->assertSame(1, User::activeSuperAdminCount());
    }

    public function test_a_permission_change_is_written_to_the_activity_log(): void
    {
        $admin = $this->makeStaff();

        $admin->permissions = [AdminSections::SMS];
        $admin->save();

        $row = DB::table('tbl_system_logs')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $admin->getKey())
            ->where('action_type', 'updated')
            ->latest('log_id')
            ->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('permissions', (string) $row->new_values);
    }

    public function test_every_assignable_section_is_a_known_section_and_staff_is_not_one(): void
    {
        $this->assertSame([], array_diff(AdminSections::ASSIGNABLE, AdminSections::ALL));
        $this->assertNotContains(AdminSections::STAFF, AdminSections::ASSIGNABLE);
        $this->assertCount(count(AdminSections::ALL) - 1, AdminSections::ASSIGNABLE);
    }

    public function test_a_request_is_filed_under_ambulance_only_for_the_ambulance_service(): void
    {
        $this->assertSame(AdminSections::AMBULANCE, AdminSections::forServiceCode('ambulance-medical-response'));
        $this->assertSame(AdminSections::REQUESTS, AdminSections::forServiceCode('road-clearing'));
        // The "Others" request has no service row.
        $this->assertSame(AdminSections::REQUESTS, AdminSections::forServiceCode(null));
    }
}
