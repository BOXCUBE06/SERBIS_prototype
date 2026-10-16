<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** 2026_10_16_100000 — the filter and pulse indexes exist, in column order, and running it again changes nothing. */
class ListAndPulseIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        ['tbl_service_request', 'tbl_service_request_barangay_id_created_at_index', ['barangay_id', 'created_at']],
        ['tbl_service_request', 'tbl_service_request_updated_at_index', ['updated_at']],
        ['tbl_equipment_borrowing', 'tbl_equipment_borrowing_due_date_index', ['due_date']],
        ['tbl_residents', 'tbl_residents_status_index', ['status']],
        ['tbl_residents', 'tbl_residents_last_name_first_name_index', ['last_name', 'first_name']],
        ['tbl_residents', 'tbl_residents_updated_at_index', ['updated_at']],
        ['tbl_conduction_requests', 'tbl_conduction_requests_updated_at_index', ['updated_at']],
    ];

    private function indexColumns(string $table, string $index): array
    {
        return collect(Schema::getIndexes($table))->firstWhere('name', $index)['columns'] ?? [];
    }

    public function test_the_indexes_exist_in_column_order(): void
    {
        foreach (self::EXPECTED as [$table, $index, $columns]) {
            $this->assertSame($columns, $this->indexColumns($table, $index), "$table.$index");
        }
    }

    public function test_running_it_again_skips_what_exists(): void
    {
        $migration = require base_path('database/migrations/2026_10_16_100000_add_list_filter_and_pulse_indexes.php');
        $migration->up();

        foreach (self::EXPECTED as [$table, $index, $columns]) {
            $this->assertSame($columns, $this->indexColumns($table, $index), "$table.$index");
        }
    }
}
