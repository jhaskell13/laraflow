# laraflow/prometheus

Prometheus metrics for [Laraflow](../../README.md) workflows: the series [docs/metrics.md](../../docs/metrics.md) defines, and a scrape endpoint your application mounts.

> Developed in the Laraflow repository and released with Laraflow 0.7.0 ([decision 0007](../../docs/decisions/0007-bridges-live-in-this-repository.md)).

## What you get

Every series in [docs/metrics.md](../../docs/metrics.md), under those names and labels and no others — executions started and finished, durations, queue lag, step attempts, retries, deferrals, compensation, schedule ticks, trigger requests, connector calls — and the gauges for what is true right now: executions by workflow and status, the oldest unfinished execution's age, retries and waits due, overdue steps, deferrals, stalled schedules.

The names are not this package's to choose. They are mapped from Laraflow's events once, in the core package, for every bridge, and this package's tests hold the text a scraper receives to the document with Laraflow's conformance kit.

**How it is kept.** Counters and histograms go to shared storage — Redis by default — because the events that feed them fire on your queue workers while the scrape is answered by a web process. Gauges are never stored: each scrape takes a fresh snapshot from the database, so a workflow that drained stops being reported rather than lingering at its last count.

## Install

```bash
composer require laraflow/prometheus
php artisan vendor:publish --tag=laraflow-prometheus-config
```

Enable it and mount the endpoint:

```dotenv
LARAFLOW_PROMETHEUS_ENABLED=true
LARAFLOW_PROMETHEUS_TOKEN=a-long-random-string
```

```php
// routes/web.php, or a service provider's boot()
Laraflow\Prometheus\Prometheus::routes();
```

and point Prometheus at it:

```yaml
scrape_configs:
  - job_name: laraflow
    metrics_path: /laraflow/metrics
    bearer_token: a-long-random-string
    static_configs:
      - targets: ['app.example.com']
```

## Who may scrape

Decided in this order:

1. A callback given to `Prometheus::auth()` decides alone:
   ```php
   Prometheus::auth(fn (Request $request) => $request->ip() === '10.0.0.5');
   ```
2. With `token` set, the request must send it as `Authorization: Bearer …`, compared in constant time.
3. Otherwise only the `local` environment is let in. Every other environment is denied, so a forgotten rule fails closed.

The endpoint is absent until you mount it, and a mounted endpoint on a disabled bridge answers 404 rather than an empty page a scraper would call healthy.

## Configuration

`config/laraflow-prometheus.php`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Nothing is registered until this is true. |
| `tenant_label` | `false` | Add the `tenant` label to execution, step, compensation, and connector series. Read [the cardinality note](../../docs/metrics.md#labels) first. |
| `storage` | `redis` | `redis` (shared by every process; needs `ext-redis`), `apcu` (one host only; needs `ext-apcu`), or `memory` (this process only — tests). |
| `prefix` | `laraflow_prometheus` | The Redis key prefix or APCu prefix. |
| `redis.connection` | `default` | A connection from `config/database.php`'s `redis` section. |
| `redis.timeout` | `0.1` | Seconds to connect. Short on purpose: recording runs inside your workers. |
| `route.path` | `laraflow/metrics` | Where `Prometheus::routes()` mounts the endpoint. |
| `route.middleware` | `[]` | Middleware before the access check. None by default: a scraper has no session. |
| `token` | unset | The bearer token a scraper must send. |
| `buckets` | `[]` | Histogram buckets in seconds by family name, replacing [the defaults](../../docs/metrics.md#how-a-bridge-is-built). |

**After changing `buckets`,** run `php artisan laraflow:prometheus-wipe`: Prometheus cannot read a histogram whose bucket boundaries changed under it. Counters restart from zero, which `rate()` reads as an ordinary reset.

## What it costs

Each recorded event is one round trip to Redis from the process that fired it, made after Laraflow's durable write and outside any workflow decision; a failure is logged by Laraflow's event emitter and never affects the execution. Each scrape reads Redis once and takes the snapshot — a fixed number of indexed `COUNT` queries, whatever the size of your tables.

## Omitted series

None. Every family docs/metrics.md defines is exposed.
