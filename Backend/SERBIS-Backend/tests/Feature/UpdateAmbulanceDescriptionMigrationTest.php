<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateAmbulanceDescriptionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_30_100000_update_ambulance_service_description.php');
    }

    private function ambulance(string $description): Service
    {
        return Service::create(['service_name' => 'Ambulance/Medical Response', 'description' => $description]);
    }

    public function test_the_seeded_text_is_replaced_and_restored(): void
    {
        $service = $this->ambulance('Emergency medical response and ambulance services.');
        $migration = $this->migration();

        $migration->up();
        $this->assertSame('Ambulance transport for non-life-threatening medical needs.', $service->fresh()->description);

        $migration->down();
        $this->assertSame('Emergency medical response and ambulance services.', $service->fresh()->description);
    }

    public function test_a_description_staff_edited_is_left_alone(): void
    {
        $service = $this->ambulance('Patient transport within Echague, call ahead.');

        $this->migration()->up();

        $this->assertSame('Patient transport within Echague, call ahead.', $service->fresh()->description);
    }
}
