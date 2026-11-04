# laraflow/pulse

> **Under construction** — Milestone 11, step 5. Not yet published; installing it from this repository changes nothing, and enabling it is refused until the step lands.

Pulse recorders over the same events, and cards for the Pulse dashboard: throughput, failures, slowest steps, tenant deferrals.

The series it publishes — names, types, labels, and units — are not this package's to choose: they are defined once in [docs/metrics.md](../../docs/metrics.md), mapped from Laraflow's events once in the core package, and held to that document by the conformance kit (`Laraflow\Testing\MetricsConformance`) in this package's tests.

## Configuration

Published with `php artisan vendor:publish --tag=laraflow-pulse-config` to `config/laraflow-pulse.php`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` (`LARAFLOW_PULSE_ENABLED`) | Nothing is registered until this is true. |
| `tenant_label` | `false` (`LARAFLOW_PULSE_TENANT_LABEL`) | Add the `tenant` label to execution, step, compensation, and connector series. Read [the cardinality note](../../docs/metrics.md#labels) first. |

## Development

This package lives in the Laraflow repository and is tested against the core checked out beside it ([decision 0007](../../docs/decisions/0007-bridges-live-in-this-repository.md)):

```bash
cd packages/pulse
composer install
composer test
composer analyse
```
