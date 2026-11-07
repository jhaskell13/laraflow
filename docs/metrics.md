# Metrics

Laraflow records no metrics and depends on no metrics library. It gives an application two things to build its own from, with whatever it already runs — Prometheus, StatsD, Datadog, Laravel Pulse, OpenTelemetry metrics:

- **The lifecycle events** say what happened: every start, step attempt, retry, failure, cancellation, deferral, and connector call, with durations, queue lag, workflow, tenant, and correlation id ([inspection-and-observability.md](inspection-and-observability.md#measurements-on-events)). Counters and histograms come from these, through a listener — recipes are in [inspection-and-observability.md](inspection-and-observability.md#vendor-neutral-by-construction).
- **The snapshot** says what is: how much work is unfinished, due, overdue, deferred, or stalled right now. Gauges come from this.

Why both: a gauge kept by counting events up and down is wrong forever once one event is missed — a worker killed between its write and the dispatch, a listener added yesterday. The snapshot is read from the database, so it is right whenever it is taken.

## The snapshot

`Laraflow\Observability\Snapshots::take(DateTimeImmutable $now, ?ExecutionFilter $scope = null)` returns a `MetricsSnapshot`:

| Field | Meaning |
|---|---|
| `executions` | Unfinished and failed executions counted by workflow class and status, for `pending`, `running`, `retrying`, `waiting`, `compensating`, and `failed`. Completed and cancelled are not counted: their totals only grow, and an event counter carries them better. `failed` is counted because a failed execution is work an operator still owns. |
| `oldestUnfinishedAgeSeconds` | Seconds since the oldest unfinished execution was created, or null when there is none. |
| `retriesDue` | Executions in `retrying` whose backoff has elapsed. |
| `waitsDue` | Executions in `waiting` whose resume time or timeout has passed. |
| `overdueSteps` | Running or compensating executions with a running step past its deadline ([retry-and-failure-policy.md](retry-and-failure-policy.md)), counted once per execution. |
| `deferred` | Executions a tenant ceiling is holding at `$now` ([tenancy.md](tenancy.md)). |
| `stalledSchedules` | Schedules with due ticks standing unclaimed ([schedules.md](schedules.md)). |
| `takenAt` | `$now`, as given. |

**Every field is an exact count, at a fixed cost.** The attention scan behind `laraflow:summary` stops at `ExecutionReader::MAX_PAGE_SIZE` because it lists rows to show them; a gauge that saturated at 500 would lie exactly when it matters. The snapshot counts in the database instead — a grouped count, four attention counts, the oldest unfinished row, and the registered schedules — and the number of queries never grows with the number of executions or workflows.

**The summary's answer.** Each field shares its predicate, in code, with the list `laraflow:summary` and the dashboard's overview print, so a graph and the console can disagree about how much they show, never about what needs attention. An execution a tenant ceiling is holding is `deferred`, never also due.

**Scope.** `$scope` narrows every field as `laraflow:summary`'s options do — workflow, tenant, correlation, parent, creation window — except `stalledSchedules`, which only a schedule's workflow and tenant can scope. A scope may not name statuses or a finished window: each field names the statuses it counts, and a scope that also did could only make fields silently zero, so `take()` refuses it.

## Using it

Take a snapshot on a schedule and set your gauges from it:

```php
// routes/console.php
Schedule::call(function (Snapshots $snapshots) {
    $snapshot = $snapshots->take(now()->toDateTimeImmutable());

    foreach ($snapshot->workflows() as $workflow) {
        foreach (MetricsSnapshot::STATUSES as $status) {
            Metrics::gauge('laraflow_executions', $snapshot->count($status, $workflow), [
                'workflow' => $workflow,
                'status' => $status->value,
            ]);
        }
    }

    Metrics::gauge('laraflow_retries_due', $snapshot->retriesDue);
    Metrics::gauge('laraflow_schedules_stalled', $snapshot->stalledSchedules);
})->everyMinute();
```

— or, for a pull-based system like Prometheus, take one in the handler that answers the scrape. `Metrics` above stands for your own client.

## Choosing labels

Whatever you name your series, two rules keep a metrics bill bounded:

- **Label by what code bounds**, not by what traffic does: workflow and step class, status, connector name, outcome. Never an execution id, correlation id, error message, or request path.
- **Add the tenant deliberately.** A tenant id is bounded by your customer list, which may be ten or a hundred thousand, and every tenant multiplies every series it appears on. Every lifecycle event carries `tenantId`; the connector events carry the running execution's.

For one execution's story rather than the totals — which step, which attempt, which connector call — see [tracing.md](tracing.md).
