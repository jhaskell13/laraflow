<?php

declare(strict_types=1);

namespace Laraflow\Core;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
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
use Laraflow\Integration\ConfigCredentialProvider;
use Laraflow\Integration\ConnectorRegistry;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\Http\LaravelHttpTransport;
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
use Laraflow\Workflow\RunnerOptions;
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
        $this->app->singleton(LaraflowManager::class);
        $this->app->singleton(WorkflowResolver::class);

        $this->app->singleton(ConnectorRegistry::class, fn (Container $app): ConnectorRegistry => new ConnectorRegistry(
            $app,
            $app->make(Config::class),
        ));

        // The transport is bound by interface so an application can route
        // every connector through its own; HttpOptions holds the
        // fleet-wide defaults each connection starts from.
        $this->app->singleton(HttpTransport::class, LaravelHttpTransport::class);
        $this->app->singleton(HttpOptions::class, fn (Container $app): HttpOptions => HttpOptions::fromConfig($app->make(Config::class)));

        // Bound by interface so an application can source credentials
        // from a vault or a per-tenant store; the default reads the
        // configuration on every request.
        $this->app->singleton(CredentialProvider::class, fn (Container $app): CredentialProvider => new ConfigCredentialProvider(
            $app->make(Config::class),
        ));

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

        // Reading and validating the runner's settings belongs with the
        // settings, not with the wiring: RunnerOptions handles the
        // blank-means-unset rule and rejects values that would make the
        // runner unusable.
        $this->app->singleton(
            RunnerOptions::class,
            fn (Container $app): RunnerOptions => RunnerOptions::fromConfig($app->make(Config::class)),
        );

        $this->app->singleton(WorkflowRunner::class, fn (Container $app): WorkflowRunner => new WorkflowRunner(
            repository: $app->make(ExecutionRepository::class),
            container: $app,
            logger: $app->make(LoggerInterface::class),
            options: $app->make(RunnerOptions::class),
            events: $app->make(EventEmitter::class),
            clock: $app->make(ClockInterface::class),
            policies: $app->make(PolicyResolver::class),
            redactor: $app->make(ErrorRedactor::class),
            logging: $app->make(LoggingContext::class),
        ));

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
     * Register every connector the configuration declares a class for.
     *
     * The bundled GitHub connector is registered this way too, as one
     * `laraflow.integrations` entry among any the application adds —
     * there is no privileged path for connectors that ship with the
     * package. Registration validates the class and nothing more:
     * connections are read and credentials checked when a connector is
     * first resolved.
     */
    protected function registerConnectors(): void
    {
        /** @var LaraflowManager $laraflow */
        $laraflow = $this->app->make(LaraflowManager::class);

        $integrations = $this->config()->get('laraflow.integrations', []);

        if (! is_array($integrations)) {
            throw new InvalidArgumentException('laraflow.integrations must be an array.');
        }

        foreach ($integrations as $name => $section) {
            if (! is_array($section) || ! isset($section['connector'])) {
                continue;
            }

            $class = $section['connector'];

            if (! is_string($class)) {
                throw new InvalidArgumentException("laraflow.integrations.{$name}.connector must be a class name.");
            }

            $laraflow->register((string) $name, $class);
        }
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
