<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * POST /register takes an individual (head_of_family, the default) or an
 * organization. Barangay accounts are made by staff and are refused here. A
 * self-registered organization is Pending (Inactive) and cannot file anything
 * until an admin activates it; an individual is unaffected.
 */
class RegisterAccountTypeTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // PhilSMS has no sandbox: an escaped request is a billed real send.
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ian',
            'last_name' => 'Uy',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234567',
            'email_address' => 'isu@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    /** Registers, reads the texted code, verifies, and returns the created row. */
    private function registerAndVerify(array $overrides = []): Resident
    {
        $body = $this->payload($overrides);

        $this->postJson('/api/register', $body)->assertStatus(201);

        $code = Http::recorded()
            ->map(function ($pair) {
                preg_match('/[0-9]{6}/', $pair[0]['message'] ?? '', $m);

                return $m[0] ?? null;
            })
            ->filter()->last();

        $this->postJson('/api/resident/verify-email', [
            'email_address' => $body['email_address'],
            'code' => $code,
        ])->assertOk();

        return Resident::where('email_address', $body['email_address'])->firstOrFail();
    }

    public function test_an_individual_is_the_default(): void
    {
        $resident = $this->registerAndVerify(['email_address' => 'juan@test.local']);

        $this->assertSame('head_of_family', $resident->account_type);
        $this->assertNull($resident->organization_name);
        $this->assertSame('Inactive', $resident->status);
    }

    public function test_an_organization_registers_pending_with_its_name(): void
    {
        $resident = $this->registerAndVerify([
            'account_type' => 'organization',
            'organization_name' => 'Isabela State University',
        ]);

        $this->assertSame('organization', $resident->account_type);
        $this->assertSame('Isabela State University', $resident->organization_name);
        $this->assertSame('Inactive', $resident->status);
        $this->assertTrue($resident->isAwaitingApproval());
        // First and last name are the contact person.
        $this->assertSame('Ian', $resident->first_name);
    }

    public function test_an_organization_needs_a_name(): void
    {
        $this->postJson('/api/register', $this->payload(['account_type' => 'organization']))
            ->assertStatus(422)->assertJsonValidationErrors(['organization_name']);
    }

    public function test_a_barangay_cannot_be_registered_from_the_app(): void
    {
        $this->postJson('/api/register', $this->payload(['account_type' => 'barangay']))
            ->assertStatus(422)->assertJsonValidationErrors(['account_type']);

        $this->postJson('/api/register', $this->payload(['account_type' => 'office']))
            ->assertStatus(422)->assertJsonValidationErrors(['account_type']);

        $this->assertNull(Cache::get('signup:pending:'.hash('sha256', 'isu@test.local')));
        $this->assertSame(0, Resident::count());
    }

    public function test_an_organization_name_is_ignored_for_an_individual(): void
    {
        $resident = $this->registerAndVerify([
            'email_address' => 'juan@test.local',
            'account_type' => 'head_of_family',
            'organization_name' => 'Should Not Stick',
        ]);

        $this->assertNull($resident->organization_name);
    }

    public function test_a_pending_organization_cannot_file_a_request_or_borrow(): void
    {
        Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'x']);
        $road = Service::create(['service_name' => 'Road Clearing', 'description' => 'x']);
        $equipment = Equipment::create([
            'item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available',
        ]);

        $org = $this->registerAndVerify([
            'account_type' => 'organization',
            'organization_name' => 'Isabela State University',
        ]);

        $this->actingAs($org)->postJson('/api/service-requests', [
            'service_id' => $road->service_id,
            'description' => 'Fallen tree',
            'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
        ])->assertStatus(403)->assertJsonPath('code', 'account_pending');

        $this->actingAs($org)->postJson('/api/borrowings', [
            'equipment_id' => $equipment->getKey(), 'quantity' => 1, 'purpose' => 'Drill',
        ])->assertStatus(403)->assertJsonPath('code', 'account_pending');

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_an_activated_organization_can_file(): void
    {
        Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'x']);
        $road = Service::create(['service_name' => 'Road Clearing', 'description' => 'x']);

        $org = $this->registerAndVerify([
            'account_type' => 'organization',
            'organization_name' => 'Isabela State University',
        ]);
        $org->update(['status' => 'Active']);

        $this->actingAs($org)->postJson('/api/service-requests', [
            'service_id' => $road->service_id,
            'description' => 'Fallen tree',
            'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
        ])->assertStatus(201);
    }

    public function test_a_pending_individual_can_still_file(): void
    {
        Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'x']);
        $road = Service::create(['service_name' => 'Road Clearing', 'description' => 'x']);

        $juan = $this->registerAndVerify(['email_address' => 'juan@test.local']);
        $this->assertSame('Inactive', $juan->status);

        $this->actingAs($juan)->postJson('/api/service-requests', [
            'service_id' => $road->service_id,
            'description' => 'Fallen tree',
            'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
        ])->assertStatus(201);
    }

    public function test_a_pending_admin_created_barangay_account_is_not_held_back(): void
    {
        // Only organizations wait for approval; a barangay account is made active
        // by staff, and one left Inactive by hand is not treated as pending.
        $hall = new Resident([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Rosa',
            'last_name' => 'Hall',
            'phone_number' => '09170000012',
            'email_address' => 'hall@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Inactive',
        ]);
        $hall->account_type = Resident::TYPE_BARANGAY;
        $hall->save();

        $this->assertFalse($hall->isAwaitingApproval());
    }
}
