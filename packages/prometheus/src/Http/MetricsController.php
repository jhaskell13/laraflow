<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Response;
use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\SnapshotMetrics;
use Laraflow\Observability\Snapshots;
use Laraflow\Prometheus\PrometheusSink;
use Prometheus\RenderTextFormat;
use Psr\Clock\ClockInterface;

/**
 * @internal One scrape: take the snapshot, publish its gauges into the
 * sink for this request, and render everything.
 *
 * Mounted while the bridge is disabled, the endpoint answers 404: there
 * is nothing behind it, and a scrape target that returned an empty 200
 * would look healthy while recording nothing.
 */
final class MetricsController
{
    public function __invoke(Container $app, Snapshots $snapshots, ClockInterface $clock): Response
    {
        if (! $app->bound(PrometheusSink::class)) {
            return new Response('laraflow/prometheus is not enabled.', 404, ['Content-Type' => 'text/plain']);
        }

        $sink = $app->make(PrometheusSink::class);

        (new SnapshotMetrics($sink, $app->make(MetricCatalog::class)))->publish($snapshots->take($clock->now()));

        return new Response($sink->render(), 200, ['Content-Type' => RenderTextFormat::MIME_TYPE]);
    }
}
