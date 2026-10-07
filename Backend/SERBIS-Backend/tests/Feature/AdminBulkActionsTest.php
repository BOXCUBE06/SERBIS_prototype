<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/admins/bulk/* — the Staff accounts page's bulk actions. Each account is
 * checked exactly as its single route would check it; refusals are reported per
 * account instead of failing the request.
 */
class AdminBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->makeAdmin('Super', 'Admin', super: true);
        Sanctum::actingAs($this->me);
    }

    private function makeAdmin(string $first, string $last, bool $super = false, string $status = 'Active'): User
    {
        $admin = User::create([
            'first_name' => $first, 'last_name' => $last, 'password' => Hash::make('Password123'),
            'role' => 'Admin', 'status' => $status,
        ]);
        $admin->forceFill(['is_super_admin' => $super, 'permissions' => $super ? null : []])->save();

        return $admin;
    }

    public function test_bulk_close_deactivates_each_account_and_ends_their_sessions(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani');
        $jonas = $this->makeAdmin('Jonas', 'Tumaneng');
        $lorna->createToken('phone');

        $this->postJson('/api/admins/bulk/close', ['ids' => [$lorna->admin_id, $jonas->admin_id]])
            ->assertOk()
            ->assertJsonPath('done', [$lorna->admin_id, $jonas->admin_id])
            ->assertJsonPath('failed', []);

        $this->assertSame('Inactive', $lorna->fresh()->status);
        $this->assertSame('Inactive', $jonas->fresh()->status);
        $this->assertSame(0, $lorna->tokens()->count());
    }

    public function test_bulk_close_never_closes_your_own_account_and_says_so(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani');

        $response = $this->postJson('/api/admins/bulk/close', ['ids' => [$this->me->admin_id, $lorna->admin_id]])->assertOk();

        $response->assertJsonPath('done', [$lorna->admin_id]);
        $response->assertJsonPath('failed.0.id', $this->me->admin_id);
        $response->assertJsonPath('failed.0.name', 'Super Admin');
        $response->assertJsonPath('failed.0.message', 'You cannot close your own account. Ask another admin to do it.');
        $this->assertSame('Active', $this->me->fresh()->status);
    }

    public function test_bulk_close_can_close_another_super_admin_while_the_caller_remains(): void
    {
        // The last-super-admin and last-account refusals cannot fire here: the
        // caller is an active super admin and can never close themself. What can
        // happen is closing every other super admin, which leaves the caller.
        $maria = $this->makeAdmin('Maria', 'Pascual', super: true);
        $ramil = $this->makeAdmin('Ramil', 'Cabacungan', super: true);

        $this->postJson('/api/admins/bulk/close', ['ids' => [$maria->admin_id, $ramil->admin_id, $this->me->admin_id]])
            ->assertOk()
            ->assertJsonPath('done', [$maria->admin_id, $ramil->admin_id])
            ->assertJsonPath('failed.0.id', $this->me->admin_id);

        $this->assertSame(1, User::activeSuperAdminCount());
    }

    public function test_a_missing_account_is_reported_not_fatal(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani');

        $this->postJson('/api/admins/bulk/close', ['ids' => [999999, $lorna->admin_id]])
            ->assertOk()
            ->assertJsonPath('done', [$lorna->admin_id])
            ->assertJsonPath('failed.0', ['id' => 999999, 'name' => null, 'message' => 'Admin not found']);
    }

    public function test_bulk_reactivate_puts_each_account_back(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani', status: 'Inactive');
        $jonas = $this->makeAdmin('Jonas', 'Tumaneng', status: 'Inactive');

        $this->postJson('/api/admins/bulk/reactivate', ['ids' => [$lorna->admin_id, $jonas->admin_id]])
            ->assertOk()
            ->assertJsonPath('failed', []);

        $this->assertSame('Active', $lorna->fresh()->status);
        $this->assertSame('Active', $jonas->fresh()->status);
    }

    public function test_bulk_permissions_gives_every_account_the_same_access(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani');
        $jonas = $this->makeAdmin('Jonas', 'Tumaneng');

        $this->putJson('/api/admins/bulk/permissions', [
            'ids' => [$lorna->admin_id, $jonas->admin_id],
            'is_super_admin' => false,
            'permissions' => ['requests', 'ambulance'],
        ])->assertOk()->assertJsonPath('failed', []);

        $this->assertSame(['requests', 'ambulance'], $lorna->fresh()->permissions);
        $this->assertSame(['requests', 'ambulance'], $jonas->fresh()->permissions);
    }

    public function test_bulk_permissions_will_not_demote_the_last_active_super_admin(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani');

        $response = $this->putJson('/api/admins/bulk/permissions', [
            'ids' => [$this->me->admin_id, $lorna->admin_id],
            'is_super_admin' => false,
            'permissions' => ['requests'],
        ])->assertOk();

        $response->assertJsonPath('done', [$lorna->admin_id]);
        $response->assertJsonPath('failed.0.id', $this->me->admin_id);
        $this->assertTrue((bool) $this->me->fresh()->is_super_admin);
    }

    public function test_bulk_permissions_validates_like_the_single_route(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani');

        $this->putJson('/api/admins/bulk/permissions', ['ids' => [$lorna->admin_id], 'permissions' => ['staff']])->assertStatus(422);
        $this->putJson('/api/admins/bulk/permissions', ['ids' => [$lorna->admin_id]])->assertStatus(422);
        $this->postJson('/api/admins/bulk/close', ['ids' => []])->assertStatus(422);
    }

    public function test_only_a_super_admin_reaches_the_bulk_routes(): void
    {
        $lorna = $this->makeAdmin('Lorna', 'Agbayani');
        $limited = $this->makeAdmin('Jonas', 'Tumaneng');
        $limited->forceFill(['permissions' => ['requests']])->save();
        Sanctum::actingAs($limited);

        $this->postJson('/api/admins/bulk/close', ['ids' => [$lorna->admin_id]])->assertForbidden();
        $this->postJson('/api/admins/bulk/reactivate', ['ids' => [$lorna->admin_id]])->assertForbidden();
        $this->putJson('/api/admins/bulk/permissions', ['ids' => [$lorna->admin_id], 'permissions' => []])->assertForbidden();
        $this->assertSame('Active', $lorna->fresh()->status);
    }
}
