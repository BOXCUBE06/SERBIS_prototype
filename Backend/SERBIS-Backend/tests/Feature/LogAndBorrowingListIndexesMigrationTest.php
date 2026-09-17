<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_15_100000 — the list indexes exist, in column order, and the
 * redundant single-column status index is gone.
 *
 * Reads via Schema::getIndexes() rather than information_schema directly:
 * the raw query passed locally (MariaDB 10.4) but failed on GitHub Actions'
 * MySQL over identifier casing, which is exactly the kind of engine detail
 * Laravel's own introspection is built to paper over.
 */
class LogAndBorrowingListIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function indexColumns(string $table, string $index): array
    {
        $match = collect(Schema::getIndexes($table))->firstWhere('name', $index);

        return $match['columns'] ?? [];
    }

    public function test_the_log_tables_are_indexed_on_created_at(): void
    {
        $this->assertSame(['created_at'], $this->indexColumns('tbl_system_logs', 'tbl_system_logs_created_at_index'));
        $this->assertSame(['created_at'], $this->indexColumns('tbl_sms_logs', 'tbl_sms_logs_created_at_index'));
    }

    public function test_a_residents_borrowings_are_indexed_in_list_order(): void
    {
        $this->assertSame(
            ['resident_id', 'created_at'],
            $this->indexColumns('tbl_equipment_borrowing', 'tbl_equipment_borrowing_resident_id_created_at_index'),
        );
    }

    public function test_the_redundant_status_index_is_dropped_and_its_composite_stays(): void
    {
        $this->assertSame([], $this->indexColumns('tbl_service_request', 'tbl_service_request_status_index'));
        $this->assertSame(
            ['status', 'created_at'],
            $this->indexColumns('tbl_service_request', 'tbl_service_request_status_created_at_index'),
        );
    }
}
