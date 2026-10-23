# Queue execution and operational recovery

This document specifies how Laraflow runs executions on a Laravel queue and how interrupted work is found and retried. It builds on the durability contract in [docs/execution-lifecycle.md](execution-lifecycle.md) — queueing changes *where* a step runs, never *what is guaranteed* about it.

The behaviour described here is exercised end to end, against a real queue driver and a real `queue:work` worker, in `tests/Feature/Queue/QueueRecoveryTest.php`.

## Running an execution on a queue

`WorkflowDispatcher` is the asynchronous counterpart of `WorkflowRunner::run()`:

```php
use Laraflow\Queue\WorkflowDispatcher;

$execution = app(WorkflowDispatcher::class)
    ->dispatch(new SyncCustomerWorkflow(), ['customer_id' => 42]);

$execution->id;          // usable immediately
$execution->isPending(); // true — nothing has run yet
```

`dispatch()` persists the execution and its full step plan (exactly as `start()` does), then queues one `ExecuteWorkflowJob` carrying **only the execution id**. The worker reads the workflow class from the execution record, resolves it from the container, and calls `WorkflowRunner::execute()`.

| Method | Behaviour |
|--------|-----------|
| `dispatch(Workflow $workflow, array $input = [], ?string $connection = null, ?string $queue = null): WorkflowExecution` | Create the execution, then queue it. Nothing runs in the calling process. Validation failures (`InvalidWorkflowDefinitionException`, `UnserializableValueException`) happen *before* anything is persisted or queued. |
| `dispatchExecution(int\|string $id, ?string $connection = null, ?string $queue = null): WorkflowExecution` | Queue an execution that already exists — a failed one to retry, an interrupted one to recover. Refuses a `completed` execution with `ExecutionNotRunnableException`; accepts `running` on purpose (see below). |

`dispatchExecution()` accepts a `running` execution deliberately: that is exactly the state a dead worker leaves behind, so refusing it would make recovery impossible. Dispatching is cheap and the claim decides which worker actually proceeds — a job that arrives while a live worker holds the claim simply no-ops.

### Why the payload carries only an id

The queue payload never contains the workflow instance, the input, or any step data. Two reasons:

1. **Durable state cannot drift from the payload.** The execution record is the single source of truth for what should run; a serialized workflow object could disagree with it after a deploy.
2. **Nothing sensitive reaches the queue backend.** Workflow input often holds tokens or personal data. Keeping it in the database — where it already lives — avoids copying it into Redis, SQS, or a `jobs` table.

### Choosing a connection and queue

```php
'queue' => [
    'connection' => env('LARAFLOW_QUEUE_CONNECTION'), // null = application default
    'queue'      => env('LARAFLOW_QUEUE'),            // null = connection default
],
```

Both default to `null`, which leaves Laravel's own defaults in place. Per-dispatch overrides win over configuration:

```php
$dispatcher->dispatch($workflow, $input, connection: 'redis', queue: 'workflows');
```

Workers are ordinary Laravel workers — there is no Laraflow-specific worker command:

```bash
php artisan queue:work redis --queue=workflows
```

> **If your queue connection is `sync`, nothing is asynchronous.** Laravel's `sync` driver — the default when `QUEUE_CONNECTION` is unset — runs a job immediately in the dispatching process, so `dispatch()` returns an execution that has *already finished* (or already thrown). This is Laravel's behaviour rather than Laraflow's, but it surprises people: if `dispatch()` seems to block, or a step's exception reaches your controller, check the connection first. It also inverts one event ordering: `WorkflowExecutionDispatched` describes the queue hand-off, which under `sync` *is* the run, so it fires after the execution's own lifecycle events. Everything else in this document other than worker timeouts applies unchanged — the claim, checkpoints, and recovery behave identically; only the process boundary differs.

### `after_commit`

`laraflow.queue.after_commit` defaults to `true`, so the job is pushed only once the surrounding database transaction commits. Without it, a worker can receive an execution id before the row that defines it is visible, and immediately fail to find it. Set it to `false` only if you never dispatch inside a transaction and want the lowest possible latency.

This works correctly in tests that use `RefreshDatabase`: Laravel treats the wrapping test transaction as one that after-commit callbacks should ignore, so dispatched jobs are queued rather than held back.

## Retries: one system, not two

Laravel retries failed jobs. Laraflow counts step attempts. If both were active, a failure would be retried `queue tries × Laraflow attempts` times, on two unrelated schedules.

**Laraflow resolves this by giving the queue no retry authority by default:** `laraflow.queue.tries` is `1`. One delivery means one execution attempt. A failing step is persisted as `failed`, the exception propagates, Laravel records the job in `failed_jobs`, and nothing is retried automatically. Re-running is then an explicit, visible act — `laraflow:execute`, `laraflow:recover`, or `WorkflowRunner::execute()`.

Per-step retry policy with backoff, retryable-exception rules, and `next_retry_at` is Milestone 3. Until it lands, there is deliberately no automatic retry of a *failed* step anywhere in the package.

If you raise `tries` above 1, understand what you are buying: each queue retry re-enters `execute()`, which skips completed steps and re-runs the failed one, so step `attempts` accumulate across queue retries. That is coherent, but the retry schedule is Laravel's and Laraflow cannot reason about it.

### Timeout and `stale_after`

| Setting | Meaning |
|---------|---------|
| `laraflow.queue.timeout` (default 60s) | How long a worker lets one job run before killing it. |
| `laraflow.execution.stale_after` (default 300s) | How long a claim may go unrefreshed before another worker may take the execution over. |

**`timeout` must stay comfortably below `stale_after`.** If a job may run longer than `stale_after`, a second worker can reclaim an execution that is still legitimately running. That is not corrupting — the loser's writes are rejected by the claim token and it receives `ClaimLostException` — but it wastes work and duplicates side effects. Set `stale_after` above your longest realistic step, then set `timeout` below it.

A killed or crashed worker never reaches any cleanup code. That is the point of claim-based recovery: the dead worker's claim simply stops being refreshed and ages out.

## Duplicate deliveries

Every supported queue backend delivers at least once. Duplicates are safe here because claiming is a single atomic compare-and-set:

| Situation | Result |
|---|---|
| Two jobs for a `pending` execution | One claims and runs it; the other logs and returns successfully. |
| A job arrives for a `running` execution with a live claim | `ExecutionAlreadyClaimedException`, caught — the job succeeds without running anything. |
| A job arrives for a `completed` execution | `ExecutionNotRunnableException`, caught — job succeeds, nothing runs. |
| A worker is reclaimed mid-run | `ClaimLostException`, caught — nothing this worker did was persisted; the new owner is authoritative. |

All four are **no-ops, not failures**: they are logged and the job completes, so duplicate delivery never pollutes `failed_jobs`. This requires no cache lock and no `ShouldBeUnique`; the database claim is the only authority.

What duplicates do *not* change: the at-least-once contract still applies to a step interrupted between performing a side effect and checkpointing it. Use `$context->idempotencyKey()` for external calls that must not repeat.

### What does fail the job

- **A step exception.** The runner persists the failure first, then the exception propagates so Laravel records it. Both systems see it.
- **A missing or invalid workflow class** (`WorkflowDefinitionMismatchException`). This is a deployment problem — code was removed while executions referencing it were in flight — and must be visible to an operator, not retried silently.

A job whose execution record no longer exists (pruned) logs a warning and returns; retrying could not repair it.

## Recovering interrupted work

A worker that dies leaves its execution `running` with an aging `claimed_at`. Nothing notices on its own, so Laraflow ships a command to find such work and retry it:

```bash
php artisan laraflow:recover
```

Schedule it; the package deliberately does not schedule itself:

```php
// routes/console.php (or bootstrap/app.php)
Schedule::command('laraflow:recover')->everyFiveMinutes()->withoutOverlapping();
```

### What it looks for

| Reason | Criteria | Default |
|---|---|---|
| `stale claim` | `running`, with `claimed_at` older than `stale_after` (or null) | Always scanned |
| `never dispatched` | `pending`, created more than `pending_after` ago | **Off** — opt in |

The pending scan is off by default on purpose. `pending` is not only the state of a lost dispatch; it is also the state of an execution created with `start()` and deliberately not yet run. Recovery must never start work the application was intentionally holding. Enable it — with `--pending-after=300` or `laraflow.recovery.pending_after` — only if every pending execution in your application is meant to run immediately.

### Options

```
--stale-after=   Seconds before a running claim counts as stale  (laraflow.execution.stale_after)
--pending-after= Also recover pending executions older than this (laraflow.recovery.pending_after)
--limit=         Maximum executions handled in one run           (laraflow.recovery.limit, 100)
--max-attempts=  Attempt bound before an execution is abandoned  (laraflow.recovery.max_attempts, 3)
--workflow=      Only executions created from this workflow class
--dry-run        Report what would happen; change nothing
--sync           Run recovered executions in this process instead of queueing them
```

`--dry-run` takes no claim, queues nothing, and writes nothing — safe to run against production to see what recovery *would* do. `--sync` is useful for one-off operator work and for applications with no queue worker running.

### Bounds — why recovery cannot loop forever

Scheduled recovery that blindly re-dispatches would retry a crash-looping workflow until something gives. Three bounds prevent that:

1. **Per-run limit.** At most `--limit` executions are handled per invocation, shared across both scans (stale work first, pending work fills the remainder).
2. **Attempt bound.** An execution whose steps have reached `--max-attempts` (default 3) is reported as **abandoned** and is *not* retried. The bound uses the highest step `attempts` value, which is already durable — recovery needs no extra state to be bounded.
3. **Missing-class bound.** An execution whose recorded workflow class no longer exists is reported as **unrecoverable** and is never dispatched. This bound exists because such a retry fails *before* any step runs — attempts never increment, so the attempt bound alone could never stop the scheduler from re-queueing it (and filling `failed_jobs`) forever. Restore or redeploy the class, or handle the execution manually.

Abandoned executions are reported on every run, and the command exits with status `1` whenever anything was abandoned or any recovery action failed — including under `--dry-run` — so a scheduled run surfaces stuck work through your existing scheduler failure alerts. They are never silently dropped and never silently retried.

An operator deliberately overriding the bound uses the per-execution command, which has no attempt bound:

```bash
php artisan laraflow:execute 42          # run it here
php artisan laraflow:execute 42 --queue  # or hand it to a worker
```

### Reading the report

```
+----+--------------+---------+-----------------+----------+---------------------+----------------------------------+
| Id | Workflow     | Status  | Reason          | Attempts | Since               | Action                           |
+----+--------------+---------+-----------------+----------+---------------------+----------------------------------+
| 17 | DemoWorkflow | running | stale claim     | 1        | 2026-10-22 10:00:00 | dispatched                       |
| 23 | DemoWorkflow | running | stale claim     | 3        | 2026-10-22 09:14:02 | abandoned (max attempts reached) |
| 24 | DemoWorkflow | pending | never dispatched| 0        | 2026-10-22 09:02:11 | dispatched                       |
+----+--------------+---------+-----------------+----------+---------------------+----------------------------------+

   INFO  Handled 3 execution(s): 2 dispatched, 0 ran, 1 abandoned, 0 unrecoverable, 0 skipped, 0 failed.

   WARN  Abandoned executions have exceeded the recovery attempt bound. Investigate them,
         then re-run deliberately with: laraflow:execute <id>
```

`Since` is when the interruption began — the claim time for stale work, the creation time for never-dispatched work. The summary line counts dispatched, ran, abandoned, skipped, and failed executions. *Skipped* means the execution completed or was claimed by a live worker between the scan and the action; those races are expected and harmless.

## Lifecycle events

Laraflow fires events at each durable boundary. They are plain objects in `Laraflow\Events` — no `ShouldBroadcast`, no queue requirement.

| Event | Fired when |
|---|---|
| `WorkflowExecutionDispatched` | An execution was handed to the queue. |
| `WorkflowExecutionStarted` | A worker claimed an execution and is about to run it. |
| `WorkflowStepStarted` | A step was marked running. |
| `WorkflowStepCompleted` | A step's checkpoint committed. |
| `WorkflowStepFailed` | A step's failure was persisted. |
| `WorkflowExecutionCompleted` | The execution was marked completed. Fires once. |
| `WorkflowExecutionFailed` | The execution was marked failed. |
| `WorkflowExecutionRetryScheduled` | Recovery selected an execution for retry. |

Two properties hold for all of them:

- **Each fires strictly *after* the durable write it describes.** A listener can always trust what it is told, and can re-read the execution from the repository. If persisting a failure itself fails, no failure event fires — there is no durable state to describe.
- **Some fire more than once per execution.** `Started`, `StepStarted`, `StepFailed`, and `ExecutionFailed` repeat across recovery attempts; `attempt` distinguishes them. Only `ExecutionCompleted` is once-per-execution.

### Payload safety

**Events never carry workflow input, step output, or context.** There is no option to include them. They carry identifiers and metadata: execution id, workflow class, step class, position, attempt, timestamps, queue/connection.

Failure events additionally carry the exception **class**, and the exception **message** truncated to `laraflow.execution.max_error_length`. The message is included by default because operators need it and it is already stored in the execution record — events do not widen what is retained, only where it flows. If exception messages in your application may contain sensitive data:

```php
'events' => [
    'include_error_messages' => false,
],
```

The exception object and stack trace are never included.

### Listeners are observational

A listener that throws is **logged and swallowed**; it never changes execution state, aborts a dispatch, or masks a workflow failure. This differs from Laravel's default synchronous-listener behaviour, and it is deliberate: `WorkflowStepFailed` fires on the path that rethrows the step's original exception, and a throwing listener there would replace a real diagnosis with an unrelated error. A throwing listener on the success path would likewise leave an execution `running` with a live claim, inventing a stale-recovery case out of nothing.

If a listener's failure genuinely needs to be retried or surfaced, make it a **queued listener**: Laravel then owns its retries, independently of the execution.

```php
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionFailed;

Event::listen(function (WorkflowExecutionFailed $event) {
    Log::error('Workflow failed', [
        'execution' => $event->executionId,
        'workflow'  => $event->workflow,
        'error'     => $event->errorClass,
    ]);
});
```

## Operational checklist

- Run workers for the queue you configured: `php artisan queue:work --queue=workflows`.
- Schedule `laraflow:recover` with `withoutOverlapping()`.
- Keep `queue.timeout` below `execution.stale_after`.
- Alert on a non-zero exit from `laraflow:recover` — it means work was abandoned or a recovery action failed.
- Watch `failed_jobs` for step failures and definition mismatches; `laraflow:list --status=failed` shows the same from Laraflow's side.
- Before deleting a workflow class, drain executions referencing it. There is no workflow-version migration mechanism yet.
