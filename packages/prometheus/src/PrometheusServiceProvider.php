<?php

declare(strict_types=1);

namespace Laraflow\Prometheus;

use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsBridgeServiceProvider;
use Laraflow\Observability\Metrics\MetricsSink;
use LogicException;

/**
 * laraflow/prometheus: counters and histograms kept in shared storage (Redis or APCu), and a scrape endpoint the application mounts — denied by default outside `local`, as the dashboard is — that reads the snapshot on each scrape.
 *
 * The rules every bridge keeps — its own configuration, off until
 * enabled, the tenant label opt-in, the one event mapping — are
 * inherited from MetricsBridgeServiceProvider; this class supplies the
 * sink. Under construction in Milestone 11 (step 3).
 */
final class PrometheusServiceProvider extends MetricsBridgeServiceProvider
{
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
        throw new LogicException('laraflow/prometheus is under construction (Milestone 11, step 3) and cannot be enabled yet.');
    }
}
