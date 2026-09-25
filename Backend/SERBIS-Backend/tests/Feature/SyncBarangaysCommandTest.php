<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncBarangaysCommandTest extends TestCase
{
    use RefreshDatabase;

    /** The three barangays production has today. @return list<int> their ids (auto-increment is not reset between tests on MySQL) */
    private function productionRows(): array
    {
        return array_map(
            fn ($name) => Barangay::create(['barangay_name' => $name])->barangay_id,
            ['San Fabian', 'San Miguel', 'San Antonio Ugad'],
        );
    }

    private function snapshot(): array
    {
        return DB::table('tbl_barangay')->orderBy('barangay_id')->get()->all();
    }

    public function test_dry_run_writes_nothing_and_prints_the_plan(): void
    {
        $this->productionRows();
        $before = $this->snapshot();

        $this->artisan('barangays:sync', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Angoluan')
            ->assertExitCode(0);

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_apply_adds_the_61_missing_barangays_and_keeps_the_existing_three(): void
    {
        [$fabian, $miguel, $ugad] = $this->productionRows();

        $this->artisan('barangays:sync')->assertExitCode(0);

        $this->assertSame(64, Barangay::count());
        $this->assertSame(['San Fabian', 'San Miguel', 'San Antonio Ugad'], array_map(fn ($id) => Barangay::find($id)->barangay_name, [$fabian, $miguel, $ugad]));
        $this->assertSame('0203112045', Barangay::find($fabian)->psgc_code);
    }

    public function test_running_again_changes_nothing(): void
    {
        $this->productionRows();
        $this->artisan('barangays:sync')->assertExitCode(0);
        $after = $this->snapshot();

        $this->artisan('barangays:sync')->assertExitCode(0);

        $this->assertEquals($after, $this->snapshot());
    }

    public function test_an_unmatched_existing_row_is_reported_not_touched(): void
    {
        $stray = Barangay::create(['barangay_name' => 'Not A Barangay']);

        $this->artisan('barangays:sync', ['--dry-run' => true])->expectsOutputToContain('Not A Barangay');
        $this->artisan('barangays:sync')->expectsOutputToContain('Not A Barangay')->assertExitCode(0);

        $row = DB::table('tbl_barangay')->where('barangay_id', $stray->barangay_id)->first();
        $this->assertSame('Not A Barangay', $row->barangay_name);
        $this->assertNull($row->psgc_code);
        $this->assertSame(65, Barangay::count());
    }
}
