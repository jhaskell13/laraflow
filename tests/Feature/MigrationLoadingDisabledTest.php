<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Laraflow\Core\LaraflowServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Boots the provider with automatic migration loading disabled, as an
 * application that publishes and manages the migrations itself would.
 */
class MigrationLoadingDisabledTest extends TestCase
{
    public function test_package_migrations_are_not_registered_when_loading_is_disabled(): void
    {
        $packagePath = realpath(__DIR__.'/../../database/migrations');

        // This case boots its own application rather than the suite's,
        // so it extends Testbench directly and narrows $app itself.
        $app = $this->app;
        $this->assertNotNull($app);

        $registered = array_filter(
            $app->make('migrator')->paths(),
            fn (string $path): bool => realpath($path) === $packagePath,
        );

        $this->assertSame([], $registered);

        $migrate = $this->artisan('migrate');
        $this->assertInstanceOf(PendingCommand::class, $migrate);
        $migrate->assertSuccessful();

        $this->assertFalse(Schema::hasTable('laraflow_workflow_executions'));
    }

    protected function getPackageProviders($app): array
    {
        return [LaraflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laraflow.migrations.load', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
