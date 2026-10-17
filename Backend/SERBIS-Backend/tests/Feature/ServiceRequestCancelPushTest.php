<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesFcm;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * Who hears about a cancellation. The resident cancelling their own request
 * needs no push; staff cancelling it for them (PATCH .../cancel is the only
 * route that writes Cancelled, update() refuses it) must reach the resident.
 */
class ServiceRequestCancelPushTest extends TestCase
{
    use FakesFcm, MakesAdmins, RefreshDatabase;

    private Resident $resident;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['fcm.googleapis.com/*' => $this->fcmAccepts()]);
        $this->configureFcm();

        $this->resident = Resident::create([
            'barangay_id' => Barangay::create(['barangay_name' => 'San Fabian'])->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '+639171111111',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        $this->deviceFor($this->resident);

        $this->request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => Service::create(['service_name' => 'Road Clearing', 'description' => 'Debris.'])->service_id,
            'description' => 'Fallen tree',
            'status' => 'Pending',
        ]);
    }

    public function test_staff_cancelling_a_request_pushes_the_resident(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->patchJson("/api/service-requests/{$this->request->getKey()}/cancel")->assertOk();

        $this->assertSame('Cancelled', $this->request->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => $sent['message']['token'] === 'device-1'
            && str_contains($sent['message']['notification']['body'], 'Road Clearing')
            && str_contains($sent['message']['notification']['body'], 'cancelled by MDRRMO')
            && $sent['message']['data']['request_id'] === (string) $this->request->getKey());
    }

    public function test_a_resident_cancelling_their_own_request_sends_no_push(): void
    {
        Sanctum::actingAs($this->resident);

        $this->patchJson("/api/service-requests/{$this->request->getKey()}/cancel")->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_refused_staff_cancel_sends_no_push(): void
    {
        $this->request->update(['status' => 'Resolved']);
        Sanctum::actingAs($this->makeStaff());

        $this->patchJson("/api/service-requests/{$this->request->getKey()}/cancel")->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_staff_cancel_still_succeeds_when_the_resident_has_no_device(): void
    {
        DeviceToken::query()->delete();
        Sanctum::actingAs($this->makeStaff());

        $this->patchJson("/api/service-requests/{$this->request->getKey()}/cancel")->assertOk();

        $this->assertSame('Cancelled', $this->request->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_update_path_cannot_cancel_so_it_cannot_push_one(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->putJson("/api/service-requests/{$this->request->getKey()}", ['status' => 'Cancelled'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('Pending', $this->request->fresh()->status);
        Http::assertNothingSent();
    }
}
