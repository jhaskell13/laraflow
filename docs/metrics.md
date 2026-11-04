# Metrics

This document is the contract for Laraflow's metrics: what each series is called, what it counts, which labels it carries, and what every metrics bridge must do. It is written before the bridges (Milestone 11), as every contract since Milestone 7 has been, and each bridge's test suite is held to it.

The core package records no metrics and depends on no metrics library. It publishes two sources — the lifecycle events ([inspection-and-observability.md](inspection-and-observability.md)) and a point-in-time snapshot (below) — and the optional bridges turn them into series:

| Bridge | Package | Delivers |
|---|---|---|
| Prometheus | `laraflow/prometheus` | Counters and histograms kept in shared storage (Redis or APCu), and a mountable scrape endpoint that reads the snapshot on each scrape. |
| StatsD | `laraflow/statsd` | Counters and timings pushed over UDP as events fire (plain StatsD or DogStatsD tags), and a scheduled command that pushes the snapshot. |
| Laravel Pulse | `laraflow/pulse` | Pulse recorders over the same events, and dashboard cards. Pulse aggregates on its own terms; the series below are what its recorders record. |

All three live in this repository under `packages/` and are released from it ([decision 0007](decisions/0007-bridges-live-in-this-repository.md)). An application installs only the one it wants; nothing here changes what the core package requires.

## Two sources, and why both

**Events say what happened.** Every counter and histogram below is derived from exactly one lifecycle event, fired after the durable write it describes. A bridge that listens to events sees every transition the process it runs in performed — including on workers, where the events fire.

**The snapshot says what is.** How many executions are waiting right now, how old the oldest unfinished one is, how many retries are due: no stream of events can answer these reliably, because a process that missed an event (a worker killed between write and dispatch, a bridge installed yesterday) would be wrong forever. The snapshot is read from the database, so it is right whenever it is taken.

A bridge publishes both. Counters from events, gauges from the snapshot — never a gauge kept by incrementing and decrementing on events.

## The snapshot

`Laraflow\Observability\MetricsSnapshot` (Public, from 0.7.0) is a read-only value taken by `Laraflow\Observability\Snapshots::take(DateTimeImmutable $now, ?ExecutionFilter $scope = null)`:

| Field | Meaning |
|---|---|
| `executions` | Unfinished and failed executions counted by workflow class and status, for the statuses `pending`, `running`, `retrying`, `waiting`, `compensating`, and `failed`. Terminal successes (`completed`, `cancelled`) are not counted: their totals only grow, and the event counters already carry them. `failed` is counted because a failed execution is work an operator still owns. |
| `oldestUnfinishedAgeSeconds` | Seconds since the oldest unfinished execution was created, or null when there is none. |
| `retriesDue` | Executions in `retrying` whose backoff has elapsed. |
| `waitsDue` | Executions in `waiting` whose resume time or timeout has passed. |
| `overdueSteps` | Running steps past their deadline ([retry-and-failure-policy.md](retry-and-failure-policy.md)). |
| `deferred` | Executions a tenant ceiling is holding at `$now` ([tenancy.md](tenancy.md)). |
| `stalledSchedules` | Schedules with due ticks standing unclaimed ([schedules.md](schedules.md)). |
| `takenAt` | `$now`, as given. |

**Every field is an exact count.** The attention scan that `laraflow:summary` reads is bounded by `ExecutionReader::MAX_PAGE_SIZE` because it lists rows to show them; a gauge that silently saturates at 500 would lie exactly when it matters. The snapshot counts in the database instead, one bounded query per field — the number of queries is fixed, never proportional to the number of executions or workflows — and the store gains the count queries it needs to do so (`ExecutionStore` is experimental; [public-api.md](public-api.md)).

**Same answer as the operator surfaces.** A field the attention scan also reports means the same thing: `retriesDue` counts what `laraflow:summary` lists as due retries, `stalledSchedules` what it lists as stalled. They are computed from the same predicates, so a dashboard built on metrics and the console cannot disagree about what needs attention — only about how much of it they show.

`$scope` narrows every field to one tenant or workflow, as the console's filter options do; the bridges take the unscoped snapshot.

## Naming

- Every series is prefixed `laraflow_`, snake case. StatsD and Pulse use the same names; a bridge may let the application change the prefix, never the rest of the name.
- **Units are in the name and are base units**: `_seconds` for durations (float, converted from the events' milliseconds), `_total` for counters. Nothing is reported in milliseconds.
- **Counters count events, histograms measure them.** A histogram's observation count is the matching counter for that event; a bridge does not need to publish both for the same event.

## Labels

Labels are what make a series useful and what make a metrics bill explode. Every label below is **bounded by code, not by traffic**: its values come from classes the application wrote or enums Laraflow defines.

| Label | Values | Notes |
|---|---|---|
| `workflow` | Workflow class, fully qualified | Not the basename: two `Notify` classes in different namespaces are different workflows. |
| `step` | Step class, fully qualified | |
| `outcome` | Fixed per series (below) | |
| `reason`, `ceiling`, `kind` | The backing values of `RecoveryReason`, `TenantCeiling`, and the wait kind | |
| `queue` | The queue name the execution was dispatched to | Configuration, not input. |
| `connector`, `connection` | Registered connector names | |
| `method`, `status_class` | HTTP method; `2xx`…`5xx` | The status class, not the status: `418` is as bounded as `4xx`, but a bridge should not be the reason to find out. |
| `trigger` | Registered trigger names, or `signed` for the signed endpoint | |
| `error_class` | Exception class, fully qualified | Only on the failure series that name it. |
| `tenant` | Tenant id | **Off by default.** See below. |

**Never a label:** execution ids, correlation ids, step positions, error messages, request paths, signal payloads, or anything else that grows with traffic. A bridge that adds one is non-conforming, and the conformance kit fails it.

**The tenant label is opt-in.** A tenant id is bounded by the application's customer list, which for some applications is ten and for others is a hundred thousand. Each bridge's configuration has a `tenant_label` switch, off by default; when on, the `tenant` label is added to every execution, step, and connector series that the event can attribute to a tenant, and to no others. An execution without a tenant is reported with the label absent (Prometheus: empty), never with a placeholder that could collide with a real tenant id.

The connector events do not carry a tenant id today (0.6.0); 0.7.0 adds `?string $tenantId` to `ConnectorRequestSent` and `ConnectorRequestFailed`, read from the running execution as the tenant-scoped rate limit already is, so a connector call made inside an execution can be attributed and one made outside any execution has the label absent. Until then the connector series carry no tenant label.

## The series

### Executions

| Series | Type | Labels | From |
|---|---|---|---|
| `laraflow_executions_started_total` | counter | `workflow` | `WorkflowExecutionStarted` |
| `laraflow_executions_finished_total` | counter | `workflow`, `outcome` = `completed` \| `failed` \| `cancelled` | `WorkflowExecutionCompleted`, `…Failed`, `…Cancelled` |
| `laraflow_execution_duration_seconds` | histogram | `workflow`, `outcome` | The same three, when `durationMs` is known |
| `laraflow_execution_queue_lag_seconds` | histogram | `workflow` | `WorkflowExecutionStarted`, when `queueLagSeconds` is known |
| `laraflow_executions_dispatched_total` | counter | `workflow`, `queue` | `WorkflowExecutionDispatched` |
| `laraflow_execution_retries_scheduled_total` | counter | `workflow`, `reason` | `WorkflowExecutionRetryScheduled` |
| `laraflow_executions_deferred_total` | counter | `workflow`, `ceiling` | `WorkflowExecutionDeferred` |
| `laraflow_execution_failures_total` | counter | `workflow`, `error_class` | `WorkflowExecutionFailed` |
| `laraflow_signals_received_total` | counter | `workflow` | `WorkflowExecutionSignalled` |
| `laraflow_plan_migrations_total` | counter | `workflow` | `WorkflowPlanMigrated` |

An execution that fails, is re-run by an operator, and completes is counted as finished twice — once `failed`, once `completed` — because it finished twice. Counters count transitions, not executions.

### Steps

| Series | Type | Labels | From |
|---|---|---|---|
| `laraflow_steps_finished_total` | counter | `workflow`, `step`, `outcome` = `completed` \| `failed` \| `skipped` | `WorkflowStepCompleted`, `…Failed`, `…Skipped` |
| `laraflow_step_duration_seconds` | histogram | `workflow`, `step`, `outcome` = `completed` \| `failed` | `WorkflowStepCompleted`, `…Failed`, when `durationMs` is known |
| `laraflow_step_failures_total` | counter | `workflow`, `step`, `error_class` | `WorkflowStepFailed` |
| `laraflow_step_retries_scheduled_total` | counter | `workflow`, `step` | `WorkflowStepRetryScheduled` |
| `laraflow_step_waits_total` | counter | `workflow`, `step`, `kind` = `signal` \| `delay` | `WorkflowStepWaiting` (`signal` when the event names one) |
| `laraflow_fan_out_members_total` | counter | `workflow`, `step` | `WorkflowFanOutStarted`, incremented by `members` |

Every attempt is a step event, so a step that fails twice and then succeeds contributes two `failed` and one `completed`. Divide failures by finishes for an attempt failure rate; use the execution series for "how many runs failed".

### Compensation

| Series | Type | Labels | From |
|---|---|---|---|
| `laraflow_compensations_started_total` | counter | `workflow` | `WorkflowCompensationStarted` |
| `laraflow_compensations_finished_total` | counter | `workflow`, `outcome` = `complete` \| `incomplete` | `WorkflowCompensated` (`complete` when nothing remains to undo) |

### Schedules and triggers

| Series | Type | Labels | From |
|---|---|---|---|
| `laraflow_schedule_ticks_total` | counter | `workflow`, `outcome` = `started` \| `caught_up` \| `skipped_overlap` | `WorkflowScheduleTicked` (`caught_up` when the tick was a catch-up), `WorkflowScheduleSkipped` |
| `laraflow_schedule_missed_ticks_total` | counter | `workflow` | `WorkflowScheduleTicked`, incremented by `missed` |
| `laraflow_trigger_requests_total` | counter | `trigger`, `outcome` = `accepted` \| `refused`, `reason` | `TriggerAccepted`, `TriggerRefused` (`reason` is the refusal reason, empty when accepted) |

### Connectors

| Series | Type | Labels | From |
|---|---|---|---|
| `laraflow_connector_requests_total` | counter | `connector`, `connection`, `method`, `status_class`, `outcome` = `success` \| `error` \| `failed` | `ConnectorRequestSent` (`success` or `error` by its `successful` flag, `status_class` from `status`), `ConnectorRequestFailed` (`failed`, no response, `status_class` empty) |
| `laraflow_connector_request_duration_seconds` | histogram | `connector`, `connection`, `outcome` | The same two |
| `laraflow_connector_retries_total` | counter | `connector`, `connection` | The same two, incremented by `attempts - 1` |

### Gauges, from the snapshot

| Series | Type | Labels | Field |
|---|---|---|---|
| `laraflow_executions` | gauge | `workflow`, `status` | `executions` |
| `laraflow_oldest_unfinished_execution_age_seconds` | gauge | — | `oldestUnfinishedAgeSeconds` (absent when null) |
| `laraflow_retries_due` | gauge | — | `retriesDue` |
| `laraflow_waits_due` | gauge | — | `waitsDue` |
| `laraflow_steps_overdue` | gauge | — | `overdueSteps` |
| `laraflow_executions_deferred` | gauge | — | `deferred` |
| `laraflow_schedules_stalled` | gauge | — | `stalledSchedules` |

The gauges never take the tenant label: the snapshot is unscoped, and a per-tenant breakdown of every gauge is exactly the query cost the opt-in exists to avoid. An application that needs one tenant's gauges takes a scoped snapshot itself.

## What every bridge must do

1. **Read only the public contract.** Events and `Snapshots` — nothing `@internal`, no Eloquent model, no table. A test enforces it, as it does for the dashboard.
2. **Publish exactly these names and labels.** A bridge may omit a series its backend cannot represent and must say so in its README; it may not rename one, add a label, or add a series under the `laraflow_` prefix that this document does not define. The shared conformance kit replays every event and a snapshot through the bridge and compares what it published against the tables above.
3. **Never affect an execution.** Listeners run inside the event emitter, which already logs a throwing listener instead of failing the workflow ([inspection-and-observability.md](inspection-and-observability.md)); a bridge must additionally not block — UDP, local shared memory, or one round trip to a store, never an HTTP call per event.
4. **Ship off by default.** Installing a bridge registers nothing until its configuration says so; a scrape endpoint is mounted by the application, as the dashboard is, and denied by default outside `local`.
5. **Configure itself in its own file** (`config/laraflow-prometheus.php` and so on), never in the core `laraflow.php`.

## Compatibility

These names are part of the compatibility promise from the release that ships the first bridge ([compatibility.md](compatibility.md)): a dashboard or alert written against them must keep working.

- **Adding a series** is not breaking.
- **Renaming a series, removing it, or changing its type** is breaking.
- **Adding or removing a label** on an existing series is breaking — it changes the identity of every series and breaks every query that aggregates over it.
- **Adding a value to a bounded label** (a new `RecoveryReason`, a new `outcome`) is not breaking, and is announced in the changelog.
