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
 * One admin hands another a temporary password, and the owner must replace it
 * before the panel lets them do anything.
 *
 * There is no mail behind this (MAIL_MAILER=log), so the password comes back
 * once in the reset response and the enforcement lives on the server: a
 * hand-rolled request must get no further than the browser does.
 */
class StaffPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin('admin@serbis.com');
        // Staff Accounts is super-admin-only; the accounts it manages are ordinary ones.
        $this->admin->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($this->admin);
    }

    private function makeAdmin(string $email, string $status = 'Active'): User
    {
        return User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => $status,
        ]);
    }

    private function reset(User $target)
    {
        return $this->postJson("/api/admins/{$target->admin_id}/reset-password");
    }

    /** A real bearer token, as a browser would hold — actingAs installs a transient one. */
    private function asBearer(User $user, string $plainToken)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$plainToken);
    }

    public function test_reset_returns_a_temporary_password_once_and_flags_the_account(): void
    {
        $other = $this->makeAdmin('other@serbis.com');

        $response = $this->reset($other)->assertStatus(200);
        $temporary = $response->json('temporary_password');

        $this->assertIsString($temporary);
        $this->assertGreaterThanOrEqual(10, strlen($temporary));
        $this->assertMatchesRegularExpression('/[A-Z]/', $temporary);
        $this->assertMatchesRegularExpression('/[a-z]/', $temporary);
        $this->assertMatchesRegularExpression('/\d/', $temporary);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $other->refresh();
        $this->assertTrue($other->must_change_password);
        $this->assertTrue(Hash::check($temporary, $other->password));
        $this->assertFalse(Hash::check('Password123', $other->password));
    }

    public function test_the_temporary_password_never_reaches_the_list_or_the_log(): void
    {
        $other = $this->makeAdmin('other@serbis.com');
        $temporary = $this->reset($other)->json('temporary_password');

        $list = $this->getJson('/api/admins')->assertStatus(200)->getContent();
        $this->assertStringNotContainsString($temporary, $list);

        $logs = DB::table('tbl_system_logs')->get()->toJson();
        $this->assertStringNotContainsString($temporary, $logs);
        $this->assertStringNotContainsString('temporary_password', $logs);

        // The flag flip is worth an audit row, and it names who did it.
        $row = DB::table('tbl_system_logs')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $other->admin_id)
            ->where('action_type', 'updated')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($this->admin->admin_id, (int) $row->admin_id);
    }

    public function test_reset_ends_every_session_of_the_target(): void
    {
        $other = $this->makeAdmin('other@serbis.com');
        $other->createToken('admin-token');
        $other->createToken('other-device');

        $this->reset($other)->assertStatus(200);

        $this->assertSame(0, $other->tokens()->count());
    }

    public function test_an_admin_cannot_reset_their_own_password(): void
    {
        $this->reset($this->admin)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot reset your own password this way. Ask another admin to do it.');

        $this->assertFalse($this->admin->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Password123', $this->admin->fresh()->password));
    }

    public function test_a_deactivated_or_missing_account_cannot_be_reset(): void
    {
        $closed = $this->makeAdmin('closed@serbis.com', 'Inactive');

        $this->reset($closed)->assertStatus(422);
        $this->assertFalse($closed->fresh()->must_change_password);

        $this->postJson('/api/admins/9999/reset-password')->assertStatus(404);
    }

    public function test_a_resident_cannot_reset_a_staff_password(): void
    {
        $other = $this->makeAdmin('other@serbis.com');
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09170000000',
            'email_address' => 'resident@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        Sanctum::actingAs($resident);

        $this->reset($other)->assertStatus(403);
        $this->assertFalse($other->fresh()->must_change_password);
    }

    public function test_a_flagged_account_can_sign_in_but_reaches_nothing_else(): void
    {
        $other = $this->makeAdmin('other@serbis.com');
        $temporary = $this->reset($other)->json('temporary_password');

        $login = $this->postJson('/api/admin/login', [
            'username' => 'other',
            'password' => $temporary,
        ])->assertStatus(200)->assertJsonPath('user.must_change_password', true);

        $token = $login->json('token');

        $this->asBearer($other, $token)
            ->getJson('/api/admins')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');

        // Still able to say who it is and to leave.
        $this->asBearer($other, $token)->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('user.must_change_password', true);
        $this->asBearer($other, $token)->postJson('/api/logout')->assertStatus(200);
    }

    public function test_changing_the_password_lifts_the_block(): void
    {
        $other = $this->makeAdmin('other@serbis.com');
        $temporary = $this->reset($other)->json('temporary_password');
        $token = $this->postJson('/api/admin/login', [
            'username' => 'other',
            'password' => $temporary,
        ])->json('token');

        $this->asBearer($other, $token)->postJson('/api/admin/change-password', [
            'current_password' => $temporary,
            'password' => 'Brandnew123',
            'password_confirmation' => 'Brandnew123',
        ])->assertStatus(200)->assertJsonPath('user.must_change_password', false);

        $other->refresh();
        $this->assertFalse($other->must_change_password);
        $this->assertTrue(Hash::check('Brandnew123', $other->password));

        // The session they made it on survives, and now reaches the panel. Any
        // ordinary admin route will do; /api/admins would be refused on section
        // grounds, since `other` is not a super admin.
        $this->asBearer($other, $token)->getJson('/api/vehicles')->assertStatus(200);

        // The temporary password is dead.
        $this->postJson('/api/admin/login', [
            'username' => 'other',
            'password' => $temporary,
        ])->assertStatus(401);
    }

    public function test_change_password_ends_other_sessions_and_keeps_this_one(): void
    {
        $other = $this->makeAdmin('other@serbis.com');
        $keep = $other->createToken('current');
        $stale = $other->createToken('stale');

        $this->asBearer($other, $keep->plainTextToken)->postJson('/api/admin/change-password', [
            'current_password' => 'Password123',
            'password' => 'Brandnew123',
            'password_confirmation' => 'Brandnew123',
        ])->assertStatus(200);

        $remaining = $other->tokens()->pluck('id')->all();
        $this->assertContains($keep->accessToken->getKey(), $remaining);
        $this->assertNotContains($stale->accessToken->getKey(), $remaining);
    }

    public function test_change_password_refuses_a_wrong_current_a_reused_or_a_weak_password(): void
    {
        $payload = fn (array $o) => array_merge([
            'current_password' => 'Password123',
            'password' => 'Brandnew123',
            'password_confirmation' => 'Brandnew123',
        ], $o);

        $this->postJson('/api/admin/change-password', $payload(['current_password' => 'Wrong12345']))
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->postJson('/api/admin/change-password', $payload(['password' => 'Password123', 'password_confirmation' => 'Password123']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/admin/change-password', $payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/admin/change-password', $payload(['password_confirmation' => 'Different123']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Password123', $this->admin->fresh()->password));
    }

    public function test_a_resident_token_cannot_use_change_password(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09170000000',
            'email_address' => 'resident@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        Sanctum::actingAs($resident);

        $this->postJson('/api/admin/change-password', [
            'current_password' => 'Password123',
            'password' => 'Brandnew123',
            'password_confirmation' => 'Brandnew123',
        ])->assertStatus(403);
    }
}
