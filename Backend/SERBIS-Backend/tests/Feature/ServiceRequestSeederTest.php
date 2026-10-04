<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Database\Seeders\ServiceRequestSeeder;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The seeder inserts rows directly, which skips ServiceRequest::booted(), so it has to
 * set barangay_id itself or every seeded request would drop out of the per-barangay
 * analytics.
 */
class ServiceRequestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_request_carries_its_residents_barangay(): void
    {
        $this->seed(ServiceSeeder::class);

        foreach (['San Fabian', 'San Miguel'] as $i => $name) {
            $barangay = Barangay::create(['barangay_name' => $name]);

            Resident::create([
                'barangay_id' => $barangay->barangay_id,
                'first_name' => 'Resident',
                'last_name' => (string) $i,
                'phone_number' => '0917000000'.$i,
                'email_address' => "r{$i}@test.local",
                'password' => Hash::make('Password123'),
                'status' => 'Active',
            ]);
        }

        $this->seed(ServiceRequestSeeder::class);

        $rows = DB::table('tbl_service_request')
            ->join('tbl_residents', 'tbl_service_request.resident_id', '=', 'tbl_residents.resident_id')
            ->get(['tbl_service_request.barangay_id as filed', 'tbl_residents.barangay_id as home']);

        $this->assertCount(30, $rows);
        $this->assertSame(0, $rows->whereNull('filed')->count());
        $this->assertTrue($rows->every(fn ($row) => $row->filed === $row->home));
    }

    public function test_it_skips_cleanly_when_there_are_no_residents(): void
    {
        $this->seed(ServiceSeeder::class);

        $this->seed(ServiceRequestSeeder::class);

        $this->assertSame(0, DB::table('tbl_service_request')->count());
    }
}
