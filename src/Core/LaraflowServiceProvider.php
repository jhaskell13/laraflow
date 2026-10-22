<?php

declare(strict_types=1);

namespace Laraflow\Core;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\ServiceProvider;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Console\DemoCommand;
use Laraflow\Console\ExecuteCommand;
use Laraflow\Console\ListCommand;
use Laraflow\Console\ShowCommand;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;

class LaraflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/laraflow.php', 'laraflow');

        // Stateless services with resolvable constructors are autowired.
        $this->app->singleton(ConnectorFactory::class);
        $this->app->singleton(ConnectorManager::class);
        $this->app->singleton(LaraflowManager::class);

        $this->app->singleton(ExecutionRepository::class, EloquentExecutionRepository::class);

        $this->app->singleton(WorkflowRunner::class, function (Container $app): WorkflowRunner {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new WorkflowRunner(
                repository: $app->make(ExecutionRepository::class),
                container: $app,
                logger: $app->make(LoggerInterface::class),
                staleAfterSeconds: (int) $config->get('laraflow.execution.stale_after', 300),
                maxErrorLength: (int) $config->get('laraflow.execution.max_error_length', 65535),
            );
        });
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMigrations();
        $this->registerCommands();
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
        if (! $this->config()->get('laraflow.migrations.load', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DemoCommand::class,
            ExecuteCommand::class,
            ListCommand::class,
            ShowCommand::class,
        ]);
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/laraflow.php' => $this->app->configPath('laraflow.php'),
        ], 'laraflow-config');

        $this->publishes([
            __DIR__.'/../../database/migrations' => $this->app->databasePath('migrations'),
        ], 'laraflow-migrations');
    }

    /**
     * Bundled connectors are registered as lazy factories: configuration is
     * read and credentials validated only when the connector is first used.
     */
    protected function registerConnectors(): void
    {
        /** @var LaraflowManager $laraflow */
        $laraflow = $this->app->make(LaraflowManager::class);

        $laraflow->register('github', fn (): GithubConnector => new GithubConnector(
            new GithubTokenAuthenticator(
                (string) $this->config()->get('laraflow.integrations.github.token', '')
            ),
            $this->app->make(HttpClient::class),
        ));
    }

    protected function config(): Config
    {
        return $this->app->make(Config::class);
    }
}
