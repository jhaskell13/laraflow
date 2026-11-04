<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support\Metrics;

use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsBridgeServiceProvider;
use Laraflow\Observability\Metrics\MetricsSink;
use Laraflow\Testing\RecordingMetricsSink;

/**
 * The smallest bridge there is: what MetricsBridgeServiceProvider asks
 * of one, rendering into a RecordingMetricsSink the test can read.
 */
final class RecordingBridgeServiceProvider extends MetricsBridgeServiceProvider
{
    public static ?RecordingMetricsSink $sink = null;

    public static ?MetricCatalog $catalog = null;

    protected function configName(): string
    {
        return 'laraflow-recording';
    }

    protected function configPath(): string
    {
        return __DIR__.'/laraflow-recording.php';
    }

    protected function sink(MetricCatalog $catalog): MetricsSink
    {
        self::$catalog = $catalog;

        return self::$sink = new RecordingMetricsSink;
    }
}
