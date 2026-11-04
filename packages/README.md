# Metrics bridges

Each directory is one optional Composer package that renders Laraflow's metrics for one backend. They live in this repository so that a change to an event and to the bridges that read it lands in one commit, and are split to read-only repositories to publish ([decision 0007](../docs/decisions/0007-bridges-live-in-this-repository.md)). The core package never requires one.

| Package | Backend | Status |
|---|---|---|
| [`laraflow/prometheus`](prometheus/README.md) | Prometheus | Under construction (Milestone 11, step 3) |
| [`laraflow/statsd`](statsd/README.md) | StatsD, DogStatsD | Under construction (Milestone 11, step 4) |
| [`laraflow/pulse`](pulse/README.md) | Laravel Pulse | Under construction (Milestone 11, step 5) |

## How a bridge is built

The series every bridge publishes are defined in [docs/metrics.md](../docs/metrics.md) and nowhere else. The core package maps events to series once (`EventMetrics`), maps the snapshot to gauges once (`SnapshotMetrics`), and lists every series as data (`MetricCatalog`). A bridge supplies the one thing that differs between backends — a `MetricsSink` — and extends `MetricsBridgeServiceProvider`, which keeps the rules every bridge shares: its own configuration file, off until enabled, the tenant label opt-in.

Its tests replay every event and a snapshot through the real sink with `Laraflow\Testing\MetricsConformance`, read back what the backend exposes, and assert it is exactly what the document defines.

## Working on one

```bash
cd packages/prometheus
composer install     # resolves laraflow/laraflow from ../.. by path
composer test
composer analyse
```

CI runs each bridge on the oldest and newest supported PHP and Laravel pairings, against the core in the same commit.
