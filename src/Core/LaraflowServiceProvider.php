<?php

namespace Laraflow\Core;

use Illuminate\Support\ServiceProvider;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\Github\GithubConnector;

class LaraflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/laraflow.php' => config_path('laraflow.php'),
        ], 'laraflow-config');

        $this->registerConnectors();
    }

    protected function registerConnectors(): void
    {
        $laraflow = $this->app->make(LaraflowManager::class);

        $laraflow->register(
            'github',
            fn () => new GithubConnector(
                new GithubTokenAuthenticator(
                    config('laraflow.integrations.github.token')
                )
            )
        );
    }
}