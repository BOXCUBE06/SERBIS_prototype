<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The MDRRMO programs: DRRM Trainings and Seminars, Simulation Drills / NSED
 * (both booked for a day, at least 14 days out, with a request letter) and
 * MDRRMO Certification (no date, optional attachment). Requested by barangay
 * and organization accounts, which hold no government ID, so valid_id is not
 * asked of them.
 */
class ProgramServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private Resident $barangay;

    private Service $trainings;

    private Service $drills;

    private Service $certification;

    private Service $roadClearing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.uploads.private'));

        $brgy = Barangay::create(['barangay_name' => 'San Miguel']);

        $account = new Resident([
            'barangay_id' => $brgy->barangay_id,
            'first_name' => 'Rosa',
            'last_name' => 'Dizon',
            'phone_number' => '09171111111',
            'email_address' => 'hall@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        $account->account_type = Resident::TYPE_BARANGAY;
        $account->save();
        $this->barangay = $account;

        // store() resolves the ambulance service's id before validating.
        Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => 'x']);
        $this->roadClearing = Service::create(['service_name' => 'Road Clearing', 'description' => 'x']);
        $this->trainings = Service::create(['service_name' => 'DRRM Trainings and Seminars', 'description' => 'x']);
        $this->drills = Service::create(['service_name' => 'Simulation Drills / NSED', 'description' => 'x']);
        $this->certification = Service::create(['service_name' => 'MDRRMO Certification', 'description' => 'x']);
    }

    private function date(int $daysAhead): string
    {
        return now('Asia/Manila')->addDays($daysAhead)->toDateString();
    }

    private function scheduled(Service $service, array $overrides = []): array
    {
        return array_merge([
            'service_id' => $service->service_id,
            'description' => "Topic: First aid\nParticipants: 40\nLocation: Barangay hall",
            'preferred_date' => $this->date(14),
            'letter' => UploadedFile::fake()->create('letter.pdf', 200, 'application/pdf'),
        ], $overrides);
    }

    public function test_the_service_codes_are_stable(): void
    {
        $this->assertSame('drrm-trainings-and-seminars', $this->trainings->code);
        $this->assertSame('simulation-drills-nsed', $this->drills->code);
        $this->assertSame('mdrrmo-certification', $this->certification->code);
    }

    public function test_a_training_fourteen_days_out_is_accepted_with_no_valid_id(): void
    {
        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings))
            ->assertStatus(201)
            ->assertJsonPath('preferred_date', $this->date(14))
            ->assertJsonPath('has_letter', true)
            ->assertJsonPath('has_valid_id', false);

        $this->assertSame(1, ServiceRequest::count());
    }

    public function test_a_training_thirteen_days_out_is_refused(): void
    {
        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings, ['preferred_date' => $this->date(13)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferred_date']);

        $this->assertSame(0, ServiceRequest::count());
        $this->assertSame([], Storage::disk(config('filesystems.uploads.private'))->allFiles());
    }

    public function test_a_training_needs_a_date_and_a_letter(): void
    {
        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', [
                'service_id' => $this->trainings->service_id,
                'description' => 'Topic: First aid',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferred_date', 'letter']);
    }

    public function test_a_letter_can_be_a_photo_but_not_a_document_type_we_do_not_accept(): void
    {
        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings, [
                'letter' => UploadedFile::fake()->create('letter.jpg', 100, 'image/jpeg'),
            ]))
            ->assertStatus(201);

        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings, [
                'letter' => UploadedFile::fake()->create('letter.docx', 100),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['letter']);

        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings, [
                'letter' => UploadedFile::fake()->create('letter.pdf', 5000, 'application/pdf'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['letter']);
    }

    public function test_a_malformed_date_is_refused(): void
    {
        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings, ['preferred_date' => 'next month']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferred_date']);
    }

    public function test_the_letter_is_served_to_its_owner_and_hidden_from_everyone_else(): void
    {
        $id = $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings))
            ->assertStatus(201)
            ->json('request_id');

        $this->actingAs($this->barangay)->get("/api/service-requests/{$id}/letter")->assertOk();

        $other = new Resident([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'juan@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        $other->save();

        $this->actingAs($other)->get("/api/service-requests/{$id}/letter")->assertNotFound();
    }

    public function test_the_stored_path_is_never_in_a_response(): void
    {
        $response = $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', $this->scheduled($this->trainings))
            ->assertStatus(201);

        $this->assertArrayNotHasKey('letter', $response->json());
    }

    public function test_every_other_service_still_requires_a_valid_id_and_ignores_program_fields(): void
    {
        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', [
                'service_id' => $this->roadClearing->service_id,
                'description' => 'Fallen tree',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_id']);

        $this->actingAs($this->barangay)
            ->postJson('/api/service-requests', [
                'service_id' => $this->roadClearing->service_id,
                'description' => 'Fallen tree',
                'valid_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
                'preferred_date' => $this->date(30),
                'letter' => UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201)
            ->assertJsonPath('preferred_date', null)
            ->assertJsonPath('has_letter', false);
    }
}
