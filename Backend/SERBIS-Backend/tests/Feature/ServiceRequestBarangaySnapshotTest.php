<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\ServiceRequest;
use App\Support\BarangayRequestCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A request keeps the barangay it was filed under. Residents can move
 * themselves (PATCH /me); their past requests must not move with them.
 */
class ServiceRequestBarangaySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $home;

    private Barangay $elsewhere;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->elsewhere = Barangay::create(['barangay_name' => 'San Miguel']);

        $this->resident = Resident::create([
            'barangay_id' => $this->home->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    private function file(?Resident $resident = null): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $resident?->getKey(),
            'walk_in_name' => $resident ? null : 'Counter visitor',
            'status' => 'Pending',
        ]);
    }

    public function test_a_request_takes_the_residents_barangay_when_filed(): void
    {
        $this->assertSame($this->home->barangay_id, $this->file($this->resident)->barangay_id);
    }

    public function test_moving_keeps_old_requests_and_routes_new_ones(): void
    {
        $before = $this->file($this->resident);

        $this->actingAs($this->resident)->patchJson('/api/me', [
            'barangay_id' => $this->elsewhere->barangay_id,
        ])->assertOk();

        $after = $this->file($this->resident->refresh());

        $this->assertSame($this->home->barangay_id, $before->refresh()->barangay_id);
        $this->assertSame($this->elsewhere->barangay_id, $after->barangay_id);
    }

    public function test_a_walk_in_has_no_barangay(): void
    {
        $this->assertNull($this->file()->barangay_id);
    }

    public function test_reassigning_the_request_retakes_the_barangay(): void
    {
        $other = Resident::create([
            'barangay_id' => $this->elsewhere->barangay_id,
            'first_name' => 'Jose',
            'last_name' => 'Reyes',
            'phone_number' => '09172222222',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        $request = $this->file($this->resident);

        $request->update(['resident_id' => $other->getKey()]);

        $this->assertSame($this->elsewhere->barangay_id, $request->refresh()->barangay_id);
    }

    public function test_the_map_counts_a_request_where_it_was_filed(): void
    {
        $this->file($this->resident);
        $this->resident->update(['barangay_id' => $this->elsewhere->barangay_id]);

        $counts = collect(BarangayRequestCounts::forWindow()['barangays'])->pluck('requests', 'name');

        $this->assertSame(1, $counts['San Fabian'] ?? 0);
        $this->assertArrayNotHasKey('San Miguel', $counts->all());
    }
}
