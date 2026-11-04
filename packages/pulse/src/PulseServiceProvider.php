<?php

declare(strict_types=1);

namespace Laraflow\Pulse;

use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsBridgeServiceProvider;
use Laraflow\Observability\Metrics\MetricsSink;
use LogicException;

/**
 * laraflow/pulse: pulse recorders over the same events, and cards for the Pulse dashboard: throughput, failures, slowest steps, tenant deferrals.
 *
 * The rules every bridge keeps — its own configuration, off until
 * enabled, the tenant label opt-in, the one event mapping — are
 * inherited from MetricsBridgeServiceProvider; this class supplies the
 * sink. Under construction in Milestone 11 (step 5).
 */
final class PulseServiceProvider extends MetricsBridgeServiceProvider
{
    protected function configName(): string
    {
        return 'laraflow-pulse';
    }

    protected function configPath(): string
    {
        return dirname(__DIR__).'/config/laraflow-pulse.php';
    }

    protected function sink(MetricCatalog $catalog): MetricsSink
    {
        throw new LogicException('laraflow/pulse is under construction (Milestone 11, step 5) and cannot be enabled yet.');
    }
}
