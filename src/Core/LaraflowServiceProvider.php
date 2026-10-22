<?php

namespace Laraflow\Core;

use Illuminate\Support\ServiceProvider;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;

class LaraflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/laraflow.php',
            'laraflow'
        );

        $this->app->singleton(
            ConnectorFactory::class,
            fn () => new ConnectorFactory()
        );

        $this->app->singleton(
            ConnectorManager::class,
            fn () => new ConnectorManager()
        );

        $this->app->singleton(
            LaraflowManager::class,
            fn ($app) => new LaraflowManager(
                $app->make(ConnectorFactory::class),
                $app->make(ConnectorManager::class)
            )
        );

        $this->app->singleton(
            ExecutionRepository::class,
            fn () => new EloquentExecutionRepository()
        );

        $this->app->singleton(
            WorkflowRunner::class,
            fn ($app) => new WorkflowRunner(
                $app->make(ExecutionRepository::class)
            )
        );
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMigrations();
        $this->registerConnectors();
    }

    /**
     * Load the package migrations automatically.
     *
     * Applications may disable this (for example, to customise the schema)
     * and publish the migrations instead via the `laraflow-migrations` tag.
     */
    protected function registerMigrations(): void
    {
        if (! config('laraflow.migrations.load', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../../config/laraflow.php' => config_path('laraflow.php'),
        ], 'laraflow-config');

        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'laraflow-migrations');
    }

    protected function registerConnectors(): void
    {
        $laraflow = $this->app->make(LaraflowManager::class);

        $laraflow->register(
            'github',
            fn () => new GithubConnector(
                new GithubTokenAuthenticator(
                    (string) config('laraflow.integrations.github.token')
                )
            )
        );
    }
}
