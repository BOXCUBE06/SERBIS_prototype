<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * The panel's access templates (ACCESS_TEMPLATES in adminSections.ts) only
 * pre-tick sections. What reaches the server is an ordinary `permissions`
 * list: on creation it is checked exactly as the access endpoints check it,
 * and once saved the account is held to it section by section.
 */
class StaffTemplatePermissionsTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    /** Mirrors the panel's Communications template. */
    private const COMMUNICATIONS = ['dashboard', 'analytics', 'residents', 'files', 'sms', 'hotlines', 'logs'];

    /** Mirrors the panel's Operations template. */
    private const OPERATIONS = [
        'dashboard', 'analytics', 'requests', 'ambulance', 'borrowings', 'vehicles', 'responders',
        'inventory', 'procurement', 'residents', 'services', 'service_audience', 'service_vehicles', 'logs',
    ];

    private function payload(array $extra = []): array
    {
        return [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'username' => 'grace',
            'phone_number' => '09171234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            ...$extra,
        ];
    }

    public function test_both_templates_only_name_sections_that_can_be_given_out(): void
    {
        foreach ([self::COMMUNICATIONS, self::OPERATIONS] as $template) {
            $this->assertSame([], array_diff($template, AdminSections::ASSIGNABLE));
            $this->assertNotContains(AdminSections::STAFF, $template);
        }
    }

    public function test_a_new_account_starts_with_the_template_it_was_created_with(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson('/api/admins', $this->payload(['permissions' => self::OPERATIONS]))->assertCreated();

        $created = User::where('username', 'grace')->firstOrFail();
        $this->assertEqualsCanonicalizing(self::OPERATIONS, $created->permissions);
        $this->assertEqualsCanonicalizing(self::OPERATIONS, $created->allowedSections());
    }

    public function test_a_new_account_with_no_template_still_starts_with_no_sections(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson('/api/admins', $this->payload())->assertCreated();

        $created = User::where('username', 'grace')->firstOrFail();
        $this->assertSame([], $created->permissions, 'an empty list, never NULL (NULL is unrestricted)');
        $this->assertSame([], $created->allowedSections());
    }

    public function test_creation_refuses_staff_accounts_and_unknown_sections(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        foreach ([[AdminSections::STAFF], ['dashboard', 'not-a-section']] as $permissions) {
            $this->postJson('/api/admins', $this->payload(['permissions' => $permissions]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['permissions.'.(count($permissions) - 1) => 'That is not a section that can be given to an account.']);
        }

        $this->assertNull(User::where('username', 'grace')->first());
    }

    public function test_only_a_super_admin_can_create_an_account_with_a_template(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->postJson('/api/admins', $this->payload(['permissions' => self::OPERATIONS]))->assertForbidden();
        $this->assertNull(User::where('username', 'grace')->first());
    }

    public function test_a_communications_account_is_held_to_its_sections(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff(self::COMMUNICATIONS));

        $this->getJson('/api/residents')->assertOk();
        $this->getJson('/api/logs/system')->assertOk();
        $this->getJson('/api/admin/service-requests')->assertForbidden();
        $this->getJson('/api/borrowings')->assertForbidden();
        $this->getJson('/api/conduction-requests')->assertForbidden();
        $this->getJson('/api/admins')->assertForbidden();
    }
}
