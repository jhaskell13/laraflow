<?php

namespace Laraflow\Core;

use Illuminate\Support\ServiceProvider;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Core\LaraflowManager;

class LaraflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ConnectorManager::class,
            fn () => new ConnectorManager()
        );

        $this->app->singleton(
            LaraflowManager::class,
            fn ($app) => new LaraflowManager($app->make(ConnectorManager::class))
        );
    }

    public function boot(): void
    {
        // ...
    }
}