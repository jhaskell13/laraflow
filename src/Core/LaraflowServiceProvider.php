<?php

declare(strict_types=1);

namespace Laraflow\Core;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Console\CancelCommand;
use Laraflow\Console\DemoCommand;
use Laraflow\Console\ExecuteCommand;
use Laraflow\Console\ListCommand;
use Laraflow\Console\PruneCommand;
use Laraflow\Console\RecoverCommand;
use Laraflow\Console\ShowCommand;
use Laraflow\Console\SummaryCommand;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\LifecycleLogger;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Clock;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\LoggingContext;
use Laraflow\Support\PassthroughRedactor;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Policy\PolicyResolver;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\RecoveryService;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\RetentionPolicy;
use Laraflow\Workflow\RetentionService;
use Laraflow\Workflow\WorkflowResolver;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
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
        $this->app->singleton(WorkflowResolver::class);

        // Bound by interface so an application (or a test) can replace the
        // package's source of time wholesale.
        $this->app->singleton(ClockInterface::class, Clock::class);

        $this->app->singleton(ExecutionRepository::class, EloquentExecutionRepository::class);

        $this->app->singleton(ErrorRedactor::class, function (Container $app): ErrorRedactor {
            $class = $this->setting($app->make(Config::class), 'laraflow.errors.redactor');

            if ($class === null) {
                return new PassthroughRedactor;
            }

            // A redactor that silently fails to load would silently leak
            // the data it was configured to strip; misconfiguration must
            // be loud.
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, ErrorRedactor::class)) {
                throw new InvalidArgumentException(
                    'laraflow.errors.redactor must name a class implementing '.ErrorRedactor::class.'.'
                );
            }

            $redactor = $app->make($class);
            assert($redactor instanceof ErrorRedactor);

            return $redactor;
        });

        // The configured policy is only a fallback: a step's own policy,
        // then its workflow's, take precedence (see PolicyResolver).
        $this->app->singleton(PolicyResolver::class, function (Container $app): PolicyResolver {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $timeout = $this->setting($config, 'laraflow.timeout.step');

            return new PolicyResolver(new StepPolicy(
                maxAttempts: (int) ($this->setting($config, 'laraflow.retry.max_attempts') ?? 1),
                baseDelaySeconds: (int) ($this->setting($config, 'laraflow.retry.base_delay') ?? 5),
                multiplier: (float) ($this->setting($config, 'laraflow.retry.multiplier') ?? 2.0),
                maxDelaySeconds: (int) ($this->setting($config, 'laraflow.retry.max_delay') ?? 300),
                jitter: (float) ($this->setting($config, 'laraflow.retry.jitter') ?? 0.2),
                timeoutSeconds: $timeout === null ? null : (int) $timeout,
            ));
        });

        $this->app->singleton(LoggingContext::class, function (Container $app): LoggingContext {
            /** @var Config $config */
            $config = $app->make(Config::class);

            // Null repository = disabled, so the runner's call sites
            // stay unconditional. The bound() check matters because
            // Laravel's context repository is registered by the
            // framework, not by this package: a non-Laravel container
            // resolving the runner must still work.
            $enabled = (bool) $config->get('laraflow.logging.context', true)
                && $app->bound(ContextRepository::class);

            return new LoggingContext($enabled ? $app->make(ContextRepository::class) : null);
        });

        $this->app->singleton(EventEmitter::class, fn (Container $app): EventEmitter => new EventEmitter(
            $app->make(EventDispatcher::class),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(WorkflowRunner::class, function (Container $app): WorkflowRunner {
            /** @var Config $config */
            $config = $app->make(Config::class);

            // Blank means unset, as everywhere else: a stray
            // LARAFLOW_MAX_PAYLOAD_BYTES= in .env must not cast to 0
            // and reject every payload the application has.
            $maxPayload = $this->setting($config, 'laraflow.execution.max_payload_bytes');

            return new WorkflowRunner(
                repository: $app->make(ExecutionRepository::class),
                container: $app,
                logger: $app->make(LoggerInterface::class),
                staleAfterSeconds: (int) $config->get('laraflow.execution.stale_after', 300),
                maxErrorLength: (int) $config->get('laraflow.execution.max_error_length', 65535),
                events: $app->make(EventEmitter::class),
                includeErrorMessages: (bool) $config->get('laraflow.events.include_error_messages', true),
                clock: $app->make(ClockInterface::class),
                policies: $app->make(PolicyResolver::class),
                redactor: $app->make(ErrorRedactor::class),
                logging: $app->make(LoggingContext::class),
                maxPayloadBytes: $maxPayload === null ? null : (int) $maxPayload,
            );
        });

        $this->app->singleton(WorkflowDispatcher::class, fn (Container $app): WorkflowDispatcher => new WorkflowDispatcher(
            runner: $app->make(WorkflowRunner::class),
            repository: $app->make(ExecutionRepository::class),
            bus: $app->make(Bus::class),
            config: $app->make(Config::class),
            events: $app->make(EventEmitter::class),
            clock: $app->make(ClockInterface::class),
        ));

        $this->app->singleton(RetentionPolicy::class, function (Container $app): RetentionPolicy {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $retention = $config->get('laraflow.retention', []);

            return RetentionPolicy::fromArray(is_array($retention) ? $retention : []);
        });

        $this->app->singleton(RetentionService::class, fn (Container $app): RetentionService => new RetentionService(
            repository: $app->make(ExecutionRepository::class),
            clock: $app->make(ClockInterface::class),
        ));

        $this->app->singleton(RecoveryService::class, fn (Container $app): RecoveryService => new RecoveryService(
            repository: $app->make(ExecutionRepository::class),
            dispatcher: $app->make(WorkflowDispatcher::class),
            runner: $app->make(WorkflowRunner::class),
            resolver: $app->make(WorkflowResolver::class),
            events: $app->make(EventEmitter::class),
            clock: $app->make(ClockInterface::class),
        ));
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMigrations();
        $this->registerCommands();
        $this->registerConnectors();
        $this->registerLifecycleLogging();
    }

    /**
     * Log every lifecycle event, when the application asks for it.
     *
     * A wildcard listener rather than one registration per event class,
     * so an event added in a later version is logged without this
     * method being touched.
     */
    protected function registerLifecycleLogging(): void
    {
        if (! (bool) $this->config()->get('laraflow.logging.lifecycle', false)) {
            return;
        }

        $this->app->make(EventDispatcher::class)->listen(
            'Laraflow\Events\*',
            [LifecycleLogger::class, 'handle'],
        );
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
            CancelCommand::class,
            DemoCommand::class,
            ExecuteCommand::class,
            ListCommand::class,
            PruneCommand::class,
            RecoverCommand::class,
            ShowCommand::class,
            SummaryCommand::class,
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

    /**
     * Read a configuration value, treating an empty string as absent.
     *
     * A declared-but-blank environment variable (`LARAFLOW_RETRY_MAX_ATTEMPTS=`)
     * reaches the config repository as `''`, which casts to 0 or 0.0 and
     * would be rejected by StepPolicy's validation — turning a stray
     * blank line in .env into a container resolution failure, and so
     * into an application that cannot run any workflow at all. An
     * unset value means "use the documented default".
     */
    protected function setting(Config $config, string $key): mixed
    {
        $value = $config->get($key);

        return $value === '' ? null : $value;
    }
}
