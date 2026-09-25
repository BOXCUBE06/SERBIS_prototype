<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Database\Seeders\EchagueBarangaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EchagueBarangaySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_64_barangays_with_unique_codes(): void
    {
        $this->seed(EchagueBarangaySeeder::class);

        $this->assertSame(64, Barangay::count());
        $this->assertSame(64, Barangay::whereNotNull('psgc_code')->distinct()->count('psgc_code'));
    }

    public function test_rerunning_changes_nothing(): void
    {
        $this->seed(EchagueBarangaySeeder::class);
        $before = DB::table('tbl_barangay')->orderBy('barangay_id')->get()->all();

        $this->seed(EchagueBarangaySeeder::class);

        $this->assertEquals($before, DB::table('tbl_barangay')->orderBy('barangay_id')->get()->all());
    }

    public function test_existing_rows_keep_their_ids_and_gain_a_code(): void
    {
        $fabian = Barangay::create(['barangay_name' => 'San Fabian']);
        // Matched despite the Pob. suffix and case.
        $cabugao = Barangay::create(['barangay_name' => 'CABUGAO']);

        $this->seed(EchagueBarangaySeeder::class);

        $this->assertSame('0203112045', $fabian->fresh()->psgc_code);
        $this->assertSame('San Fabian', $fabian->fresh()->barangay_name);
        $this->assertSame('CABUGAO', $cabugao->fresh()->barangay_name);
        $this->assertNotNull($cabugao->fresh()->psgc_code);
        $this->assertSame(64, Barangay::count());
    }

    public function test_a_row_matching_no_official_barangay_is_kept_and_reported(): void
    {
        $stray = Barangay::create(['barangay_name' => 'Not A Barangay']);

        $this->seed(EchagueBarangaySeeder::class);

        $this->assertNotNull(Barangay::find($stray->barangay_id));
        $this->assertNull($stray->fresh()->psgc_code);
        $this->assertSame(65, Barangay::count());
    }
}
