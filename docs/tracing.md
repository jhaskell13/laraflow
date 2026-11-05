# Tracing

This document is the contract for Laraflow's traces: which spans exist, how they nest, how one execution's spans are joined across processes and days, which attributes they carry, and what they must never carry. It was written before the tracing bridge (Milestone 11, step 6), and that bridge's tests are held to it.

Metrics ([metrics.md](metrics.md)) say how much and how often. A trace says what happened to one execution: when it waited for a worker, which step ran, which attempt failed, which connector call was slow — in one timeline, even though the work crossed web requests, queue workers, retries, and waits that lasted days.

## Two parts

**The core package** records where a run, a step, and a connector call begin and end, and remembers the trace an execution was started in. It depends on no tracing library: the hooks call a `Laraflow\Observability\Tracing\Tracer`, and the binding that ships is a no-op.

**`laraflow/opentelemetry`**, an optional bridge in `packages/` like the metrics bridges ([decision 0007](decisions/0007-bridges-live-in-this-repository.md)), implements that `Tracer` with the OpenTelemetry API. It depends on `open-telemetry/api` only. The application chooses the SDK, the sampler, and the exporter — Jaeger, Tempo, Honeycomb, Datadog, any OTLP endpoint — as it would for any other instrumentation; without an SDK configured, the API is a no-op and the bridge costs nothing.

## The spans

| Span | Kind | Name | Begins | Ends |
|---|---|---|---|---|
| Run | `INTERNAL` | `laraflow.run {Workflow}` | A process claims the execution and starts running it | The process releases it — completed, failed, cancelled, parked on a wait, scheduled for a retry, or lost its claim |
| Step attempt | `INTERNAL` | `laraflow.step {Step}` | One attempt of one step begins | That attempt completes, fails, parks on a wait, or the run ends around it |
| Connector call | `CLIENT` | `{METHOD} {connector}` | A connector sends a request | The response, or the failure, is known — transport retries and throttle waits included |

`{Workflow}` and `{Step}` are fully qualified class names; `{connector}` is the registered connector name. Names are bounded by code, never by traffic: no execution id, path, or tenant ever appears in a span name.

A step attempt is a child of its run. A connector call is a child of whatever span is active when it is made — the step attempt, inside a step; the application's own span, outside any workflow. A sub-workflow or fan-out member started inside a step remembers that step's span as its origin (below), so its runs appear under the step that started it.

**One run, not one execution.** An execution that fails, retries an hour later, parks on an approval for three days, and completes is four runs, each its own span. That is what happened: four processes did work at four times. The execution is what joins them.

## Joining runs into one trace

When an execution is created, the trace context active at that moment — the web request that started it, the step that fanned it out — is stored with it, as a [W3C `traceparent`](https://www.w3.org/TR/trace-context/), in `laraflow_workflow_executions.trace_context`. That is how a trace crosses the queue: not in the job payload, which a retry or a recovery scan re-creates, but on the execution, which every delivery reads.

A run span is parented by these rules, in order:

1. **An active span in the running process** is the parent — the web request running an execution inline, an auto-instrumented queue job, the step of a parent workflow running a sub-workflow on the sync driver. The process's own trace stays whole.
2. **Otherwise the stored origin** is the parent, if the execution was created less than `link_after` seconds ago (default one hour): the first delivery of an ordinary queued execution lands in the trace of the request that started it.
3. **Otherwise the run starts a new trace**, with a span link to the origin. An execution resumed days later does not stretch one trace across those days — which most tracing backends display poorly or split — but every one of its later runs can still be followed back to where it began.

Whenever the origin is not the parent, the run carries a link to it. An execution with no stored origin — started by the scheduler, or before tracing was enabled — begins a new trace on each run.

An application can supply the origin itself: `ExecutionOptions::$traceContext` takes a `traceparent`, for an execution started on behalf of a request that arrived with one (a webhook, a message from another service). An invalid value is refused when the options are built, never stored.

## Attributes

| Attribute | On | Value |
|---|---|---|
| `laraflow.execution.id` | run, step | The execution id |
| `laraflow.workflow` | run, step | Workflow class |
| `laraflow.correlation_id` | run, step | When the execution has one |
| `laraflow.tenant_id` | run, step, connector | When the execution has one — attributes are per span, not per series, so the cardinality concern of metrics does not apply |
| `laraflow.plan_version` | run | The execution's plan version |
| `laraflow.run.outcome` | run | `completed`, `failed`, `cancelled`, `waiting`, `retrying`, or `released` (the run ended any other way — a lost claim, an exception). A claim a tenant ceiling refuses never begins a run, and has no span. |
| `laraflow.step` | step | Step class |
| `laraflow.step.position` | step | Zero-based position in the plan |
| `laraflow.step.attempt` | step | Attempt number, from 1 |
| `laraflow.step.outcome` | step | `completed`, `failed`, `skipped`, `waiting`, or `interrupted` (the run ended around it) |
| `laraflow.connector`, `laraflow.connection` | connector | Registered names |
| `http.request.method` | connector | Uppercased |
| `url.path` | connector | The path **without the query string**, which may carry a credential |
| `http.response.status_code` | connector | When a response arrived |
| `laraflow.connector.attempts` | connector | Wire attempts, more than one when a transport retry policy repeated the call |
| `error.type` | any | On a failed span: the exception class; for a connector call answered with an error status, the status code, as OpenTelemetry's HTTP conventions specify |

**Status.** A failed step attempt, a failed run, and a connector call that got no response or was refused by the connector's error mapper end with status `ERROR` and `error.type`. The status description is the error message only when `laraflow.events.include_error_messages` is true, and then only as the configured `ErrorRedactor` rewrote it — the same text the lifecycle events carry. A step attempt that failed and will be retried is still `ERROR`: that attempt failed.

**Never on a span:** inputs, outputs, context values, signal payloads, request or response headers and bodies, query strings, credentials, or unredacted exception messages. The attributes above are the complete list; the bridge adds no others under `laraflow.`.

## The hook

`Laraflow\Observability\Tracing\Tracer` (Experimental, from 0.7.0) is what the core calls:

- `capture(): ?string` — the active trace context as a `traceparent`, stored on an execution created now.
- `runStarted(WorkflowExecution)` and `runEnded()` — around a run, from the claim to the release, nested for a sub-workflow run inline.
- `stepStarted(WorkflowExecution, StepExecution)` — when an attempt begins. Its end, and the outcome of the run, come from the lifecycle events the runner already fires; `runEnded()` closes anything still open.
- `connectorCallStarted(Connection, ConnectorRequest)` and `connectorCallEnded()` — around a connector call. The connector events fire between the two.

Like the event emitter, a tracer must never affect an execution: the core calls it outside every durable decision, and a tracer that throws is logged and ignored. The default binding, `NullTracer`, does nothing.

## Compatibility

Span names, kinds, the parenting rules, and the attributes above are part of the compatibility promise from 0.7.0 ([compatibility.md](compatibility.md)): a saved trace query must keep working.

- **Adding an attribute** or a value of `laraflow.run.outcome` or `laraflow.step.outcome` is not breaking, and is announced in the changelog.
- **Renaming or removing an attribute, a span, or changing a span's name, kind, or parent** is breaking.
- The `Tracer` hook itself is experimental until a second implementation exists.
