<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pickup/delivery beyond equipment borrowing (MDRRMO feedback, 2026-09-18) —
 * starting with relief goods. Mirrors tbl_equipment_borrowing's
 * fulfillment_method / delivery_address pair exactly, on tbl_service_request
 * instead. Offered on every service the same way `landmark` is (nothing
 * server-side restricts it to relief goods specifically), but only the
 * relief goods mobile form actually sends it — ambulance and conduction
 * don't map onto "pickup or delivery" at all.
 */
class ServiceRequestFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Service $reliefGoods;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        // store() unconditionally resolves the ambulance service's id to build
        // its required_if/required_unless rules — unrelated to this feature,
        // but store() throws before validation runs at all if it is missing.
        Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->reliefGoods = Service::create([
            'service_name' => 'Relief Goods Distribution',
            'description' => 'Distribution of essential relief goods during disasters.',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->reliefGoods->getKey(),
            'description' => 'Household head: Juan Dela Cruz',
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ], $overrides);
    }

    public function test_pickup_needs_no_delivery_address(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['fulfillment_method' => 'Pickup']))
            ->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertSame('Pickup', $request->fulfillment_method);
        $this->assertNull($request->delivery_address);
    }

    public function test_delivery_requires_an_address(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload(['fulfillment_method' => 'Delivery']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_address']);

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_delivery_with_an_address_is_stored(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'fulfillment_method' => 'Delivery',
                'delivery_address' => 'Purok 2, San Fabian',
            ]))
            ->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertSame('Delivery', $request->fulfillment_method);
        $this->assertSame('Purok 2, San Fabian', $request->delivery_address);
    }

    /** An address typed in and then switched to Pickup must not survive as a delivery instruction nobody is delivering against. */
    public function test_an_address_is_dropped_when_switched_back_to_pickup(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload([
                'fulfillment_method' => 'Pickup',
                'delivery_address' => 'Purok 2, San Fabian',
            ]))
            ->assertStatus(201);

        $this->assertNull(ServiceRequest::first()->delivery_address);
    }

    public function test_fulfillment_is_optional_and_defaults_to_neither(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/api/service-requests', $this->payload())
            ->assertStatus(201);

        $request = ServiceRequest::first();
        $this->assertNull($request->fulfillment_method);
        $this->assertNull($request->delivery_address);
    }
}
