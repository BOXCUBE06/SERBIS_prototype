<?php

namespace Tests\Feature;

use App\Models\Responder;
use App\Support\AdminSections;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/** The shared field rules (App\Rules\Charset, FieldRules) and free-text cleaning (Text) at the API. */
class FieldValidationTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::RESPONDERS, AdminSections::VEHICLES]));
    }

    private function responderBody(array $overrides = []): array
    {
        return $overrides + ['name' => 'Juan Dela Cruz', 'contact_no' => '09171234567', 'position' => 'Driver'];
    }

    public function test_a_responder_can_be_added_and_edited_with_each_status(): void
    {
        foreach (Responder::STATUSES as $status) {
            $id = $this->postJson('/api/responders', $this->responderBody(['status' => $status]))
                ->assertCreated()->assertJsonPath('status', $status)->json('responder_id');

            foreach (Responder::STATUSES as $next) {
                $this->putJson("/api/responders/{$id}", ['status' => $next])->assertOk()->assertJsonPath('status', $next);
            }
        }
    }

    public function test_a_status_label_is_not_a_status(): void
    {
        $this->postJson('/api/responders', $this->responderBody(['status' => 'Off Duty']))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_a_name_error_lands_on_the_name_with_a_plain_message(): void
    {
        $response = $this->postJson('/api/responders', $this->responderBody(['name' => 'Juan 3']))
            ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonMissingValidationErrors('status');

        $this->assertSame(
            'The name can only contain letters, spaces, hyphens, apostrophes and periods.',
            $response->json('errors.name.0'),
        );
    }

    public function test_names_take_enye_and_the_usual_punctuation(): void
    {
        foreach (['Ma. Niña', "Peña-O'Brien", 'Ñito Cruz'] as $name) {
            $this->postJson('/api/responders', $this->responderBody(['name' => $name]))->assertCreated();
        }
    }

    public function test_a_name_is_two_to_fifty_characters(): void
    {
        foreach (['J', str_repeat('a', 51)] as $name) {
            $this->postJson('/api/responders', $this->responderBody(['name' => $name]))
                ->assertStatus(422)->assertJsonValidationErrors('name');
        }
    }

    public function test_a_contact_number_must_be_a_mobile_number(): void
    {
        foreach (['0917123456', '19171234567', '0917-123-4567', 'abc'] as $number) {
            $this->postJson('/api/responders', $this->responderBody(['contact_no' => $number]))
                ->assertStatus(422)->assertJsonValidationErrors('contact_no');
        }

        // Older app builds still send the country-code shapes.
        foreach (['639171234567', '+639171234567'] as $number) {
            $this->postJson('/api/responders', $this->responderBody(['contact_no' => $number]))->assertCreated();
        }
    }

    public function test_a_vehicle_unit_takes_letters_numbers_spaces_hyphens_and_parentheses_only(): void
    {
        $this->postJson('/api/vehicles', ['unit_identifier' => 'AMB-01 (Type I)', 'type' => 'Ambulance', 'specification' => 'Type II (Basic)', 'status' => 'Available'])
            ->assertCreated();

        foreach ([['unit_identifier' => 'AMB@02'], ['unit_identifier' => 'AMB-03', 'specification' => 'Type #1!']] as $bad) {
            $this->postJson('/api/vehicles', $bad + ['type' => 'Ambulance', 'status' => 'Available'])->assertStatus(422);
        }
    }

    public function test_free_text_is_stripped_trimmed_and_capitalized(): void
    {
        $this->assertSame('Flooded road near the school', Text::clean("  flooded road <b>near</b> the school\x07 "));
        $this->assertSame('Ñandú sighted', Text::clean('ñandú sighted'));
        $this->assertSame('', Text::clean('   '));
    }
}
