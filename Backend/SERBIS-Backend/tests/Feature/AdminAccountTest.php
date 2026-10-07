<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CompletesAdminMfa;
use Tests\TestCase;

/**
 * /api/admins — MDRRMO staff accounts (audit #29).
 *
 * Before this there was no route: every admin was a hand-written INSERT, so an
 * office could not add a new employee or close a departing one's account
 * without database credentials.
 *
 * The two refusals in destroy() are the load-bearing part. The panel has no
 * other way back in, so deleting yourself or deleting the last account would
 * leave a running system nobody can sign into.
 */
class AdminAccountTest extends TestCase
{
    use CompletesAdminMfa, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin('admin@test.local');
        // Staff Accounts is super-admin-only; the accounts it manages are ordinary ones.
        $this->admin->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($this->admin);
    }

    private function makeAdmin(string $email): User
    {
        return User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            // Explicit, not left to the column default: `create()` returns the
            // in-memory model without reading defaults back, so the instance
            // handed to Sanctum::actingAs would carry a null status and the
            // is.admin middleware would refuse it.
            'status' => 'Active',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Grace',
            'last_name' => 'Reyes',
            'username' => 'grace',
            'phone_number' => '09171234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    public function test_an_admin_can_create_another_admin(): void
    {
        $response = $this->postJson('/api/admins', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('username', 'grace')
            // The whole point of the feature: the account can sign in.
            ->assertJsonPath('role', 'Admin');

        $created = User::where('username', 'grace')->first();
        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('Password123', $created->password));

        // The hash must never be serialised, whatever the client asked for.
        $response->assertJsonMissingPath('password');
    }

    public function test_the_new_account_can_actually_log_in(): void
    {
        $this->postJson('/api/admins', $this->payload())->assertStatus(201);

        // Creating a row that cannot authenticate would pass every assertion
        // above and still leave the office locked out of the account it made.
        $this->loginAdmin('grace', 'Password123')
            ->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_the_role_cannot_be_set_by_the_client(): void
    {
        // A role the is.admin middleware does not recognise locks the account
        // out of the panel it was created for.
        $this->postJson('/api/admins', $this->payload(['role' => 'superadmin']))
            ->assertStatus(201)
            ->assertJsonPath('role', 'Admin');

        $this->assertSame('Admin', User::where('username', 'grace')->first()->role);
    }

    public function test_a_weak_or_unconfirmed_password_is_refused(): void
    {
        $this->postJson('/api/admins', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/admins', $this->payload(['password_confirmation' => 'Different123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(1, User::count());
    }

    public function test_a_duplicate_username_is_refused(): void
    {
        $this->postJson('/api/admins', $this->payload(['username' => 'admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_a_username_must_follow_the_rule(): void
    {
        foreach (['gr', 'Grace', 'gr ace', 'grace-r', 'grace@serbis.com', str_repeat('a', 31), ''] as $bad) {
            $this->postJson('/api/admins', $this->payload(['username' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('username');
        }

        $this->assertSame(1, User::count());

        $this->postJson('/api/admins', $this->payload(['username' => 'grace.r_2']))
            ->assertStatus(201);
    }

    public function test_an_email_is_no_longer_needed_or_kept(): void
    {
        $this->postJson('/api/admins', $this->payload(['email_address' => 'grace@serbis.com']))
            ->assertStatus(201);

        $this->assertNull(User::where('username', 'grace')->value('email_address'));
    }

    public function test_a_username_can_be_changed_and_signs_in_afterwards(): void
    {
        $other = $this->makeAdmin('other@test.local');

        $this->putJson("/api/admins/{$other->admin_id}", [
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'username' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('username');

        $this->putJson("/api/admins/{$other->admin_id}", [
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'username' => 'renamed.user',
        ])->assertStatus(200)->assertJsonPath('username', 'renamed.user');

        $this->loginAdmin('renamed.user', 'Password123')->assertStatus(200);
    }

    public function test_an_admin_can_be_renamed_without_touching_the_password(): void
    {
        $other = $this->makeAdmin('other@test.local');

        $this->putJson("/api/admins/{$other->admin_id}", [
            'first_name' => 'Renamed',
            'last_name' => 'Person',
            'username' => 'other',
        ])->assertStatus(200)->assertJsonPath('first_name', 'Renamed');

        // An omitted password must leave the hash alone; assigning null locks
        // the account out of its own panel.
        $this->assertTrue(Hash::check('Password123', $other->fresh()->password));
    }

    public function test_setting_another_admins_password_ends_their_sessions(): void
    {
        $other = $this->makeAdmin('other@test.local');
        $other->createToken('admin-token');
        $this->assertSame(1, $other->tokens()->count());

        $this->putJson("/api/admins/{$other->admin_id}", [
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'username' => 'other',
            'password' => 'Newpassword123',
            'password_confirmation' => 'Newpassword123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('Newpassword123', $other->fresh()->password));
        // This is the recovery path for a leaked credential. Leaving the old
        // token valid for the rest of its 8-hour life would defeat it.
        $this->assertSame(0, $other->tokens()->count());
    }

    public function test_changing_your_own_password_keeps_you_signed_in(): void
    {
        $keep = $this->admin->createToken('current-session');
        $stale = $this->admin->createToken('other-device');

        // A real bearer token, not Sanctum::actingAs. actingAs installs a
        // transient token, so `currentAccessToken()` would not be the one being
        // spared and this test would pass while proving nothing.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$keep->plainTextToken)
            ->putJson("/api/admins/{$this->admin->admin_id}", [
                'first_name' => 'MDRRMO',
                'last_name' => 'Admin',
                'username' => 'admin',
                'password' => 'Newpassword123',
                'password_confirmation' => 'Newpassword123',
            ])->assertStatus(200);

        $remaining = $this->admin->fresh()->tokens()->pluck('id')->all();

        // Logging someone out of the click they just made reads as a failure
        // and sends them back to re-enter the password they just set.
        $this->assertContains($keep->accessToken->getKey(), $remaining);
        $this->assertNotContains($stale->accessToken->getKey(), $remaining);
    }

    public function test_an_admin_cannot_close_their_own_account(): void
    {
        $this->makeAdmin('other@test.local');

        $this->deleteJson("/api/admins/{$this->admin->admin_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot close your own account. Ask another admin to do it.');

        $this->assertNotNull(User::find($this->admin->admin_id));
    }

    public function test_the_only_active_admin_cannot_close_the_last_way_in(): void
    {
        // The office would end up with a running system and no way to sign into
        // it, recoverable only by the hand-written INSERT this feature replaced.
        //
        // The self-guard is what actually blocks this, and that is not an
        // accident of ordering: a caller must be Active to get here, so when
        // there is only one Active account the caller *is* it. The
        // "only active admin" refusal in the controller is therefore defence in
        // depth with no reachable path of its own — it is kept because the day
        // someone adds a way to close an account other than by calling it
        // yourself, it is the guard that still holds.
        $solo = $this->makeAdmin('solo@test.local');
        $solo->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($solo);
        User::where('admin_id', $this->admin->admin_id)->update(['status' => 'Inactive']);

        $this->assertSame(1, User::where('status', 'Active')->count());

        $this->deleteJson("/api/admins/{$solo->admin_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot close your own account. Ask another admin to do it.');

        $this->assertSame('Active', $solo->fresh()->status);
    }

    public function test_an_account_with_no_history_is_deactivated_too_and_can_come_back(): void
    {
        // What a typo looks like: created minutes ago, nothing recorded against
        // it. It used to be deleted outright, which made closing irreversible for
        // these accounts only; now every close can be undone.
        $this->postJson('/api/admins', $this->payload())->assertStatus(201);
        $typo = User::where('username', 'grace')->first();

        $this->deleteJson("/api/admins/{$typo->admin_id}")
            ->assertStatus(200)
            ->assertJsonPath('deactivated', true);

        $this->assertSame('Inactive', $typo->fresh()->status);

        $this->patchJson("/api/admins/{$typo->admin_id}/reactivate")->assertOk();
        $this->assertSame('Active', $typo->fresh()->status);
    }

    public function test_an_account_with_history_is_deactivated_rather_than_deleted(): void
    {
        $other = $this->makeAdmin('other@test.local');
        $other->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($other);
        // Anything they did leaves a row in tbl_system_logs pointing at them.
        $this->postJson('/api/admins', $this->payload())->assertStatus(201);
        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson("/api/admins/{$other->admin_id}")->assertStatus(200);

        // The first draft of this controller answered 500 here: the foreign key
        // from tbl_system_logs refuses the delete, which is exactly the case
        // the feature exists for — a departing employee who did some work.
        $response->assertJsonPath('deactivated', true);
        $this->assertNotNull(User::find($other->admin_id));
        $this->assertSame('Inactive', $other->fresh()->status);
    }

    public function test_an_account_that_only_sent_sms_blasts_is_deactivated(): void
    {
        $other = $this->makeAdmin('other@test.local');
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        // A blast writes no audit row, so this is the sender's only trace.
        DB::table('tbl_sms_logs')->insert([
            'sender_id' => $other->admin_id,
            'target_area_id' => $barangay->barangay_id,
            'message_body' => 'Evacuate now.',
            'status' => 'Sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/admins/{$other->admin_id}")
            ->assertStatus(200)
            ->assertJsonPath('deactivated', true);

        $this->assertSame('Inactive', $other->fresh()->status);
    }

    public function test_closing_an_account_revokes_its_tokens(): void
    {
        $other = $this->makeAdmin('other@test.local');
        $other->createToken('admin-token');

        $this->deleteJson("/api/admins/{$other->admin_id}")->assertStatus(200);

        $this->assertSame(0, $other->tokens()->count());
    }

    public function test_a_deactivated_admin_cannot_log_in_or_use_a_token(): void
    {
        $other = $this->makeAdmin('other@test.local');
        $other->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($other);
        $this->postJson('/api/admins', $this->payload())->assertStatus(201);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/admins/{$other->admin_id}")->assertJsonPath('deactivated', true);

        // The point of closing the account.
        $this->postJson('/api/admin/login', [
            'username' => 'other',
            'password' => 'Password123',
        ])->assertStatus(403);

        // And a token issued some other way must not outlive it either.
        Sanctum::actingAs($other->fresh());
        $this->getJson('/api/admins')->assertStatus(403);
    }

    public function test_a_deactivated_admin_can_be_put_back_to_work(): void
    {
        $other = $this->makeAdmin('other@test.local');
        User::where('admin_id', $other->admin_id)->update(['status' => 'Inactive']);

        $this->patchJson("/api/admins/{$other->admin_id}/reactivate")
            ->assertStatus(200)
            ->assertJsonPath('status', 'Active');

        // An employee back from leave keeps their name on the work they did,
        // instead of needing a second account.
        $this->loginAdmin('other', 'Password123')->assertStatus(200);
    }

    public function test_a_missing_admin_is_a_404_not_a_500(): void
    {
        $this->getJson('/api/admins/9999')->assertStatus(404);
        $this->putJson('/api/admins/9999', [
            'first_name' => 'A', 'last_name' => 'B', 'username' => 'xuser',
        ])->assertStatus(404);
        $this->deleteJson('/api/admins/9999')->assertStatus(404);
    }

    public function test_the_list_never_serialises_a_password(): void
    {
        $this->makeAdmin('other@test.local');

        $response = $this->getJson('/api/admins')->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('password', $row);
        }
    }

    public function test_a_resident_cannot_reach_any_of_it(): void
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

        // is.admin, not a role check inside the controller — a resident must
        // not even learn how many staff accounts exist.
        $this->getJson('/api/admins')->assertStatus(403);
        $this->postJson('/api/admins', $this->payload())->assertStatus(403);
        $this->deleteJson("/api/admins/{$this->admin->admin_id}")->assertStatus(403);
    }

    public function test_creating_an_admin_is_written_to_the_system_log(): void
    {
        $this->postJson('/api/admins', $this->payload())->assertStatus(201);

        $created = User::where('username', 'grace')->first();

        $log = DB::table('tbl_system_logs')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $created->admin_id)
            ->where('action_type', 'created')
            ->first();

        $this->assertNotNull($log, 'An account appearing is exactly the change nobody remembers making.');
        $this->assertSame($this->admin->admin_id, (int) $log->admin_id);
        // The log is rendered on the Logs page. A password hash must not be in
        // it, whatever the trait's default ignore list happens to say later.
        $this->assertStringNotContainsString('password', (string) $log->new_values);
    }
}
