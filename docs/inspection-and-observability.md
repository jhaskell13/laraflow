# Inspection and observability

This document is the contract for how an application inspects executions, correlates them with the rest of its telemetry, and measures them — without reading Laraflow's tables or its internal models. It builds on [docs/execution-lifecycle.md](execution-lifecycle.md) (what is persisted) and [docs/queue-and-recovery.md](queue-and-recovery.md) (events and the queue).

Everything stated here is exercised by `tests/Feature/Workflow/ExecutionQueryTest.php`, `CorrelationTest.php`, `tests/Feature/Events/EventDurationsTest.php`, `tests/Feature/Queue/QueueLagTest.php`, `tests/Feature/Observability/LoggingContextTest.php`, `LifecycleLoggingTest.php`, and the console suites. If the code and this document disagree, one of them has a bug.

## The principle

**Anything the console can show you, your application can show you.** Every Laraflow command runs on the same public `ExecutionRepository` methods described below; none touches an Eloquent model, and a test reads each command's source to keep it that way. If you find yourself querying `laraflow_*` tables directly, the API is missing something — open an issue rather than depending on the schema, which is frozen as an implementation detail but carries no compatibility promise of its own.

## Reading executions

`ExecutionRepository` returns plain `WorkflowExecution`, `StepExecution`, and `StepAttempt` value objects.

| Method | Returns | Cost |
|---|---|---|
| `find($id)` | One execution, or `ExecutionNotFoundException` | One query |
| `steps($execution)` | Its steps in position order | One query |
| `attempts($step)` | One step's attempt history | One query |
| `attemptHistory($execution)` | Every step's attempt history, ordered by step position then attempt | One query |
| `stepProgress(array $ids)` | `[id => ['total' => n, 'completed' => n]]` for many executions | One query |
| `list(ExecutionFilter, $limit, $cursor)` | One page of executions | One query, plus one more to learn whether another page exists |
| `count(ExecutionFilter)` | How many match | One query |

The two batched reads exist so that showing a list never costs a query per row. `stepProgress()` omits executions that have no steps rather than returning zeros, so a caller can tell "no plan" from "nothing done yet".

### Filtering

`ExecutionFilter` offers exactly the criteria the schema can serve from an index, and nothing else on purpose: a filter Laraflow cannot bound is a filter it does not expose, because an unindexed filter on a high-volume table is how observability tooling takes an application down.

```php
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;

$filter = new ExecutionFilter(
    workflow: [SyncCustomerWorkflow::class, BillCustomerWorkflow::class],
    status: [ExecutionStatus::Failed, ExecutionStatus::Retrying],
    tenantId: 'acme',
    createdAfter: now()->subDay()->toImmutable(),   // inclusive
    createdBefore: now()->toImmutable(),            // exclusive
    oldestFirst: true,
);

$failures = $repository->count($filter);
```

| Criterion | Matches | Index |
|---|---|---|
| `workflow` (one or several) | Exact class name | `(workflow, status)` |
| `status` (one or several) | `ExecutionStatus` cases | the `(status, *)` composite prefixes |
| `correlationId` | Exact | `correlation_id` |
| `tenantId` | Exact | `tenant_id` |
| `createdAfter` / `createdBefore` | Half-open window `[after, before)` | `created_at` |
| `finishedAfter` / `finishedBefore` | Half-open window on `finished_at`; implicitly finished executions only | `(status, finished_at)` |
| `oldestFirst` | Sort direction; default newest first | — |

Windows are half-open — inclusive start, exclusive end — so consecutive windows neither overlap nor leave a gap, which is what both reporting and retention need. Blank identifiers mean "unfiltered", mirroring `ExecutionOptions`: a caller forwarding an optional input must not end up with a filter that matches nothing. Inverted windows and wrong types are rejected at construction.

`withStatus()` and `withOldestFirst()` return a copy with one criterion changed and every other kept, for callers that count one status at a time within the same scope.

### Pagination

`list()` paginates by **keyset**, not offset. The cursor is the id of the last execution on the page; the next page continues strictly past it in the filter's direction.

```php
$cursor = null;

do {
    $page = $repository->list($filter, limit: 100, cursor: $cursor);

    foreach ($page as $execution) {
        // ...
    }

    $cursor = $page->nextCursor;
} while ($page->hasMore());
```

Why keyset: offset pagination over a table that is being written to skips or repeats rows as earlier pages shift underneath it, and page 1000 costs a thousand pages of scanning. A keyset cursor is stable under concurrent inserts and costs the same on every page — both proven by test.

The page size is clamped to `ExecutionRepository::MAX_PAGE_SIZE` (500). The bound lives on the contract rather than the implementation so no implementation can forget it; the page reports the `limit` actually applied. Treat the cursor as opaque and pass back exactly what you were given — a malformed one is rejected rather than guessed at.

## Correlation

An execution can carry two application-supplied identifiers, recorded when it is created and never changed:

```php
use Laraflow\Workflow\ExecutionOptions;

$runner->run($workflow, $input, new ExecutionOptions(
    correlationId: $request->header('X-Request-Id'),
    tenantId: (string) $tenant->id,
));

$dispatcher->dispatch($workflow, $input, options: new ExecutionOptions(
    correlationId: $job->uuid(),
));
```

Laraflow never interprets them. It persists them to indexed columns, exposes them to steps as `$context->correlationId()` and `$context->tenantId()`, carries them on **every** lifecycle event, and publishes them into the log context (below). One identifier ties an application's request log, the execution record, the worker's log, and an external provider's records together.

### Correlating with a request, job, tenant, and provider

| You want to tie an execution to… | Do this |
|---|---|
| **The HTTP request that started it** | Pass the request id (your own, or `X-Request-Id`) as `correlationId`. Every log line a step writes then carries it under `laraflow.correlation_id`, and `laraflow:list --correlation=<id>` finds the execution. |
| **The queued job that started it** | Same, with the job's uuid. If the job itself runs inside Laravel's log context, the `laraflow.*` keys are added to whatever is already there and removed afterwards. |
| **A tenant** | Pass `tenantId`. Then `laraflow:list --tenant=`, `laraflow:summary --tenant=`, `laraflow:prune --tenant=`, and the `tenantId` filter all scope to it. Steps read `$context->tenantId()` from durable state, so a step re-run by a recovering worker sees the tenant the execution was *created for*, not whatever ambient tenant that worker happens to be in. |
| **An external provider's records** | Forward `$context->correlationId()` — or `$context->idempotencyKey()`, which is stable per step — as the provider's request id or metadata field. When the provider's dashboard shows a charge with your correlation id on it, you can find the execution that made it. |
| **Everything the execution itself did** | `laraflow.execution_id` is in the log context for the duration of every step, so grepping your logs for one execution id returns every line any step wrote. |

### Validation

Both identifiers are validated at construction, before anything is persisted:

- Trimmed; blank means absent, so passing a request header that may be missing is safe. An empty string in an indexed column would be a value that matches nothing and means nothing.
- Rejected over 255 characters, because silent truncation by the database leaves an identifier that correlates with nothing.
- Rejected when they contain control characters, because these values reach log records where a newline would let a crafted identifier forge a second line. Rejecting once at the boundary keeps every downstream sink honest without each having to re-sanitise.

Violations raise `InvalidExecutionOptionsException`.

## Logging context

While a step runs, Laravel's log context carries the execution's identity:

| Key | Value |
|---|---|
| `laraflow.execution_id` | The execution id |
| `laraflow.workflow` | Workflow class |
| `laraflow.step` | Step class now running |
| `laraflow.position` | Its position |
| `laraflow.attempt` | Its attempt number |
| `laraflow.correlation_id` | Only when supplied |
| `laraflow.tenant_id` | Only when supplied |

**No Laraflow API is involved.** A step that calls `Log::info('charging card')` gets all of them attached by Laravel's own context processor. Because Laravel dehydrates the context into every queue payload and rehydrates it on the worker, **a job dispatched from inside a step inherits them too** — one correlation id follows the work across processes.

Guarantees:

- **Namespaced.** The context belongs to the application; a package writing a bare `correlation_id` would silently clobber whatever the application put there. Even a `laraflow.*` key the application set itself is restored, not deleted, because Laraflow did not create it.
- **Absent means absent.** A null correlation id in every record is noise, so the key is omitted rather than written as null.
- **Restored in a `finally`.** Whether the execution completed, threw, scheduled a retry, or was cancelled, the context is left exactly as found. An in-process `run()` cannot leak its execution id into every later log record of the surrounding request.
- **Frames nest.** A step that synchronously runs another workflow pushes a frame; leaving it restores the outer execution's keys, so the rest of the outer run is still attributed correctly.
- **Set before the step is announced.** The keys are narrowed to a step *before* `WorkflowStepStarted` fires, so a listener's own logging during that event is attributed to the right step.

Controlled by `laraflow.logging.context` (default `true`). It is additive, namespaced, and restored on the way out, so it cannot disturb an application that ignores it.

### The lifecycle log

`laraflow.logging.lifecycle` (default `false`) writes one log record per lifecycle event — an execution trail for applications that do not want to write listeners. Terminal failures log at `error`, failed attempts and scheduled retries at `warning`, everything else at `info`, matching the guidance to alert on `WorkflowExecutionFailed` rather than on every attempt. The record's context is the event's own public properties, which is safe by construction: events carry identifiers and metadata only, and any error message has already passed the configured redactor.

It is a wildcard listener on `Laraflow\Events\*`, so an event added in a later version is logged without code changes. Off by default because an application already listening to these events, or shipping them to a metrics backend, should not also pay for a log line per step.

## Measurements on events

Lifecycle events carry the numbers a metrics listener needs, so it never has to re-read the database or subtract timestamps itself.

| Event | Carries | Means |
|---|---|---|
| `WorkflowExecutionStarted` | `queueLagSeconds` | How long the execution waited for a worker (below). Null when it never waited. |
| `WorkflowStepCompleted`, `WorkflowStepFailed` | `durationMs` | This attempt's wall-clock time. The **same** `StepAttempt` record that went to history feeds the event, so telemetry and history cannot disagree. |
| `WorkflowExecutionCompleted`, `Failed`, `Cancelled` | `durationMs` | First start → this finish. |

**Execution duration is end-to-end latency, not compute time.** `startedAt` is preserved across re-executions, so an execution that failed, sat for an hour, and was recovered reports the hour. That is what an SLA measures and what "how long did the customer wait" means; per-attempt compute time is on the step events. It is null when the execution never started (a `pending` execution cancelled outright); zero is a measured zero.

### Queue lag

Every dispatch records `dispatched_at` (handed to the queue) and `available_at` (asked to become runnable). They are the same instant for an ordinary push and differ for a delayed retry, where `available_at` is the due time.

**Lag is measured from `available_at`, never from `dispatched_at`.** A retry held back by its own two-minute backoff has not been waiting two minutes for a worker; only the time past its due time is wait. `laraflow:show` reports the same number as *Queue wait*: open-ended while pending, exact once a worker owns it, absent entirely for an in-process run.

`queueLagSeconds` is **null, not zero**, when the execution was never dispatched or was claimed ahead of availability (`laraflow:execute --now` on a scheduled retry). Running early is not a wait of zero; it is no wait at all. The full case table is in [queue-and-recovery.md](queue-and-recovery.md#queue-lag).

### Vendor-neutral by construction

Laraflow ships no metrics integration and assumes no vendor. Events are plain readonly objects; a listener turns them into whatever your stack wants:

```php
// Prometheus (promphp/prometheus_client_php)
Event::listen(function (WorkflowStepCompleted $event) use ($registry) {
    $registry->getOrRegisterHistogram('laraflow', 'step_duration_ms', 'Step duration', ['workflow', 'step'])
        ->observe($event->durationMs ?? 0, [class_basename($event->workflow), class_basename($event->step)]);
});

// StatsD / Datadog
Event::listen(function (WorkflowExecutionStarted $event) use ($statsd) {
    if ($event->queueLagSeconds !== null) {
        $statsd->histogram('laraflow.queue_lag', $event->queueLagSeconds, ['workflow' => class_basename($event->workflow)]);
    }
});

// Laravel Pulse (a custom recorder)
Event::listen(function (WorkflowExecutionFailed $event) {
    Pulse::record('laraflow_failure', class_basename($event->workflow), $event->durationMs)->count();
});
```

Every listener is observational: one that throws is logged and swallowed, and cannot change execution state. Events carry identifiers and metadata only — never workflow input, step output, or context — so no listener can leak them, and the configured `ErrorRedactor` has already rewritten any error message they carry.

## The console

| Command | Shows |
|---|---|
| `laraflow:list` | A filtered, paginated page with step progress, how many of the matching set it showed, and the command that continues it |
| `laraflow:show {id}` | One execution: steps, correlation and tenant, finish time, queue timing, retry schedule, cancellation, scrub marker, and with `--attempts` the full history |
| `laraflow:summary` | Counts by status for a scope, then what needs attention: retries due now, running steps past their deadline, the oldest unfinished execution. **Exits non-zero when anything needs attention**, so it doubles as a scheduled health check or deploy gate |

The summary deliberately has no per-workflow breakdown: that needs a `GROUP BY` the contract does not expose, and `--workflow=` scopes the same numbers through an indexed filter instead.

## Connector calls

Two events describe outbound provider calls. `ConnectorRequestSent` fires for every response — `successful` reflects the HTTP status alone; a `200` the connector's error mapper later rejects is announced as successful, because the event is emitted before mapping — carrying `connector`, `connection`, `method`, `path`, `status`, `durationMs`, and `attempts`. `ConnectorRequestFailed` fires when there was no response at all: a connection failure, or Laraflow's own rate limit refusing to send.

Both carry identifiers and measurements only. The target is the **path**, never the query string, which may carry a token; there are no headers, options, or bodies on either; and the failure message passes through the configured `ErrorRedactor` and honours `events.include_error_messages`, exactly as workflow failure events do.

Neither carries a correlation id, deliberately: a connector can be called from anywhere, including outside a workflow. When one is called from inside a step, Laravel's log context already holds the identifiers, so a listener reads them from there:

```php
Event::listen(function (ConnectorRequestSent $event): void {
    Metrics::timing('provider.request', $event->durationMs, [
        'connector' => $event->connector,
        'status' => (string) $event->status,
        'correlation' => Context::get('laraflow.correlation_id'),
    ]);
});
```

While a connector request is in flight, `laraflow.connector` and `laraflow.connection` join the log context, so an application's own log records written from a transport decorator or an HTTP middleware say where the call was going. They are restored when the request ends, including when it throws.

One caution: paging a large result set fires one `ConnectorRequestSent` per page. A listener that writes a log line per event will write one per page.

## Payload size

`laraflow.execution.max_payload_bytes` (default `null`) bounds a single durable payload — input, one step's output, or the context snapshot — measured as the JSON that reaches the column. Setting it does not turn a success into a failure; an oversized payload already fails, as a driver error from inside the checkpoint transaction. The limit moves that failure earlier and names it: `PayloadTooLargeException` with the path, the actual size, and the limit, never retried. Specified in [execution-lifecycle.md](execution-lifecycle.md#payload-size).

## Operational checklist

- Pass a `correlationId` from wherever work originates; it is the one identifier that reaches every sink.
- Pass a `tenantId` in multi-tenant applications — listing, summary, and retention all scope by it.
- Alert on `WorkflowExecutionFailed`, not `WorkflowStepFailed`; the latter fires per attempt.
- Build metrics from events, not from the tables: the tables are internal and will change.
- Schedule `laraflow:summary` if you want a health check that exits non-zero when retries are due or steps are overdue.
- Page through `list()` with the cursor, never with an offset you compute yourself.
