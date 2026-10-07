<?php

namespace Tests\Feature;

use App\Models\Responder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A responder's position is one of the four roles on a response team:
 * Team Leader, Assistant Leader, Logistics or Driver. Anything else is refused
 * on create and on edit.
 */
class ResponderPositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::create([
            'first_name' => 'Super', 'last_name' => 'Admin', 'password' => Hash::make('Password123'),
            'role' => 'Admin', 'status' => 'Active',
        ]);
        $admin->forceFill(['is_super_admin' => true, 'permissions' => null])->save();
        Sanctum::actingAs($admin);
    }

    public function test_each_of_the_four_positions_is_accepted(): void
    {
        foreach (Responder::POSITIONS as $i => $position) {
            $this->postJson('/api/responders', [
                'name' => "Responder $i",
                'contact_no' => '09171234567',
                'position' => $position,
            ])->assertCreated()->assertJsonPath('position', $position);
        }

        $this->assertSame(4, Responder::count());
    }

    public function test_any_other_position_is_refused_on_create(): void
    {
        foreach (['EMT', 'Rescuer', 'team leader', ''] as $position) {
            $this->postJson('/api/responders', [
                'name' => 'Juan Dela Cruz',
                'contact_no' => '09171234567',
                'position' => $position,
            ])->assertStatus(422)->assertJsonValidationErrors('position');
        }

        $this->assertSame(0, Responder::count());
    }

    public function test_the_migration_moves_old_free_text_onto_the_four(): void
    {
        foreach (['Ambulance Driver', 'Boat Operator', 'Emergency Medical Technician', 'Rescuer', 'Nurse', ' team leader ', 'driver'] as $i => $old) {
            Responder::create(['name' => "R$i", 'contact_no' => '09171234567', 'position' => $old, 'status' => 'available']);
        }

        (require database_path('migrations/2026_10_06_090000_limit_responder_positions.php'))->up();

        $this->assertSame(
            ['Driver', 'Driver', 'Logistics', 'Logistics', 'Logistics', 'Team Leader', 'Driver'],
            Responder::orderBy('responder_id')->pluck('position')->all(),
        );
    }

    public function test_an_edit_cannot_set_another_position(): void
    {
        $responder = Responder::create([
            'name' => 'Juan Dela Cruz', 'contact_no' => '09171234567', 'position' => 'Driver', 'status' => 'available',
        ]);

        $this->putJson("/api/responders/{$responder->responder_id}", ['position' => 'Nurse'])
            ->assertStatus(422)->assertJsonValidationErrors('position');
        $this->assertSame('Driver', $responder->fresh()->position);

        $this->putJson("/api/responders/{$responder->responder_id}", ['position' => 'Team Leader'])
            ->assertOk();
        $this->assertSame('Team Leader', $responder->fresh()->position);
    }
}
