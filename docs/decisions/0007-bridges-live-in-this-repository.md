# 0007 — Metrics bridges live in this repository and are split out to publish

**Status:** Superseded by [0011](0011-one-optional-package.md) — the metrics bridges were removed before release; the one remaining optional package, `laraflow/opentelemetry`, still lives in `packages/` as described here.

## Context

The roadmap calls for metrics bridges — Prometheus, StatsD, Laravel Pulse — as optional packages, so that no application pays for a metrics library it does not use. Before 1.0, the events and the snapshot the bridges read can still change.

## Decision

Each bridge is its own Composer package with its own `composer.json`, developed under `packages/` in this repository and tested in CI against the core checked out beside it. When a bridge is ready to publish, its directory is split to a read-only repository that Packagist reads; issues and pull requests stay here. The core package never requires a bridge ([metrics.md](../metrics.md)).

## Rejected

- **A repository per bridge from day one.** A change to an event and to the bridges that read it would need coordinated releases across repositories while the contract is still moving.
- **Bridges inside the core package.** Every application would carry Prometheus, StatsD, and Pulse code and their optional dependencies.

## Consequences

CI gains a job per bridge. Releasing needs a split step, and creating the split repositories and their Packagist entries is a maintainer action. Bridge versions follow the core's minor until 1.0.
