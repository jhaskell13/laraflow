# 0011 — One optional package; metrics are the application's

**Status:** Accepted (Milestone 11). Supersedes [0007](0007-bridges-live-in-this-repository.md) and [0010](0010-one-mapping-bridges-are-sinks.md).

## Context

Milestone 11 planned four optional packages — Prometheus, StatsD, Laravel Pulse, and OpenTelemetry — and three were built, over a metrics model in the core (a catalog of series, an event mapping, a sink interface, a base provider, a conformance kit). Reviewed before anything was released, most of it did work an application already does better with what it runs:

- The lifecycle events already carry every measurement, and a listener of a few lines turns them into a metric in any backend; the documentation already had the recipes.
- Prometheus storage and an authenticated scrape endpoint, a StatsD client, and a Pulse card are infrastructure an application or a dedicated package (`spatie/laravel-prometheus`, Datadog's own tracer, Pulse itself) owns. A Laravel package emits signals; it does not run the application's monitoring.
- Each package follows a third-party library's majors, its own CI legs, and eventually its own repository and Packagist entry — a standing cost for one maintainer, paid before any user had asked for one.

## Decision

- **Metrics are the application's to build.** The core keeps what only it can provide: the lifecycle events, and the metrics snapshot — exact counts of what is unfinished, due, overdue, deferred, and stalled, at a fixed cost ([metrics.md](../metrics.md)). The Prometheus, StatsD, and Pulse packages and the core metrics model are removed; nothing of them was released.
- **Tracing keeps one optional package.** An execution's runs span requests, workers, retries, and days; joining them into one trace needs the execution's own record, which no general-purpose instrumentation has. The core keeps the `Tracer` hook and the stored trace context; `laraflow/opentelemetry`, depending on the OpenTelemetry API only, is the one optional package, developed under `packages/` and split to publish ([tracing.md](../tracing.md)).
- **Nothing new is frozen early.** The snapshot and everything in tracing.md are Experimental until 1.0.

## Rejected

- **Keeping all four, unpublished until asked for.** Unreleased code is still code that CI runs and refactors must carry.
- **Consolidating every metric into the OpenTelemetry package.** Possible later, on demand; OpenTelemetry's own metrics API and collector already reach Prometheus, StatsD, and Datadog without Laraflow in between.

## Consequences

An application wires its own metrics from the events and the snapshot — a few lines, documented. The core carries no metrics vocabulary to keep stable. One optional package, one CI leg.
