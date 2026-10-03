<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Migration 2026_10_02_100000: one Responding request per vehicle at the DB
 * level, and the vehicle FK is RESTRICT.
 */
class ActiveVehicleUniqueTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private Vehicle $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Service::create(['service_name' => 'Road Clearing', 'description' => 'Debris removal.']);
        $this->unit = Vehicle::create(['unit_identifier' => 'RES-01', 'type' => 'Rescue Vehicle', 'status' => 'Available']);
    }

    private function row(string $status): ServiceRequest
    {
        return ServiceRequest::create([
            'walk_in_name' => 'Juan Dela Cruz',
            'walk_in_contact_number' => '09171234567',
            'service_id' => $this->service->service_id,
            'description' => 'Test',
            'status' => $status,
            'vehicle_id' => $this->unit->vehicle_id,
        ]);
    }

    public function test_the_database_refuses_a_second_responding_row_on_one_vehicle(): void
    {
        $this->row('Responding');
        // Any number of non-Responding rows may share it.
        $this->row('Resolved');
        $this->row('Pending');

        try {
            $this->row('Responding');
            $this->fail('A second Responding row on one vehicle was accepted.');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
            $this->assertStringContainsString('tbl_service_request_active_vehicle_unique', $e->getMessage());
        }
    }

    public function test_deleting_a_vehicle_any_request_references_returns_422(): void
    {
        $this->row('Resolved');

        $this->actingAs(User::create([
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'role' => 'Admin',
            'email_address' => 'ana@test.local', 'password' => Hash::make('password123'),
        ]))->deleteJson("/api/vehicles/{$this->unit->vehicle_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete this unit: 1 service request(s) reference it. Set it to Maintenance instead.');

        $this->assertNotNull($this->unit->fresh());
    }
}
