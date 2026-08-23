<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` answers with JSON rather than a rendered view. The assertion is on
     * the body, not just the status: a Blade page would also return 200, and
     * the point of the change is that no view — and so no Vite manifest, and
     * so no Node in the image — is involved.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->getJson('/');

        $response->assertStatus(200)
            ->assertExactJson([
                'service' => 'SERBIS API',
                'health' => '/up',
            ]);
    }
}
