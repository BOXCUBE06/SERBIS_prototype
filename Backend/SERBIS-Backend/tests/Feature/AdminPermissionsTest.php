<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use App\Models\User;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * PUT /api/admins/{id}/permissions and the guards around who may change whom.
 *
 * The route's own gate (Staff Accounts is super-admin-only) already keeps an
 * ordinary admin out of AdminController. The tests that call the controller
 * directly are for the checks behind that gate, which exist for the day it is
 * loosened and are otherwise unreachable from a request.
 */
class AdminPermissionsTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = $this->makeSuperAdmin();
        Sanctum::actingAs($this->super);
    }

    private function setAccess(User $target, array $body)
    {
        return $this->putJson("/api/admins/{$target->admin_id}/permissions", $body);
    }

    /** Calls the controller as $caller, past the route's gate. */
    private function asController(User $caller, string $method, string $verb, int $id, array $body = [])
    {
        $request = Request::create('/', $verb, $body);
        $request->setUserResolver(fn () => $caller);

        return $this->app->make(AdminController::class)->{$method}($request, $id);
    }

    // ---- new accounts ------------------------------------------------------

    public function test_an_account_created_from_the_panel_starts_with_no_sections(): void
    {
        $response = $this->postJson('/api/admins', [
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'email_address' => 'grace@serbis.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);

        $response->assertJsonPath('permissions', [])->assertJsonPath('is_super_admin', false);

        $created = User::where('email_address', 'grace@serbis.com')->first();
        $this->assertSame([], $created->permissions);
        $this->assertSame([], $created->allowedSections());
    }

    public function test_the_creation_is_one_row_in_the_activity_log(): void
    {
        $this->postJson('/api/admins', [
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'email_address' => 'grace@serbis.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);

        $id = User::where('email_address', 'grace@serbis.com')->value('admin_id');

        $this->assertSame(1, DB::table('tbl_system_logs')
            ->where('auditable_type', User::class)->where('auditable_id', $id)->count());
    }

    // ---- setting access ----------------------------------------------------

    public function test_a_super_admin_sets_which_sections_an_account_may_open(): void
    {
        $target = $this->makeStaff('t@test.local');

        $this->setAccess($target, ['permissions' => [AdminSections::SMS, AdminSections::REQUESTS]])
            ->assertOk()
            // Sidebar order, however it was sent.
            ->assertJsonPath('permissions', [AdminSections::REQUESTS, AdminSections::SMS]);

        $this->assertSame([AdminSections::REQUESTS, AdminSections::SMS], $target->fresh()->permissions);
    }

    public function test_the_change_reaches_the_account_on_its_very_next_request(): void
    {
        $target = $this->makeLimitedStaff([], 't@test.local');

        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/logs/system')->assertStatus(403)->assertJsonPath('code', 'section_forbidden');

        Sanctum::actingAs($this->super);
        $this->setAccess($target, ['permissions' => [AdminSections::LOGS]])->assertOk();

        // Read from the row each time, nothing cached in the token.
        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/logs/system')->assertOk();
    }

    public function test_an_empty_list_takes_every_section_away(): void
    {
        $target = $this->makeStaff('t@test.local');

        $this->setAccess($target, ['permissions' => []])->assertOk()->assertJsonPath('permissions', []);

        $this->assertSame([], $target->fresh()->allowedSections());
    }

    public function test_only_sections_that_can_be_handed_out_are_accepted(): void
    {
        $target = $this->makeStaff('t@test.local');

        $this->setAccess($target, ['permissions' => ['nonsense']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.0');
        // Staff Accounts is not one of them, so a hand-built request cannot grant it.
        $this->setAccess($target, ['permissions' => [AdminSections::STAFF]])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.0');
        $this->setAccess($target, ['permissions' => [AdminSections::SMS, AdminSections::SMS]])
            ->assertStatus(422);

        $this->assertNull($target->fresh()->permissions);
    }

    public function test_something_has_to_be_sent(): void
    {
        $this->setAccess($this->makeStaff('t@test.local'), [])->assertStatus(422);
    }

    public function test_a_missing_account_is_a_404(): void
    {
        $this->putJson('/api/admins/999999/permissions', ['permissions' => []])->assertStatus(404);
    }

    public function test_changing_access_is_written_to_the_activity_log_under_the_super_admin(): void
    {
        $target = $this->makeStaff('t@test.local');

        $this->setAccess($target, ['permissions' => [AdminSections::SMS]])->assertOk();

        $row = DB::table('tbl_system_logs')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $target->admin_id)
            ->where('action_type', 'updated')
            ->latest('log_id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($this->super->admin_id, (int) $row->admin_id);
        $this->assertStringContainsString('permissions', (string) $row->new_values);
    }

    public function test_a_permission_change_does_not_take_the_activity_log_page_down(): void
    {
        // The list is an array in the log row. The page used to compare values
        // as strings and threw "Array to string conversion" on it.
        $target = $this->makeLimitedStaff([AdminSections::SMS], 't@test.local');

        $this->setAccess($target, ['permissions' => [AdminSections::SMS, AdminSections::LOGS]])->assertOk();

        $descriptions = collect($this->getJson('/api/logs/system')->assertOk()->json('data'))
            ->pluck('description')->implode(' | ');

        $this->assertStringContainsString('fields: permissions', $descriptions);
    }

    // ---- who may do it -----------------------------------------------------

    public function test_an_ordinary_admin_cannot_change_anyones_access_not_even_their_own(): void
    {
        $plain = $this->makeStaff('plain@test.local');
        Sanctum::actingAs($plain);

        $this->setAccess($plain, ['is_super_admin' => true])->assertStatus(403);
        $this->assertFalse($plain->fresh()->isSuperAdmin());
    }

    public function test_the_controller_itself_refuses_a_caller_who_is_not_a_super_admin(): void
    {
        $plain = $this->makeStaff('plain@test.local');
        $target = $this->makeStaff('t@test.local');

        $response = $this->asController($plain, 'updatePermissions', 'PUT', $target->admin_id, ['permissions' => []]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($target->fresh()->permissions);
    }

    // ---- promoting and demoting --------------------------------------------

    public function test_a_super_admin_can_promote_and_later_demote_another_account(): void
    {
        $target = $this->makeStaff('t@test.local');

        $this->setAccess($target, ['is_super_admin' => true])->assertOk()->assertJsonPath('is_super_admin', true);
        $this->assertTrue($target->fresh()->isSuperAdmin());

        $this->setAccess($target, ['is_super_admin' => false])->assertOk()->assertJsonPath('is_super_admin', false);
        $this->assertFalse($target->fresh()->isSuperAdmin());
    }

    public function test_the_last_active_super_admin_cannot_be_demoted_by_anyone_themselves_included(): void
    {
        $this->setAccess($this->super, ['is_super_admin' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This is the only active super admin. Make another account a super admin before removing this one.');

        $this->assertTrue($this->super->fresh()->isSuperAdmin());
    }

    public function test_a_closed_super_admin_does_not_count_as_a_second_one(): void
    {
        $this->makeSuperAdmin('closed@test.local', ['status' => 'Inactive']);

        $this->setAccess($this->super, ['is_super_admin' => false])->assertStatus(422);
    }

    public function test_with_a_second_active_super_admin_the_first_can_step_down(): void
    {
        $this->makeSuperAdmin('second@test.local');

        $this->setAccess($this->super, ['is_super_admin' => false])->assertOk();
    }

    public function test_the_last_active_super_admin_cannot_be_closed(): void
    {
        // Past the route's gate: a caller who is a super admin but no longer
        // signed in as one can reach this, and it must still refuse.
        $target = $this->makeSuperAdmin('only@test.local');
        $this->super->forceFill(['status' => 'Inactive'])->save();
        $this->makeStaff('plain@test.local');

        $response = $this->asController($this->super->fresh(), 'destroy', 'DELETE', $target->admin_id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('only active super admin', $response->getData(true)['message']);
        $this->assertNotNull(User::find($target->admin_id));
    }

    public function test_one_of_two_active_super_admins_can_be_closed(): void
    {
        $other = $this->makeSuperAdmin('other@test.local');

        $this->deleteJson("/api/admins/{$other->admin_id}")->assertOk();
    }

    // ---- actions on a super admin's account --------------------------------

    public function test_a_caller_who_is_not_a_super_admin_cannot_act_on_a_super_admins_account(): void
    {
        $plain = $this->makeStaff('plain@test.local');
        $target = $this->makeSuperAdmin('target@test.local');

        foreach ([
            ['update', 'PUT', ['first_name' => 'X', 'last_name' => 'Y', 'email_address' => 'target@test.local', 'password' => 'Newpass123', 'password_confirmation' => 'Newpass123']],
            ['resetPassword', 'POST', []],
            ['reactivate', 'PATCH', []],
            ['destroy', 'DELETE', []],
        ] as [$method, $verb, $body]) {
            $response = $this->asController($plain, $method, $verb, $target->admin_id, $body);

            $this->assertSame(403, $response->getStatusCode(), "{$method} was not refused");
        }

        // Nothing moved: the password is still the one it started with, and no
        // temporary one was handed out to sign in with.
        $fresh = $target->fresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Password123', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame('Active', $fresh->status);
    }

    public function test_a_super_admin_can_still_act_on_another_super_admins_account(): void
    {
        $target = $this->makeSuperAdmin('target@test.local');

        $this->postJson("/api/admins/{$target->admin_id}/reset-password")
            ->assertOk()->assertJsonStructure(['temporary_password']);
    }

    // ---- what the panel is told --------------------------------------------

    public function test_me_lists_the_sections_the_account_may_open(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::LOGS, AdminSections::SMS], 'l@test.local'));

        $this->getJson('/api/me')->assertOk()->assertJsonPath('sections', [AdminSections::SMS, AdminSections::LOGS]);
    }

    public function test_me_gives_a_super_admin_every_section_including_staff(): void
    {
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('sections', AdminSections::ALL)
            ->assertJsonPath('user.is_super_admin', true);
    }

    public function test_an_unrestricted_account_is_told_every_assignable_section(): void
    {
        Sanctum::actingAs($this->makeStaff('plain@test.local'));

        $this->getJson('/api/me')->assertOk()->assertJsonPath('sections', AdminSections::ASSIGNABLE);
    }

    public function test_signing_in_returns_the_sections_too(): void
    {
        $this->makeLimitedStaff([AdminSections::FILES], 'files@test.local');

        $this->postJson('/api/admin/login', ['email_address' => 'files@test.local', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('sections', [AdminSections::FILES]);
    }

    public function test_the_account_list_carries_the_flag_and_the_list_for_the_staff_page(): void
    {
        $this->makeLimitedStaff([AdminSections::SMS], 'l@test.local');

        $rows = collect($this->getJson('/api/admins')->assertOk()->json('data'));
        $limited = $rows->firstWhere('email_address', 'l@test.local');

        $this->assertSame([AdminSections::SMS], $limited['permissions']);
        $this->assertFalse($limited['is_super_admin']);
        $this->assertTrue($rows->firstWhere('email_address', 'super@test.local')['is_super_admin']);
    }
}
