<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard that stops a production deployment booting with APP_DEBUG=true.
 *
 * Exercised through the public static method rather than by booting a second
 * application: the guard has to run before anything else in boot(), and a test
 * that spun up a whole production-environment app to reach it would be testing
 * the framework's bootstrap more than this check.
 *
 * The environment is swapped with detectEnvironment(), which is what the
 * framework itself calls, and restored in tearDown so a failure here cannot
 * leave the rest of the suite running as production.
 */
class ProductionDebugGuardTest extends TestCase
{
    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->app->environment();
    }

    protected function tearDown(): void
    {
        $restore = $this->originalEnvironment;
        $this->app->detectEnvironment(fn () => $restore);

        parent::tearDown();
    }

    private function runGuard(string $environment, bool $debug): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['app.debug' => $debug]);

        AppServiceProvider::assertDebugIsOffInProduction();
    }

    public function test_production_with_debug_on_refuses_to_boot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSING TO START');

        $this->runGuard('production', true);
    }

    public function test_the_refusal_names_the_variable_and_the_way_out(): void
    {
        // An operator reads this on a deploy log at speed. It has to say which
        // variable, on which machine, and what to run afterwards — a message
        // that only says "misconfigured" costs an outage while somebody guesses.
        try {
            $this->runGuard('production', true);
            $this->fail('The guard did not throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG', $e->getMessage());
            $this->assertStringContainsString('APP_ENV is production', $e->getMessage());
            $this->assertStringContainsString('config:clear', $e->getMessage());
        }
    }

    public function test_production_with_debug_off_boots(): void
    {
        $this->runGuard('production', false);

        $this->assertTrue(true, 'The guard allowed a correctly configured production boot.');
    }

    /**
     * Debug is on in local development by design and must stay boot-safe there.
     * A guard that fired on APP_DEBUG alone would break every developer machine
     * and be switched off within a day.
     */
    public function test_debug_is_allowed_outside_production(): void
    {
        foreach (['local', 'testing', 'staging'] as $environment) {
            foreach ([true, false] as $debug) {
                $this->runGuard($environment, $debug);
            }
        }

        $this->assertTrue(true, 'No non-production environment was blocked.');
    }

    /**
     * The suite itself runs under APP_ENV=testing (phpunit.xml). If this ever
     * fails, every other test in the project is about to fail with it, and the
     * cause will not be obvious from those failures.
     */
    public function test_the_test_suite_is_not_running_as_production(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertFalse($this->app->environment('production'));
    }
}
