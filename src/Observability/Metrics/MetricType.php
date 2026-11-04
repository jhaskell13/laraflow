<?php

declare(strict_types=1);

namespace Laraflow\Observability\Metrics;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * The three kinds of series docs/metrics.md defines. Counters only
 * grow, histograms measure, gauges say what is.
 */
enum MetricType: string
{
    case Counter = 'counter';
    case Histogram = 'histogram';
    case Gauge = 'gauge';
}
