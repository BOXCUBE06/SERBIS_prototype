<?php

namespace Tests\Feature;

use App\Models\EmergencyHotline;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

class EmergencyHotlineTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'MDRRMO Duty',
            'label_fil' => 'MDRRMO Duty',
            'numbers' => [['label' => 'Globe', 'number' => '0917-000-0000']],
        ], $overrides);
    }

    public function test_migration_seeds_the_five_hotlines_and_the_list_is_public(): void
    {
        $this->getJson('/api/hotlines')
            ->assertOk()
            ->assertJsonCount(5)
            ->assertJsonPath('0.label', 'Echague Rescue Hotline')
            ->assertJsonPath('0.numbers.1.number', '0917-626-2352')
            ->assertJsonPath('4.numbers.0.number', '911');
    }

    public function test_guest_cannot_write(): void
    {
        $this->postJson('/api/hotlines', $this->payload())->assertUnauthorized();
    }

    public function test_admin_without_the_section_is_refused(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::FILES]));

        $this->postJson('/api/hotlines', $this->payload())->assertForbidden();
    }

    public function test_admin_with_the_section_can_create_update_and_delete(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::HOTLINES]));

        $id = $this->postJson('/api/hotlines', $this->payload())->assertCreated()->json('hotline_id');

        $this->putJson("/api/hotlines/{$id}", $this->payload(['label' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('label', 'Renamed');

        $this->deleteJson("/api/hotlines/{$id}")->assertOk();
        $this->assertNull(EmergencyHotline::find($id));
    }

    public function test_bad_number_is_rejected(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->postJson('/api/hotlines', $this->payload(['numbers' => [['number' => 'call me']]]))
            ->assertStatus(422)->assertJsonValidationErrors('numbers.0.number');
        $this->postJson('/api/hotlines', $this->payload(['numbers' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('numbers');
    }
}
