<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a borrowing tells a client about its handover photos.
 *
 * `release_photo_path` and `return_photo_path` are storage paths on the private
 * disk. They were serialised in full to every client — the panel and the app
 * both keyed off them — which is the thing `valid_id` and `site_photo` were
 * hidden on ServiceRequest to stop: a path handed to a client is a path a client
 * can ask for. Clients now get `has_release_photo` / `has_return_photo` and
 * fetch the image from GET /borrowings/{id}/photo/{stage}, which checks
 * ownership first.
 *
 * Asserted on both the admin list and the owner's own read, since the two go
 * through different query paths (ScopesToOwner) and only one of them was ever
 * looked at.
 */
class EquipmentBorrowingPhotoSerialisationTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private User $admin;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'Silauan Norte']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 3,
            'status' => 'Available',
        ]);
    }

    /**
     * The columns are not fillable — uploadPhoto() writes them directly — so
     * the fixture does too.
     */
    private function borrowingWith(?string $releasePath, ?string $returnPath): EquipmentBorrowing
    {
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Flood evacuation',
            'status' => 'Returned',
        ]);

        $borrowing->release_photo_path = $releasePath;
        $borrowing->return_photo_path = $returnPath;
        $borrowing->save();

        return $borrowing;
    }

    public function test_the_storage_paths_never_reach_a_client(): void
    {
        $borrowing = $this->borrowingWith(
            'borrowing-photos/1/release.jpg',
            'borrowing-photos/1/return.jpg',
        );

        Sanctum::actingAs($this->admin);
        $adminRead = $this->getJson("/api/borrowings/{$borrowing->getKey()}")->assertOk();
        $adminRead->assertJsonMissingPath('release_photo_path');
        $adminRead->assertJsonMissingPath('return_photo_path');

        Sanctum::actingAs($this->resident);
        $ownerRead = $this->getJson("/api/borrowings/{$borrowing->getKey()}")->assertOk();
        $ownerRead->assertJsonMissingPath('release_photo_path');
        $ownerRead->assertJsonMissingPath('return_photo_path');

        // The list is the payload that carries every row at once, so a leak
        // there is the widest one.
        $list = $this->getJson('/api/borrowings')->assertOk();
        $this->assertStringNotContainsString('release_photo_path', $list->getContent());
        $this->assertStringNotContainsString('return_photo_path', $list->getContent());
        $this->assertStringNotContainsString('borrowing-photos/', $list->getContent());
    }

    /**
     * All four states a row can be in. The pair is not one flag: a loan can be
     * photographed on release and never on return, and staff photographing a
     * return after the fact is a late record rather than an impossible one.
     */
    public function test_the_booleans_answer_for_every_combination(): void
    {
        $cases = [
            'neither' => [null, null, false, false],
            'release only' => ['borrowing-photos/1/release.jpg', null, true, false],
            'return only' => [null, 'borrowing-photos/1/return.jpg', false, true],
            'both' => ['borrowing-photos/1/release.jpg', 'borrowing-photos/1/return.jpg', true, true],
        ];

        Sanctum::actingAs($this->admin);

        foreach ($cases as $label => [$releasePath, $returnPath, $hasRelease, $hasReturn]) {
            $borrowing = $this->borrowingWith($releasePath, $returnPath);

            $this->getJson("/api/borrowings/{$borrowing->getKey()}")
                ->assertOk()
                ->assertJsonPath('has_release_photo', $hasRelease, "release flag wrong for: {$label}")
                ->assertJsonPath('has_return_photo', $hasReturn, "return flag wrong for: {$label}");
        }
    }

    /**
     * uploadPhoto() answers with the row it just wrote, and the panel patches
     * the open record from that response — so the flag has to be true in it,
     * not only on the next fetch.
     */
    public function test_an_upload_answers_with_the_flag_already_true(): void
    {
        $borrowing = $this->borrowingWith(null, null);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->getKey()}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('release.jpg', 20, 'image/jpeg'),
        ])
            ->assertOk()
            ->assertJsonPath('has_release_photo', true)
            ->assertJsonPath('has_return_photo', false)
            ->assertJsonMissingPath('release_photo_path');
    }
}
