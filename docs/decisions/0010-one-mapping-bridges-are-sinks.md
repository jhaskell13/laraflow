# 0010 — One mapping in the core; a bridge is a sink

**Status:** Accepted (Milestone 11, step 2).

## Context

[docs/metrics.md](../metrics.md) promises the same series names and labels from every metrics bridge, and makes them part of the compatibility promise. Three bridges — Prometheus, StatsD, Pulse — were planned, each listening to the events itself. Twenty-three events map to twenty-four counter and histogram families; written three times, that mapping would drift the first time an event gained a field or a bridge fixed a conversion the others did not.

## Decision

The mapping is written once, in the core package, under `Laraflow\Observability\Metrics`:

- `MetricCatalog` — every series as data, held to the document's tables by a contract test.
- `EventMetrics` — events to counters and histograms; every event is mapped or listed in `UNMAPPED` with a reason, enforced by test.
- `SnapshotMetrics` — the snapshot to gauges, each family replaced whole.
- `MetricsSink` — the interface a bridge implements: `increment`, `observe`, `replaceGauge`.
- `MetricsBridgeServiceProvider` — the base every bridge's provider extends, which keeps the shared rules: its own configuration, off until enabled, the tenant label opt-in.
- `Laraflow\Testing\MetricsConformance` — the kit a bridge's tests run against the real backend.

All are Experimental until the three bridges have settled them.

## Rejected

- **Each bridge maps the events itself, held together by the conformance kit alone.** The kit would catch a wrong name, but every fix and every new event would still be three changes, and the kit can only check names and labels, not that the same event produced the same value in each.
- **A metrics library in the core.** Would make every application carry it; the core still depends on none.

## Consequences

A bridge is small: a sink, a provider, its configuration, and tests. A third-party backend is built the same way and held to the same kit. The core package grows an experimental namespace it must keep stable enough for the bridges to build on, and the sink interface is the one thing about a bridge that is not decided in this repository's core — which is exactly the part that differs between backends.
