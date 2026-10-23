<?php

declare(strict_types=1);

namespace Laraflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Tests\Support\FixtureState;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture steps keep static state (run counters, failure switches);
        // resetting centrally means no test class can forget and leak state
        // into another under randomized ordering.
        FixtureState::reset();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaraflowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // The schema has always declared cascading foreign keys, but
            // SQLite only enforces them when asked — without this, tests
            // asserting on cascade behaviour pass vacuously.
            'foreign_key_constraints' => true,
        ]);
    }
}
