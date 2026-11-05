# laraflow/statsd

StatsD and DogStatsD metrics for [Laraflow](../../README.md) workflows: the series [docs/metrics.md](../../docs/metrics.md) defines, pushed over UDP as they happen.

> Developed in the Laraflow repository and released with Laraflow 0.7.0 ([decision 0007](../../docs/decisions/0007-bridges-live-in-this-repository.md)).

## What you get

Every series in docs/metrics.md, under those names, with their labels sent as tags. The names are not this package's to choose: they are mapped from Laraflow's events once, in the core package, and this package's tests hold the datagrams themselves to the document with Laraflow's conformance kit.

Counters and histograms are sent as events fire, from whichever process fired them. Gauges — work by status, due retries, the oldest unfinished execution's age, stalled schedules — come from a database snapshot that `laraflow:statsd-gauges` pushes on a schedule, because StatsD has nothing that asks for them.

## Install

```bash
composer require laraflow/statsd
php artisan vendor:publish --tag=laraflow-statsd-config
```

```dotenv
LARAFLOW_STATSD_ENABLED=true
LARAFLOW_STATSD_HOST=127.0.0.1
LARAFLOW_STATSD_PORT=8125
```

```php
// routes/console.php
Schedule::command('laraflow:statsd-gauges')->everyMinute();
```

## Dialects

Labels travel as tags, in one of two forms:

| `dialect` | Datagram | Read by |
|---|---|---|
| `dogstatsd` (default) | `laraflow_executions_finished_total:1\|c\|#workflow:App\Workflows\Sync,outcome:completed` | The Datadog agent, Telegraf, StatsD Exporter, most modern collectors |
| `graphite` | `laraflow_executions_finished_total;workflow=App\Workflows\Sync;outcome=completed:1\|c` | etsy statsd 0.9+ (Graphite 1.1 tags) |

Plain, untagged StatsD is not offered: it could only fold labels into the series name, and the names are fixed by docs/metrics.md.

Counters are `|c`. Histograms are `|h` with the value **in seconds**, as the name ends `_seconds`; collectors treat `|h` as a timer whose unit is the sender's. Gauges are `|g`. An empty label value — an execution with no tenant, a connector call with no response — is left off rather than sent empty, and characters that would end a tag (`,` and `|`; `;` and `~` for Graphite) are replaced with `_`.

## Gauges that stop

StatsD cannot delete a gauge. When a workflow drains, its `laraflow_executions` series is simply no longer sent. DogStatsD and the Datadog agent stop reporting a gauge they are no longer given; for **etsy statsd, set `deleteGauges: true`**, or the last value is repeated forever. Nothing is sent as zero to stand in for "absent" — an oldest-unfinished age of zero would say an execution was created this instant.

## Configuration

`config/laraflow-statsd.php`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Nothing is registered until this is true. |
| `tenant_label` | `false` | Add the `tenant` tag to execution, step, compensation, and connector series. Read [the cardinality note](../../docs/metrics.md#labels) first — tags are billed per combination by most vendors. |
| `host`, `port` | `127.0.0.1`, `8125` | The collector. |
| `dialect` | `dogstatsd` | `dogstatsd` or `graphite`. |

## What it costs

One UDP datagram per observation, sent without waiting for anything; the socket is opened on first use, never at boot. A lost datagram is a lost observation and nothing more — an unreachable collector never slows or fails a workflow.

## Omitted series

None. Every family docs/metrics.md defines is sent.
