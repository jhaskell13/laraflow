<?php

declare(strict_types=1);

namespace Laraflow\Pulse;

use Laraflow\Observability\Metrics\MetricDefinition;
use Laraflow\Observability\Metrics\MetricsSink;
use Laravel\Pulse\Pulse;

/**
 * Laraflow's metrics recorded into Laravel Pulse.
 *
 * Pulse aggregates by a type and one key, so each series family is a
 * Pulse type under its docs/metrics.md name, and each series' labels
 * are its key, as JSON in the definition's label order — the names stay
 * exactly the contract's, and every label is recoverable from the key.
 *
 * - **Counters** are entries aggregated by `sum`.
 * - **Histograms** are entries aggregated by `avg`, `max`, and `count`.
 *   Pulse stores integers, so a duration is recorded in whole
 *   **milliseconds** under the `_seconds` name; the cards convert. This
 *   is the one place a backend's storage forces another unit, and it is
 *   stated in the README.
 * - **Gauges** are Pulse values, replaced whole: the family's values are
 *   purged before the new ones are set, so a drained workflow is gone
 *   rather than shown at its last count.
 *
 * Recording only buffers in memory; Pulse ingests at the end of the
 * request or job, as it does for its own recorders.
 */
final class PulseSink implements MetricsSink
{
    public function __construct(private readonly Pulse $pulse) {}

    public function increment(MetricDefinition $metric, array $labels, float $by = 1.0): void
    {
        $this->pulse->record($metric->name, self::key($labels), (int) round($by))->sum();
    }

    public function observe(MetricDefinition $metric, array $labels, float $value): void
    {
        $this->pulse->record($metric->name, self::key($labels), (int) round($value * 1000))->avg()->max()->count();
    }

    public function replaceGauge(MetricDefinition $metric, array $series): void
    {
        $this->pulse->purge([$metric->name]);

        foreach ($series as $one) {
            $this->pulse->set($metric->name, self::key($one['labels']), (string) $one['value']);
        }
    }

    /**
     * A series' labels as its Pulse key.
     *
     * @param  array<string, string>  $labels
     */
    public static function key(array $labels): string
    {
        return json_encode((object) $labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * A Pulse key back to its labels.
     *
     * @return array<string, string>
     */
    public static function labels(string $key): array
    {
        /** @var array<string, string> $labels */
        $labels = json_decode($key, true, 512, JSON_THROW_ON_ERROR);

        return $labels;
    }
}
