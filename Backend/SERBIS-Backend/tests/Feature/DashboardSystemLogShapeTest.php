<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * The dashboard's recent-activity rows are built from SystemLog and its admin
 * relation. Only a display line comes out of that account; nothing else of it
 * may ride along (phone, email, role, permissions).
 */
class DashboardSystemLogShapeTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    public function test_activity_rows_carry_a_display_line_and_no_account_fields(): void
    {
        $staff = $this->makeStaff('actor@test.local', [
            'username' => 'mdrrmo.actor',
            'phone_number' => '+639171234567',
        ]);

        SystemLog::create([
            'admin_id' => $staff->getKey(),
            'action_type' => 'created',
            'auditable_type' => ServiceRequest::class,
            'auditable_id' => 1,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/admin/dashboard')->assertOk();
        // Not row 0: creating the staff account above logged a row of its own, in the same second.
        $row = collect($response->json('systemLogs'))->firstWhere('module', 'ServiceRequest');

        $this->assertEqualsCanonicalizing(['time', 'user', 'module', 'action'], array_keys($row));
        $this->assertSame('MDRRMO Admin (mdrrmo.actor)', $row['user']);

        foreach (['+639171234567', 'actor@test.local', 'permissions', 'must_change_password'] as $leaked) {
            $this->assertStringNotContainsString($leaked, json_encode($response->json('systemLogs')));
        }
    }
}
