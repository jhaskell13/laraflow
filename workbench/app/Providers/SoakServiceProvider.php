<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laraflow\Support\Clock;
use Laraflow\Workflow\ExecutionRepository;
use Psr\Clock\ClockInterface;
use Workbench\App\Soak\Connectors\SoakConnector;
use Workbench\App\Soak\Env;
use Workbench\App\Soak\SkewedClock;
use Workbench\App\Soak\Stats\StatsSink;
use Workbench\App\Soak\Stats\TimingRepository;
use Workbench\App\Soak\Workflows\EveryMinuteWorkflow;
use Workbench\App\Soak\Workflows\NoOverlapWorkflow;

/**
 * Turns a Testbench process into a soak process (docs/soak.md).
 *
 * Does nothing unless Env::active(): the playground and the ordinary
 * Testbench CLI never see it. In a soak process it points the database
 * at the engine the LARAFLOW_TEST_DB_* variables name, configures
 * Laraflow for a run that lasts minutes rather than months, skews the
 * clock where a process was told to be wrong, registers the stub
 * provider as a connector, and wraps the repository so the hot path is
 * timed.
 *
 * Everything is applied from `booting`, which runs after every provider
 * has registered — so Laraflow's own configuration has been merged and
 * these settings override individual keys rather than whole sections —
 * and before any provider boots, so Laraflow's boot sees the connector
 * and the schedules this provider configured.
 */
class SoakServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! Env::active()) {
            return;
        }

        // The connection is not Laraflow's configuration and must exist
        // before anything resolves a connection, so it is set here.
        $connection = Env::databaseConnection();
        $config = $this->app->make(Repository::class);
        $config->set('database.connections.soak', $connection);
        $config->set('database.default', 'soak');

        // SQLite refuses to open a file that is not there, and every
        // soak process shares one: the first to start creates it.
        if ($connection['driver'] === 'sqlite' && is_string($connection['database']) && ! is_file($connection['database'])) {
            @mkdir(dirname($connection['database']), 0775, true);
            touch($connection['database']);
        }

        $this->app->singleton(StatsSink::class, fn (): StatsSink => new StatsSink(Env::string(Env::STATS_FILE)));

        /** @var Application $app */
        $app = $this->app;

        $app->booting(function () use ($app): void {
            $this->configureLaraflow();

            $offset = Env::int(Env::CLOCK_OFFSET, 0);

            if ($offset !== 0) {
                $app->singleton(ClockInterface::class, fn (): ClockInterface => new SkewedClock(new Clock, $offset));
            }

            $app->extend(ExecutionRepository::class, fn (ExecutionRepository $repository, Container $container): ExecutionRepository => new TimingRepository(
                $repository,
                $container->make(StatsSink::class),
            ));
        });
    }

    /**
     * Laraflow, configured for a run measured in minutes: a short stale
     * claim so recovery is exercised often; the pending scan on, so a
     * dispatch lost to a queue outage is found; the abandonment bound
     * lifted, so an execution interrupted many times by the fault
     * injector is recovered rather than parked for an operator
     * (docs/soak.md says why); the schedules, tenants, and connector
     * the workload needs.
     */
    private function configureLaraflow(): void
    {
        $config = $this->app->make(Repository::class);
        $staleAfter = Env::staleAfterSeconds();

        // Ordered as docs/queue-and-recovery.md asks: the job timeout
        // below the queue's retry_after (Env::retryAfterSeconds(), set
        // on every spawned process) below the stale claim.
        $config->set('laraflow.execution.stale_after', $staleAfter);
        $config->set('laraflow.queue.timeout', max(10, Env::retryAfterSeconds() - 10));
        $config->set('laraflow.recovery.pending_after', 20);
        $config->set('laraflow.recovery.abandon_after_attempts', 1000);
        $config->set('laraflow.recovery.redispatch_after', 10);
        $config->set('laraflow.recovery.limit', 200);
        $config->set('laraflow.retry.dispatch_delayed', true);
        $config->set('laraflow.logging.lifecycle', true);

        $config->set('laraflow.schedules.workflows', [EveryMinuteWorkflow::class, NoOverlapWorkflow::class]);
        $config->set('laraflow.schedules.redispatch_after', 30);

        $config->set('laraflow.tenants.deferral', 5);
        $config->set('laraflow.tenants.overrides', [
            'flood' => ['max_concurrent' => 2, 'max_claims_per_minute' => 40],
        ]);

        $config->set('laraflow.integrations.soak', [
            'connector' => SoakConnector::class,
            'default' => 'default',
            'connections' => [
                'default' => [
                    'base_url' => Env::string(Env::STUB_URL, 'http://127.0.0.1:1'),
                    'timeout' => 5,
                    'connect_timeout' => 2,
                    'retries' => ['max_attempts' => 3, 'base_delay_ms' => 100, 'max_delay_ms' => 500, 'max_retry_after' => 3],
                    'rate_limit' => ['max' => 80, 'per' => 5, 'max_wait' => 1],
                    'credentials' => ['token' => Env::string(Env::TOKEN, 'soak-secret-unset')],
                ],
            ],
        ]);
    }
}
