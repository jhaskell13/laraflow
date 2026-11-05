# laraflow/pulse

[Laravel Pulse](https://pulse.laravel.com) recorders and a dashboard card for [Laraflow](../../README.md) workflows, over the series [docs/metrics.md](../../docs/metrics.md) defines.

> Developed in the Laraflow repository and released with Laraflow 0.7.0 ([decision 0007](../../docs/decisions/0007-bridges-live-in-this-repository.md)).

## What you get

- **Every series in docs/metrics.md, recorded into Pulse.** Each series family is a Pulse type under its documented name; each series' labels are its Pulse key, as JSON. The names are mapped from Laraflow's events once, in the core package, and this package's tests read them back from Pulse's own tables after a real ingest and hold them to the document with Laraflow's conformance kit.
- **The gauges**, taken from a database snapshot on `pulse:check`'s isolated heartbeat — once across your servers, every `gauge_interval` seconds — and replaced whole, so a drained workflow disappears rather than lingering.
- **A card** for the Pulse dashboard: executions finished by outcome per workflow for the selected period, the slowest steps, and what is unfinished, due, deferred, overdue, or stalled as of the last heartbeat.

Recording only buffers in memory; Pulse ingests at the end of each request and job, as it does for its own recorders.

## Install

Pulse must already be installed and `pulse:check` running.

```bash
composer require laraflow/pulse
php artisan vendor:publish --tag=laraflow-pulse-config
```

```dotenv
LARAFLOW_PULSE_ENABLED=true
```

Add the card to `resources/views/vendor/pulse/dashboard.blade.php`:

```blade
<livewire:laraflow.workflows cols="6" />
```

## How the series sit in Pulse

| docs/metrics.md type | In Pulse |
|---|---|
| counter | Entries aggregated by `sum` |
| histogram | Entries aggregated by `avg`, `max`, and `count`; the value in **whole milliseconds** |
| gauge | Values, purged and set whole on each heartbeat |

**Durations are milliseconds here.** Pulse stores integers, and seconds would round every step to zero; this is the one bridge whose backend forces a unit other than the one the series name carries. The card labels them `ms`.

## Configuration

`config/laraflow-pulse.php`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Nothing is registered until this is true. |
| `tenant_label` | `false` | Add `tenant` to the key of execution, step, compensation, and connector series. Every tenant multiplies the rows Pulse aggregates; read [the cardinality note](../../docs/metrics.md#labels) first. |
| `gauge_interval` | `60` | Seconds between snapshots taken on the heartbeat. |

## Omitted series

None. Every family docs/metrics.md defines is recorded.
