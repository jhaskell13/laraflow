<?php

declare(strict_types=1);

namespace Laraflow\Observability\Metrics;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * Where a metrics bridge puts what Laraflow measures — the one thing a
 * bridge implements. Which events become which series, under which
 * names and labels, is decided once, by EventMetrics and SnapshotMetrics
 * in the core package; a sink only renders it for one backend. That is
 * what lets docs/metrics.md promise the same names from every bridge.
 *
 * Every call is made from inside the event emitter, which logs a
 * throwing listener instead of failing the workflow (docs/metrics.md,
 * "What every bridge must do"). A sink must still not block: local
 * shared memory, a UDP datagram, or one round trip to a store — never
 * an HTTP call per event.
 *
 * Labels arrive as an array keyed by exactly the definition's label
 * names, in its order. An absent value — no tenant, no status class —
 * arrives as the empty string; a backend that can drop a label (a
 * DogStatsD tag) may, and one that cannot (a Prometheus series) keeps it
 * empty, never a placeholder that could collide with a real value.
 */
interface MetricsSink
{
    /**
     * Add $by to a counter series.
     *
     * @param  array<string, string>  $labels
     */
    public function increment(MetricDefinition $metric, array $labels, float $by = 1.0): void;

    /**
     * Record one observation, in seconds, in a histogram series.
     *
     * @param  array<string, string>  $labels
     */
    public function observe(MetricDefinition $metric, array $labels, float $value): void;

    /**
     * Replace every series of a gauge family with exactly these. A
     * series the previous call published and this one does not — a
     * workflow with nothing left unfinished — is gone, not frozen at
     * its last value.
     *
     * @param  list<array{labels: array<string, string>, value: float}>  $series
     */
    public function replaceGauge(MetricDefinition $metric, array $series): void;
}
