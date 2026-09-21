<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * Resident Requests and Ambulance share one set of routes, so which rows an
 * admin may see, and which records they may change, follows the service each
 * request names: ambulance requests belong to Ambulance, everything else —
 * "Others" included — to Resident Requests.
 */
class ServiceRequestSectionScopeTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private Resident $resident;

    private Service $ambulance;

    private Service $roadClearing;

    private ServiceRequest $ambulanceRequest;

    private ServiceRequest $plainRequest;

    private ServiceRequest $othersRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'phone_number' => '+639171234567',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->ambulance = Service::create(['service_name' => 'Ambulance/Medical Response']);
        $this->roadClearing = Service::create(['service_name' => 'Road Clearing']);

        $this->ambulanceRequest = $this->fileRequest($this->ambulance->service_id, 'Ambulance');
        $this->plainRequest = $this->fileRequest($this->roadClearing->service_id, 'Road');
        // The "Others" request has no service row at all.
        $this->othersRequest = $this->fileRequest(null, 'Something else');
    }

    private function fileRequest(?int $serviceId, string $description): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->resident_id,
            'service_id' => $serviceId,
            'description' => $description,
            'status' => 'Pending',
        ]);
    }

    private function idsFrom($response, string $path = 'data'): array
    {
        $rows = $path === '' ? $response->json() : $response->json($path);

        return collect($rows)->pluck('request_id')->sort()->values()->all();
    }

    private function ids(ServiceRequest ...$requests): array
    {
        return collect($requests)->pluck('request_id')->sort()->values()->all();
    }

    // ---- what each list shows ---------------------------------------------

    public function test_resident_requests_sees_everything_that_is_not_ambulance_others_included(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $this->assertSame(
            $this->ids($this->plainRequest, $this->othersRequest),
            $this->idsFrom($this->getJson('/api/admin/service-requests')->assertOk())
        );
    }

    public function test_ambulance_sees_only_ambulance_requests(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::AMBULANCE]));

        $this->assertSame(
            $this->ids($this->ambulanceRequest),
            $this->idsFrom($this->getJson('/api/admin/service-requests')->assertOk())
        );
    }

    public function test_holding_both_sections_sees_every_request(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS, AdminSections::AMBULANCE]));

        $this->assertSame(
            $this->ids($this->ambulanceRequest, $this->plainRequest, $this->othersRequest),
            $this->idsFrom($this->getJson('/api/admin/service-requests')->assertOk())
        );
    }

    public function test_an_account_with_no_permission_list_still_sees_every_request(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->assertSame(
            $this->ids($this->ambulanceRequest, $this->plainRequest, $this->othersRequest),
            $this->idsFrom($this->getJson('/api/admin/service-requests')->assertOk())
        );
    }

    public function test_the_shared_list_the_app_also_uses_is_narrowed_the_same_way_for_staff(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::AMBULANCE]));

        $this->assertSame(
            $this->ids($this->ambulanceRequest),
            $this->idsFrom($this->getJson('/api/service-requests')->assertOk(), '')
        );
    }

    public function test_a_resident_still_sees_all_of_their_own_requests(): void
    {
        Sanctum::actingAs($this->resident);

        $this->assertSame(
            $this->ids($this->ambulanceRequest, $this->plainRequest, $this->othersRequest),
            $this->idsFrom($this->getJson('/api/service-requests')->assertOk(), '')
        );
    }

    // ---- one record --------------------------------------------------------

    public function test_reading_a_request_from_the_other_section_is_refused(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $this->getJson("/api/service-requests/{$this->plainRequest->request_id}")->assertOk();
        $this->getJson("/api/service-requests/{$this->othersRequest->request_id}")->assertOk();
        $this->getJson("/api/service-requests/{$this->ambulanceRequest->request_id}")
            ->assertStatus(403)->assertJsonPath('code', 'section_forbidden');
    }

    public function test_the_other_way_round_is_refused_too(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::AMBULANCE]));

        $this->getJson("/api/service-requests/{$this->ambulanceRequest->request_id}")->assertOk();
        $this->getJson("/api/service-requests/{$this->plainRequest->request_id}")->assertStatus(403);
    }

    public function test_an_attachment_is_refused_before_anyone_learns_whether_there_is_one(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        // The ambulance request has no site photo. Refused all the same, so the
        // answer does not depend on whether a file exists.
        $this->getJson("/api/service-requests/{$this->ambulanceRequest->request_id}/site-photo")->assertStatus(403);
        // In the section, a request with no file is an ordinary 404.
        $this->getJson("/api/service-requests/{$this->plainRequest->request_id}/site-photo")->assertStatus(404);
    }

    public function test_changing_a_request_in_the_other_section_is_refused_and_leaves_it_alone(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $this->patchJson("/api/service-requests/{$this->ambulanceRequest->request_id}", ['description' => 'Tampered'])
            ->assertStatus(403);
        $this->patchJson("/api/service-requests/{$this->ambulanceRequest->request_id}/approve", ['vehicle_id' => 1])
            ->assertStatus(403);
        $this->patchJson("/api/service-requests/{$this->ambulanceRequest->request_id}/reschedule", [])
            ->assertStatus(403);
        $this->deleteJson("/api/service-requests/{$this->ambulanceRequest->request_id}")->assertStatus(403);

        $this->assertSame('Ambulance', $this->ambulanceRequest->fresh()->description);
        $this->assertNotNull(ServiceRequest::find($this->ambulanceRequest->request_id));
    }

    public function test_deleting_in_the_section_you_hold_works(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $this->deleteJson("/api/service-requests/{$this->plainRequest->request_id}")->assertOk();
        $this->assertNull(ServiceRequest::find($this->plainRequest->request_id));
    }

    public function test_a_request_cannot_be_moved_into_a_section_the_admin_does_not_hold(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        // Turning a Road Clearing request into an ambulance one would put it out
        // of their reach and into the other board's, which is not theirs to do.
        $this->patchJson("/api/service-requests/{$this->plainRequest->request_id}", [
            'service_id' => $this->ambulance->service_id,
        ])->assertStatus(403);

        $this->assertSame($this->roadClearing->service_id, $this->plainRequest->fresh()->service_id);
    }

    public function test_holding_both_sections_may_move_a_request_between_them(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS, AdminSections::AMBULANCE]));

        $this->patchJson("/api/service-requests/{$this->plainRequest->request_id}", [
            'description' => 'Still Road Clearing',
        ])->assertOk();
    }

    // ---- filing ------------------------------------------------------------

    public function test_a_walk_in_cannot_be_filed_into_the_other_section(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $before = ServiceRequest::count();

        $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Juan',
            'walk_in_contact_number' => '09171234567',
            'service_id' => $this->ambulance->service_id,
        ])->assertStatus(403)->assertJsonPath('code', 'section_forbidden');

        $this->assertSame($before, ServiceRequest::count());
    }

    public function test_a_walk_in_in_your_own_section_is_not_refused_on_section_grounds(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::REQUESTS]));

        $response = $this->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Juan',
            'walk_in_contact_number' => '09171234567',
            'service_id' => $this->roadClearing->service_id,
            'description' => 'Fallen tree',
        ]);

        $this->assertNotSame('section_forbidden', $response->json('code'));
    }
}
