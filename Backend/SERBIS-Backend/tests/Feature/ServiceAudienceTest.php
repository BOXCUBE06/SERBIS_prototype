<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceAudience;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which account types may request which service. The seeded mapping is the
 * office's own (relief goods for barangays only; trainings and drills for
 * barangays and organizations; everything else for all three), and the panel
 * can change it without a deploy.
 */
class ServiceAudienceTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICES = [
        'Ambulance/Medical Response', 'Relief Goods Distribution', 'Road Clearing',
        'Power Line Repair', 'Debris Removal', 'Animal Rescue', 'Sandbagging',
        'DRRM Trainings and Seminars', 'Simulation Drills / NSED', 'MDRRMO Certification',
    ];

    private Resident $head;

    private Resident $barangay;

    private Resident $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.uploads.private'));

        foreach (self::SERVICES as $name) {
            Service::create(['service_name' => $name, 'description' => 'x']);
        }

        $brgy = Barangay::create(['barangay_name' => 'San Miguel']);

        $this->head = $this->account($brgy, 'head@test.local', '09171000001', Resident::TYPE_HEAD_OF_FAMILY);
        $this->barangay = $this->account($brgy, 'hall@test.local', '09171000002', Resident::TYPE_BARANGAY);
        $this->organization = $this->account($brgy, 'isu@test.local', '09171000003', Resident::TYPE_ORGANIZATION, 'ISU');

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);
    }

    private function account(Barangay $brgy, string $email, string $phone, string $type, ?string $org = null): Resident
    {
        $resident = new Resident([
            'barangay_id' => $brgy->barangay_id,
            'first_name' => 'Test',
            'last_name' => ucfirst($type),
            'phone_number' => $phone,
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        $resident->account_type = $type;
        $resident->organization_name = $org;
        $resident->save();

        return $resident;
    }

    /** What the app shows: the service rows it is sent plus the two extras it is told about. */
    private function visibleCount(Resident $account): int
    {
        $response = $this->actingAs($account)->getJson('/api/services')->assertOk();
        $audience = $response->json('audience');

        return count($response->json('data'))
            + (int) $audience['equipment_borrowing']
            + (int) $audience['others'];
    }

    private function serviceId(string $name): int
    {
        return Service::where('service_name', $name)->value('service_id');
    }

    public function test_a_head_of_the_family_sees_nine_of_the_twelve(): void
    {
        $this->assertSame(9, $this->visibleCount($this->head));
    }

    public function test_a_barangay_sees_all_twelve(): void
    {
        $this->assertSame(12, $this->visibleCount($this->barangay));
    }

    public function test_an_organization_sees_eleven_and_no_relief_goods(): void
    {
        $this->assertSame(11, $this->visibleCount($this->organization));

        $names = collect($this->actingAs($this->organization)->getJson('/api/services')->json('data'))->pluck('service_name');
        $this->assertNotContains('Relief Goods Distribution', $names->all());
        $this->assertContains('DRRM Trainings and Seminars', $names->all());
    }

    public function test_a_head_of_the_family_does_not_see_the_programs_for_organizations(): void
    {
        $names = collect($this->actingAs($this->head)->getJson('/api/services')->json('data'))->pluck('service_name')->all();

        $this->assertNotContains('Relief Goods Distribution', $names);
        $this->assertNotContains('DRRM Trainings and Seminars', $names);
        $this->assertNotContains('Simulation Drills / NSED', $names);
        $this->assertContains('MDRRMO Certification', $names);
    }

    public function test_the_backend_refuses_relief_goods_from_a_head_of_the_family(): void
    {
        $this->actingAs($this->head)
            ->postJson('/api/service-requests', [
                'service_id' => $this->serviceId('Relief Goods Distribution'),
                'description' => 'Food packs for 5',
                'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'service_not_allowed');

        $this->assertSame(0, ServiceRequest::count());
        $this->assertSame([], Storage::disk(config('filesystems.uploads.private'))->allFiles());
    }

    public function test_a_barangay_can_request_relief_goods(): void
    {
        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', [
                'service_id' => $this->serviceId('Relief Goods Distribution'),
                'description' => 'Food packs for 40 households',
                'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            ])
            ->assertStatus(201);
    }

    public function test_an_organization_is_refused_relief_goods_too(): void
    {
        $this->actingAs($this->organization)
            ->postJson('/api/service-requests', [
                'service_id' => $this->serviceId('Relief Goods Distribution'),
                'description' => 'Food packs',
                'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            ])
            ->assertStatus(403);
    }

    public function test_others_and_borrowing_follow_the_mapping_too(): void
    {
        $equipment = Equipment::create([
            'item_name' => 'Rubber Boat', 'total_quantity' => 4, 'available_quantity' => 4, 'status' => 'Available',
        ]);
        $borrow = ['equipment_id' => $equipment->getKey(), 'quantity' => 1, 'purpose' => 'Drill'];

        $this->actingAs($this->head)->postJson('/api/borrowings', $borrow)->assertStatus(201);

        // Untick Equipment Borrowing for heads of the family and the same call is refused.
        $this->actingAs($this->admin)
            ->putJson('/api/service-audience/'.ServiceAudience::EQUIPMENT_BORROWING, ['account_types' => ['barangay', 'organization']])
            ->assertOk();

        $this->actingAs($this->head)->postJson('/api/borrowings', $borrow)
            ->assertStatus(403)->assertJsonPath('code', 'service_not_allowed');
        $this->actingAs($this->barangay)->postJson('/api/borrowings', $borrow)->assertStatus(201);

        $this->actingAs($this->admin)
            ->putJson('/api/service-audience/'.ServiceAudience::OTHERS, ['account_types' => ['barangay']])
            ->assertOk();

        $this->actingAs($this->head)
            ->postJson('/api/service-requests', [
                'description' => 'Something else',
                'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            ])
            ->assertStatus(403);

        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', [
                'description' => 'Something else',
                'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            ])
            ->assertStatus(201);
    }

    public function test_the_admin_list_has_all_twelve_with_their_types(): void
    {
        $data = collect($this->actingAs($this->admin)->getJson('/api/service-audience')->assertOk()->json('data'));

        $this->assertCount(12, $data);

        $relief = $data->firstWhere('code', 'relief-goods-distribution');
        $this->assertSame(['barangay'], $relief['account_types']);
        $this->assertTrue($relief['is_service']);

        $borrowing = $data->firstWhere('code', 'equipment-borrowing');
        $this->assertFalse($borrowing['is_service']);
        $this->assertEqualsCanonicalizing(Resident::ACCOUNT_TYPES, $borrowing['account_types']);
    }

    public function test_saving_the_mapping_changes_what_the_app_lists(): void
    {
        $this->assertSame(9, $this->visibleCount($this->head));

        $this->actingAs($this->admin)
            ->putJson('/api/service-audience/relief-goods-distribution', ['account_types' => ['head_of_family', 'barangay']])
            ->assertOk();

        $this->assertSame(10, $this->visibleCount($this->head));
        $this->assertSame(11, $this->visibleCount($this->organization));

        $this->actingAs($this->head)
            ->postJson('/api/service-requests', [
                'service_id' => $this->serviceId('Relief Goods Distribution'),
                'description' => 'Food packs for 5',
                'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            ])
            ->assertStatus(201);
    }

    public function test_a_service_cannot_be_saved_with_nothing_ticked(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/service-audience/road-clearing', ['account_types' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_types']);

        $this->assertSame(3, ServiceAudience::where('service_code', 'road-clearing')->count());
    }

    public function test_an_unknown_account_type_or_service_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/service-audience/road-clearing', ['account_types' => ['office']])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->putJson('/api/service-audience/not-a-service', ['account_types' => ['barangay']])
            ->assertNotFound();
    }

    public function test_a_service_added_later_is_open_to_every_type(): void
    {
        $service = Service::create(['service_name' => 'Tree Trimming', 'description' => 'x']);

        $this->assertEqualsCanonicalizing(Resident::ACCOUNT_TYPES, ServiceAudience::typesFor($service->code));
        $this->assertSame(10, $this->visibleCount($this->head));
    }

    public function test_only_an_admin_can_change_the_mapping(): void
    {
        $this->actingAs($this->barangay)
            ->putJson('/api/service-audience/road-clearing', ['account_types' => ['barangay']])
            ->assertStatus(403);

        $this->assertSame(3, ServiceAudience::where('service_code', 'road-clearing')->count());
    }

    public function test_the_change_is_written_to_the_system_log(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/service-audience/relief-goods-distribution', ['account_types' => ['barangay', 'organization']])
            ->assertOk();

        $this->assertDatabaseHas('tbl_system_logs', [
            'admin_id' => $this->admin->getKey(),
            'action_type' => 'created',
        ]);
    }
}
