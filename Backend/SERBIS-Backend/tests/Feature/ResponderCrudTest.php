<?php

namespace Tests\Feature;

use App\Models\Responder;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/** Responders section: CRUD, photo upload and removal, and the section gate in front of both. */
class ResponderCrudTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = config('filesystems.uploads.public');
        Storage::fake($this->disk);
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::RESPONDERS]));
    }

    private function responder(array $overrides = []): Responder
    {
        return Responder::create($overrides + [
            'name' => 'Juan Dela Cruz',
            'contact_no' => '09171234567',
            'position' => 'Driver',
            'status' => 'available',
        ]);
    }

    private function photo(string $name = 'face.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, $name === 'b.png' ? 'image/png' : 'image/jpeg');
    }

    public function test_create_list_show_and_update(): void
    {
        $id = $this->postJson('/api/responders', ['name' => 'Ana', 'contact_no' => '09171234567', 'position' => 'Logistics'])
            ->assertCreated()->assertJsonPath('name', 'Ana')->json('responder_id');

        $this->getJson('/api/responders')->assertOk()->assertJsonFragment(['name' => 'Ana']);
        $this->getJson("/api/responders/{$id}")->assertOk()->assertJsonPath('position', 'Logistics');

        $this->putJson("/api/responders/{$id}", ['status' => 'off_duty'])->assertOk()->assertJsonPath('status', 'off_duty');
        $this->assertSame('off_duty', Responder::find($id)->status);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->postJson('/api/responders', ['name' => '', 'contact_no' => 'abc', 'position' => 'Cook'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'contact_no', 'position']);
    }

    public function test_an_unknown_id_is_a_404(): void
    {
        $this->getJson('/api/responders/999999')->assertNotFound();
        $this->putJson('/api/responders/999999', ['name' => 'X'])->assertNotFound();
        $this->deleteJson('/api/responders/999999')->assertNotFound();
        $this->postJson('/api/responders/999999/photo', ['photo' => $this->photo()])->assertNotFound();
    }

    public function test_delete_removes_the_row_and_its_photo(): void
    {
        $responder = $this->responder();
        $this->postJson("/api/responders/{$responder->getKey()}/photo", ['photo' => $this->photo()])->assertOk();
        $path = $responder->fresh()->photo_path;

        $this->deleteJson("/api/responders/{$responder->getKey()}")->assertOk();

        $this->assertNull(Responder::find($responder->getKey()));
        Storage::disk($this->disk)->assertMissing($path);
    }

    public function test_a_deployed_responder_cannot_be_deleted(): void
    {
        $responder = $this->responder(['status' => 'deployed']);

        $this->deleteJson("/api/responders/{$responder->getKey()}")->assertStatus(422);

        $this->assertNotNull(Responder::find($responder->getKey()));
    }

    public function test_a_photo_is_stored_and_its_url_returned(): void
    {
        $responder = $this->responder();

        $response = $this->postJson("/api/responders/{$responder->getKey()}/photo", ['photo' => $this->photo()])->assertOk();

        $path = $responder->fresh()->photo_path;
        $this->assertStringStartsWith("responder-photos/{$responder->getKey()}/", $path);
        Storage::disk($this->disk)->assertExists($path);
        $this->assertNotEmpty($response->json('photo_url'));
    }

    public function test_a_new_photo_replaces_and_deletes_the_old_file(): void
    {
        $responder = $this->responder();
        $this->postJson("/api/responders/{$responder->getKey()}/photo", ['photo' => $this->photo('a.jpg')])->assertOk();
        $first = $responder->fresh()->photo_path;

        $this->postJson("/api/responders/{$responder->getKey()}/photo", ['photo' => $this->photo('b.png')])->assertOk();

        $second = $responder->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk($this->disk)->assertMissing($first);
        Storage::disk($this->disk)->assertExists($second);
    }

    public function test_photo_rules_refuse_a_missing_file_a_wrong_type_and_an_oversize_one(): void
    {
        $id = $this->responder()->getKey();

        $this->postJson("/api/responders/{$id}/photo", [])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->postJson("/api/responders/{$id}/photo", ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->postJson("/api/responders/{$id}/photo", ['photo' => UploadedFile::fake()->create('big.jpg', 5000, 'image/jpeg')])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_deleting_the_photo_clears_the_column_and_the_file(): void
    {
        $responder = $this->responder();
        $this->postJson("/api/responders/{$responder->getKey()}/photo", ['photo' => $this->photo()])->assertOk();
        $path = $responder->fresh()->photo_path;

        $this->deleteJson("/api/responders/{$responder->getKey()}/photo")->assertOk();

        $this->assertNull($responder->fresh()->photo_path);
        Storage::disk($this->disk)->assertMissing($path);
    }

    public function test_deleting_a_photo_that_is_not_there_is_harmless(): void
    {
        $responder = $this->responder();

        $this->deleteJson("/api/responders/{$responder->getKey()}/photo")->assertOk();
    }

    public function test_an_account_without_the_section_gets_403_on_every_responder_route(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::LOGS], 'other@test.local'));
        $id = $this->responder()->getKey();

        $this->getJson('/api/responders')->assertForbidden();
        $this->postJson('/api/responders', ['name' => 'X', 'contact_no' => '09171234567', 'position' => 'Driver'])->assertForbidden();
        $this->putJson("/api/responders/{$id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/responders/{$id}")->assertForbidden();
        $this->postJson("/api/responders/{$id}/photo", ['photo' => $this->photo()])->assertForbidden();
        $this->deleteJson("/api/responders/{$id}/photo")->assertForbidden();

        $this->assertNotNull(Responder::find($id));
    }

    public function test_without_a_token_it_is_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->refreshApplication();

        $this->getJson('/api/responders')->assertUnauthorized();
    }
}
