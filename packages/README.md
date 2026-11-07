# Optional packages

Each directory is an optional Composer package, developed here so that a change to the core and to the package that depends on it lands in one commit, and split to a read-only repository to publish. The core package never requires one ([decision 0011](../docs/decisions/0011-one-optional-package.md)).

| Package | What it does |
|---|---|
| [`laraflow/opentelemetry`](opentelemetry/README.md) | OpenTelemetry traces for every run, step attempt, and connector call, as [docs/tracing.md](../docs/tracing.md) defines |

Metrics need no package: the lifecycle events and the metrics snapshot are what an application builds its own from ([docs/metrics.md](../docs/metrics.md)).

## Working on one

```bash
cd packages/opentelemetry
composer install     # resolves jhaskell13/laraflow from ../.. by path
composer test
composer analyse
```

CI runs it on the oldest and newest supported PHP and Laravel pairings, against the core in the same commit.
