<?php

namespace Tests\Feature;

use App\Models\ConductionRequest;
use App\Models\EquipmentBorrowing;
use App\Models\ServiceRequest;
use App\Models\SystemLog;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * POST /api/admin/export-logs/{type}: the audit row for a print or export the
 * browser did. Gated by the section that owns the list, throttled per account.
 */
class ExportLogTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    // Creating the admin logs a row of its own; these are the ones the route wrote.
    private function exportLogs()
    {
        return SystemLog::whereIn('action_type', ['printed', 'exported']);
    }

    private function log(string $type, array $body = []): TestResponse
    {
        return $this->postJson("/api/admin/export-logs/{$type}", $body + [
            'action' => 'export', 'format' => 'xlsx', 'scope' => 'all', 'count' => 12,
        ]);
    }

    public function test_an_export_is_recorded_with_actor_type_count_and_format(): void
    {
        $admin = $this->makeStaff();
        Sanctum::actingAs($admin);

        $this->log('borrowing', ['from' => '2026-09-01', 'to' => '2026-09-24'])->assertCreated();

        $row = $this->exportLogs()->firstOrFail();
        $this->assertSame($admin->admin_id, $row->admin_id);
        $this->assertSame('exported', $row->action_type);
        $this->assertSame(EquipmentBorrowing::class, $row->auditable_type);
        $this->assertSame(0, (int) $row->auditable_id);
        $this->assertSame(12, $row->new_values['count']);
        $this->assertSame('xlsx', $row->new_values['format']);
        $this->assertSame('2026-09-01', $row->new_values['from']);
    }

    public function test_a_single_print_points_at_its_record(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->log('trip', ['action' => 'print', 'format' => 'print', 'scope' => 'single', 'count' => 1, 'ids' => [41]])->assertCreated();

        $row = $this->exportLogs()->firstOrFail();
        $this->assertSame('printed', $row->action_type);
        $this->assertSame(ConductionRequest::class, $row->auditable_type);
        $this->assertSame(41, (int) $row->auditable_id);
    }

    public function test_requests_and_bookings_log_against_the_service_request_model(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->log('request')->assertCreated();
        $this->log('booking')->assertCreated();

        $this->assertSame(2, $this->exportLogs()->where('auditable_type', ServiceRequest::class)->count());
    }

    public function test_the_route_needs_the_section_that_owns_the_list(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::BORROWINGS]));

        $this->log('borrowing')->assertCreated();
        $this->log('request')->assertForbidden();
        $this->log('trip')->assertForbidden();
        $this->assertSame(1, $this->exportLogs()->count());
    }

    public function test_bad_input_is_refused(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->log('request', ['format' => 'pdf'])->assertUnprocessable();
        $this->log('request', ['count' => 0])->assertUnprocessable();
        $this->log('request', ['ids' => range(1, 201)])->assertUnprocessable();
        $this->log('nonsense')->assertNotFound();
        $this->assertSame(0, $this->exportLogs()->count());
    }

    public function test_it_is_throttled_per_account(): void
    {
        Sanctum::actingAs($this->makeStaff());

        foreach (range(1, 30) as $i) {
            $this->log('vehicle')->assertCreated();
        }
        $this->log('vehicle')->assertStatus(429);
    }

    public function test_the_activity_log_describes_it_in_words(): void
    {
        Sanctum::actingAs($this->makeStaff());
        $this->log('borrowing', ['from' => '2026-09-01', 'to' => '2026-09-24'])->assertCreated();

        $description = $this->getJson('/api/logs/system')->assertOk()->json('data.0.description');

        $this->assertSame('Exported 12 borrowing records as XLSX (2026-09-01 to 2026-09-24)', $description);
    }

    public function test_the_log_row_carries_no_record_data(): void
    {
        Sanctum::actingAs($this->makeStaff());
        $this->log('request', ['scope' => 'selected', 'count' => 2, 'ids' => [5, 6]])->assertCreated();

        $this->assertSame(['type' => 'request', 'format' => 'xlsx', 'scope' => 'selected', 'count' => 2, 'ids' => [5, 6]],
            $this->exportLogs()->firstOrFail()->new_values);
    }
}
