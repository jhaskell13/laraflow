<?php

declare(strict_types=1);

namespace Laraflow\Prometheus;

use Closure;
use Laraflow\Observability\Metrics\MetricDefinition;
use Laraflow\Observability\Metrics\MetricsSink;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

/**
 * Laraflow's metrics, kept for Prometheus to scrape.
 *
 * **Counters and histograms live in shared storage** (Redis or APCu):
 * the events that feed them fire on queue workers, and the scrape is
 * answered by a web process, so a per-process store would show each
 * scrape only what one process saw.
 *
 * **Gauges never touch storage.** They are rendered from the snapshot
 * taken for each scrape (docs/metrics.md, "Two sources, and why both"),
 * held in this object for that one request, and replaced whole — so a
 * workflow that drained stops being reported instead of staying in
 * Redis at its last count forever.
 *
 * The storage adapter is built on first use, never at boot: an enabled
 * application boots this sink on every request and every worker job,
 * and connecting to Redis for a request that records nothing would be
 * a cost the metrics did not earn.
 */
final class PrometheusSink implements MetricsSink
{
    private ?CollectorRegistry $registry = null;

    /** @var array<string, array{metric: MetricDefinition, series: list<array{labels: array<string, string>, value: float}>}> */
    private array $gauges = [];

    /**
     * @param  Closure(): Adapter  $storage  Builds the shared adapter, once, on first use.
     * @param  array<string, list<float>>  $buckets  Histogram buckets by family name, replacing the catalog's defaults.
     */
    public function __construct(
        private readonly Closure $storage,
        private readonly array $buckets = [],
    ) {}

    public function increment(MetricDefinition $metric, array $labels, float $by = 1.0): void
    {
        $this->registry()
            ->getOrRegisterCounter('', $metric->name, $metric->help, $metric->labels)
            ->incBy($by, array_values($labels));
    }

    public function observe(MetricDefinition $metric, array $labels, float $value): void
    {
        $this->registry()
            ->getOrRegisterHistogram('', $metric->name, $metric->help, $metric->labels, $this->buckets[$metric->name] ?? $metric->buckets)
            ->observe($value, array_values($labels));
    }

    public function replaceGauge(MetricDefinition $metric, array $series): void
    {
        $this->gauges[$metric->name] = ['metric' => $metric, 'series' => $series];
    }

    /**
     * The exposition text for one scrape: every stored counter and
     * histogram, and the gauges the caller just published.
     */
    public function render(): string
    {
        $gauges = new CollectorRegistry(new InMemory, false);

        foreach ($this->gauges as ['metric' => $metric, 'series' => $series]) {
            $gauge = $gauges->registerGauge('', $metric->name, $metric->help, $metric->labels);

            foreach ($series as $one) {
                $gauge->set($one['value'], array_values($one['labels']));
            }
        }

        return (new RenderTextFormat)->render(array_merge(
            $this->registry()->getMetricFamilySamples(),
            $gauges->getMetricFamilySamples(),
        ));
    }

    /**
     * Delete every stored counter and histogram. What a deploy that
     * changes bucket boundaries needs: Prometheus cannot read a family
     * whose buckets changed under it.
     */
    public function wipe(): void
    {
        $this->registry()->wipeStorage();
    }

    private function registry(): CollectorRegistry
    {
        // Laraflow's families only: the client's default php_info
        // gauge is not a series docs/metrics.md defines, and nothing
        // else may appear under this endpoint.
        return $this->registry ??= new CollectorRegistry(($this->storage)(), false);
    }
}
