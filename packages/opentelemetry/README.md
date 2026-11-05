# laraflow/opentelemetry

OpenTelemetry traces for [Laraflow](../../README.md) workflows: a span for every run, step attempt, and connector call, joined across web requests, queue workers, retries, and waits — as [docs/tracing.md](../../docs/tracing.md) defines.

> Developed in the Laraflow repository and released with Laraflow 0.7.0 ([decision 0007](../../docs/decisions/0007-bridges-live-in-this-repository.md)).

## What you get

```
POST /orders                                   ← your request's span
└─ laraflow.run App\Workflows\FulfilOrder        outcome: retrying
   ├─ laraflow.step …\ReserveInventory           completed
   └─ laraflow.step …\ChargePayment  attempt 1   failed  error.type: …\TimeoutException
      └─ POST stripe                             error.type: 504
laraflow.run App\Workflows\FulfilOrder           outcome: completed   (a worker, 30 s later)
└─ laraflow.step …\ChargePayment  attempt 2      completed
   └─ POST stripe                                http.response.status_code: 200
```

Spans, names, attributes, and the rules that join one execution's runs into a trace are the contract in [docs/tracing.md](../../docs/tracing.md). In short: every execution remembers the trace it was started in; a run joins that trace while it is recent (`link_after`, an hour by default) and otherwise starts a new trace linked to it, so an approval answered three days later is still one click from the request that asked for it.

**Never on a span:** inputs, outputs, context values, signal payloads, headers, bodies, query strings, credentials, or unredacted error messages.

## Install

This bridge depends on the OpenTelemetry **API** only. Recording and exporting are your application's SDK's job, configured as for any OpenTelemetry PHP instrumentation:

```bash
composer require laraflow/opentelemetry open-telemetry/sdk open-telemetry/exporter-otlp
php artisan vendor:publish --tag=laraflow-opentelemetry-config
```

```dotenv
LARAFLOW_OPENTELEMETRY_ENABLED=true

# The SDK, configured from the environment:
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=my-app
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
```

A `TracerProviderInterface` bound in the container is used if there is one; otherwise OpenTelemetry's global provider. With no SDK configured, the API is a no-op and the bridge costs nothing.

## Alongside other instrumentation

Every Laraflow span is made the active span while it runs. A database query, an HTTP call, or a span of your own started inside a step is that step's child. If an auto-instrumented queue job span is active when a worker runs an execution, the run is that job's child and links to the execution's origin.

## Configuration

`config/laraflow-opentelemetry.php`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | The core keeps its no-op tracer until this is true. |
| `link_after` | `3600` | Seconds after an execution's creation during which its runs join the trace it was started in; later runs start a new trace linked to it. |

## Naming the trace yourself

An execution started on behalf of a request that arrived with a `traceparent` — a webhook, another service's call — can join that trace:

```php
$runner->start($workflow, $input, new ExecutionOptions(
    traceContext: $request->header('traceparent'),
));
```

An invalid value is refused when the options are built.
