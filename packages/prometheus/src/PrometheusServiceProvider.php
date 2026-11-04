<?php

declare(strict_types=1);

namespace Laraflow\Prometheus;

use Illuminate\Contracts\Config\Repository as Config;
use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsBridgeServiceProvider;
use Laraflow\Observability\Metrics\MetricsSink;
use Laraflow\Prometheus\Console\WipeCommand;

/**
 * laraflow/prometheus: counters and histograms kept in shared storage,
 * and a scrape endpoint the application mounts with Prometheus::routes().
 *
 * The rules every bridge keeps — its own configuration, off until
 * enabled, the tenant label opt-in, the one event mapping — are
 * inherited from MetricsBridgeServiceProvider; this class supplies the
 * sink and binds it, with the catalog, for the scrape endpoint.
 */
final class PrometheusServiceProvider extends MetricsBridgeServiceProvider
{
    private ?PrometheusSink $sink = null;

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([WipeCommand::class]);
        }
    }

    protected function configName(): string
    {
        return 'laraflow-prometheus';
    }

    protected function configPath(): string
    {
        return dirname(__DIR__).'/config/laraflow-prometheus.php';
    }

    protected function sink(MetricCatalog $catalog): MetricsSink
    {
        $config = $this->app->make(Config::class);

        return $this->sink = new PrometheusSink(
            storage: fn () => Storage::make($config),
            buckets: $this->buckets($config->get('laraflow-prometheus.buckets')),
        );
    }

    protected function bootBridge(MetricCatalog $catalog): void
    {
        $this->app->instance(PrometheusSink::class, $this->sink);
        $this->app->instance(MetricCatalog::class, $catalog);
    }

    /**
     * @return array<string, list<float>>
     */
    private function buckets(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        $buckets = [];

        foreach ($configured as $family => $bounds) {
            if (is_string($family) && is_array($bounds) && $bounds !== []) {
                $buckets[$family] = array_values(array_map('floatval', $bounds));
            }
        }

        return $buckets;
    }
}
