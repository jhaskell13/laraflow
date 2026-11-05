<?php

declare(strict_types=1);

namespace Laraflow\StatsD;

use Illuminate\Contracts\Config\Repository as Config;
use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsBridgeServiceProvider;
use Laraflow\Observability\Metrics\MetricsSink;
use Laraflow\StatsD\Console\PushGaugesCommand;

/**
 * laraflow/statsd: counters and timings pushed over UDP as events fire,
 * and `laraflow:statsd-gauges` for the snapshot, scheduled by the
 * application.
 *
 * The rules every bridge keeps — its own configuration, off until
 * enabled, the tenant label opt-in, the one event mapping — are
 * inherited from MetricsBridgeServiceProvider.
 */
final class StatsDServiceProvider extends MetricsBridgeServiceProvider
{
    private ?StatsDSink $sink = null;

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([PushGaugesCommand::class]);
        }
    }

    protected function configName(): string
    {
        return 'laraflow-statsd';
    }

    protected function configPath(): string
    {
        return dirname(__DIR__).'/config/laraflow-statsd.php';
    }

    protected function sink(MetricCatalog $catalog): MetricsSink
    {
        $config = $this->app->make(Config::class);

        $transport = $this->app->bound(Transport::class)
            ? $this->app->make(Transport::class)
            : new UdpTransport(
                (string) $config->get('laraflow-statsd.host', '127.0.0.1'),
                (int) $config->get('laraflow-statsd.port', 8125),
            );

        return $this->sink = new StatsDSink($transport, (string) $config->get('laraflow-statsd.dialect', 'dogstatsd'));
    }

    protected function bootBridge(MetricCatalog $catalog): void
    {
        $this->app->instance(StatsDSink::class, $this->sink);
        $this->app->instance(MetricCatalog::class, $catalog);
    }
}
