<?php

declare(strict_types=1);

namespace Laraflow\StatsD;

use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsBridgeServiceProvider;
use Laraflow\Observability\Metrics\MetricsSink;
use LogicException;

/**
 * laraflow/statsd: counters and timings pushed over UDP as events fire, as plain StatsD or with DogStatsD tags, and a scheduled command that pushes the snapshot's gauges.
 *
 * The rules every bridge keeps — its own configuration, off until
 * enabled, the tenant label opt-in, the one event mapping — are
 * inherited from MetricsBridgeServiceProvider; this class supplies the
 * sink. Under construction in Milestone 11 (step 4).
 */
final class StatsDServiceProvider extends MetricsBridgeServiceProvider
{
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
        throw new LogicException('laraflow/statsd is under construction (Milestone 11, step 4) and cannot be enabled yet.');
    }
}
