<?php

declare(strict_types=1);

namespace Laraflow\Testing;

use Laraflow\Observability\Metrics\MetricDefinition;
use Laraflow\Observability\Metrics\MetricsSink;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * A sink that keeps what it was given, for tests: every family it saw
 * by name, every counter's running total and histogram's observations
 * by label set, and the current series of every gauge. What
 * MetricsConformance replays into to check the mapping itself, and
 * what an application's own tests can use to assert a workflow was
 * measured.
 */
final class RecordingMetricsSink implements MetricsSink
{
    /** @var array<string, MetricDefinition> */
    public array $families = [];

    /** @var array<string, array<string, float>> name => encoded labels => total */
    public array $counters = [];

    /** @var array<string, array<string, list<float>>> name => encoded labels => observations */
    public array $observations = [];

    /** @var array<string, list<array{labels: array<string, string>, value: float}>> */
    public array $gauges = [];

    public function increment(MetricDefinition $metric, array $labels, float $by = 1.0): void
    {
        $this->families[$metric->name] = $metric;
        $key = self::key($labels);

        $this->counters[$metric->name][$key] = ($this->counters[$metric->name][$key] ?? 0.0) + $by;
    }

    public function observe(MetricDefinition $metric, array $labels, float $value): void
    {
        $this->families[$metric->name] = $metric;
        $this->observations[$metric->name][self::key($labels)][] = $value;
    }

    public function replaceGauge(MetricDefinition $metric, array $series): void
    {
        $this->families[$metric->name] = $metric;
        $this->gauges[$metric->name] = $series;
    }

    /**
     * A counter's total for one label set; zero when never incremented.
     *
     * @param  array<string, string>  $labels
     */
    public function counter(string $name, array $labels): float
    {
        return $this->counters[$name][self::key($labels)] ?? 0.0;
    }

    /**
     * Every observation recorded for one histogram label set.
     *
     * @param  array<string, string>  $labels
     * @return list<float>
     */
    public function observed(string $name, array $labels): array
    {
        return $this->observations[$name][self::key($labels)] ?? [];
    }

    /**
     * Every label set a family was recorded under, in the order first seen.
     *
     * @return list<array<string, string>>
     */
    public function labelSets(string $name): array
    {
        $keys = array_keys($this->counters[$name] ?? $this->observations[$name] ?? []);

        if ($keys === [] && isset($this->gauges[$name])) {
            return array_map(fn (array $series): array => $series['labels'], $this->gauges[$name]);
        }

        return array_map(
            function (string $key): array {
                /** @var array<string, string> $labels */
                $labels = json_decode($key, true, 512, JSON_THROW_ON_ERROR);

                return $labels;
            },
            array_map('strval', $keys),
        );
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function key(array $labels): string
    {
        return json_encode($labels, JSON_THROW_ON_ERROR);
    }
}
