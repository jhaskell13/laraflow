# Laraflow

**A framework for building integrations and durable workflows in Laravel.**

Applications often begin integrating external services with a few HTTP calls and queued jobs. As an integration grows, application code takes on more responsibility: coordinating multiple steps, carrying data between them, handling failures, keeping track of what ran, and figuring out where to resume when something goes wrong.

Laraflow gives that work a home. Workflows are ordered, named steps written as plain PHP classes. Connectors give external services a consistent place for authentication, requests, responses, and errors. A runner executes workflows and records every execution and step in your database so progress can be inspected and, when safe, continued.

> **Status: pre-release.** Laraflow is under active development and has not been tagged. The API is expected to change before the first release. See [Current limitations](#current-limitations) and [docs/public-api.md](docs/public-api.md) before depending on it.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Try it locally](#try-it-locally)
- [Configuration](#configuration)
- [Your first workflow](#your-first-workflow)
- [Retries, timeouts, and cancellation](#retries-timeouts-and-cancellation)
- [Running on a queue](#running-on-a-queue)
- [Inspecting and correlating executions](#inspecting-and-correlating-executions)
- [Retention](#retention)
- [Your first connector](#your-first-connector)
- [Current limitations](#current-limitations)
- [Design principles](#design-principles)
- [Compatibility and support](#compatibility-and-support)
- [Contributing](#contributing)

## Requirements

| Dependency | Supported |
|------------|-----------|
| PHP        | 8.2, 8.3, 8.4 |
| Laravel    | 12.x |
| Database   | SQLite (tested), MySQL 8+, PostgreSQL 13+, MariaDB 10.6+ |

See [docs/compatibility.md](docs/compatibility.md) for the full policy, including what "supported" means at this stage.

## Installation

The package is not yet published on Packagist. Add the repository to your application's `composer.json` and require the development branch:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/jhaskell13/laraflow" }
    ]
}
```

```bash
composer require laraflow/laraflow:dev-main
```

Laravel's package auto-discovery registers `Laraflow\Core\LaraflowServiceProvider`. No manual provider registration is needed.

### Database tables

Laraflow stores executions in three tables: `laraflow_workflow_executions`, `laraflow_step_executions`, and `laraflow_step_attempts` (the append-only per-attempt history). The package loads its own migrations, so you only need to run:

```bash
php artisan migrate
```

If you want to review or customise the migrations first, publish them and disable automatic loading:

```bash
php artisan vendor:publish --tag=laraflow-migrations
```

```dotenv
LARAFLOW_LOAD_MIGRATIONS=false
```

## Try it locally

The repository ships a playground (via Orchestra Testbench), the package's experimental Artisan commands, and a traffic generator plus a live view, so you can watch the durable runtime work without creating a Laravel application:

```bash
git clone https://github.com/jhaskell13/laraflow.git
cd laraflow
composer install
composer playground     # creates a SQLite playground database and runs the migrations
```

> Re-run `composer playground` after pulling changes. Until the schema is frozen (roadmap Milestone 6) Laraflow edits its migrations in place rather than adding new ones, so an existing database does **not** pick up schema changes — `migrate` considers those migrations already run. The playground database is disposable, so `composer playground` resets it.

The demo workflow simulates an order: **reserve inventory → charge payment → send receipt**. With `--fail`, the payment step throws on its first attempt only, so every failure path below recovers on the next attempt.

The playground runs a **real database queue**, not Laravel's `sync` driver, so dispatched executions genuinely wait for a worker.

### A full walkthrough

Each block stands alone; run them in order for the complete tour. Execution ids increment, so adjust them if you skip around (`laraflow:list` shows them).

**1 · Durable execution, start to finish**

```bash
vendor/bin/testbench laraflow:demo          # inline: three steps, checkpointed
vendor/bin/testbench laraflow:show 1        # steps, attempts, outputs, context
```

**2 · Asynchronous execution on a real queue**

```bash
vendor/bin/testbench laraflow:demo --queue  # returns immediately; nothing has run
vendor/bin/testbench laraflow:list          # pending, 0/3 steps
vendor/bin/testbench queue:work --stop-when-empty
vendor/bin/testbench laraflow:list          # completed, 3/3
```

**3 · Failure and recovery from a checkpoint**

```bash
vendor/bin/testbench laraflow:demo --fail   # payment fails; execution is failed, 1/3 done
vendor/bin/testbench laraflow:execute 3     # resumes: step 1 skipped, payment succeeds on attempt 2
```

**4 · Retry policy: a failure that heals itself**

Retries are off by default, so turn them on for the demo:

```bash
export LARAFLOW_RETRY_MAX_ATTEMPTS=3 LARAFLOW_RETRY_BASE_DELAY=5 LARAFLOW_RETRY_JITTER=0

vendor/bin/testbench laraflow:demo --fail   # now `retrying`, not `failed` — note the due time
vendor/bin/testbench laraflow:show 4 --attempts
```

The history shows both attempts and the retry the failure scheduled:

```
| Step             | Attempt | Outcome   | Started             | Duration | Scheduled retry     |
| ReserveInventory | 1       | completed | 2026-10-23 23:29:24 | 11 ms    |                     |
| ChargePayment    | 1       | failed    | 2026-10-23 23:29:24 | 3 ms     | 2026-10-23 23:29:29 |
```

The backoff is enforced, and overriding it is explicit:

```bash
vendor/bin/testbench laraflow:execute 4         # refused: "not due until 23:29:29"
vendor/bin/testbench laraflow:execute 4 --now   # the deliberate override
```

**5 · Retries delivered by the queue**

```bash
vendor/bin/testbench laraflow:demo --fail --queue
vendor/bin/testbench queue:work --stop-when-empty   # step fails, retry scheduled, job SUCCEEDS
vendor/bin/testbench laraflow:list                  # retrying
```

A scheduled retry is not a failed job — `failed_jobs` stays empty — and a delayed job now waits for the due time. After the backoff elapses:

```bash
vendor/bin/testbench queue:work --stop-when-empty   # the retry runs and completes
```

**6 · Retries delivered without any queue**

`laraflow:recover` is the contractual delivery path; the delayed job is only the fast path.

```bash
export LARAFLOW_RETRY_DISPATCH_DELAYED=false
vendor/bin/testbench laraflow:demo --fail
vendor/bin/testbench laraflow:recover          # "Nothing to recover" — not due yet
# ...wait out the backoff...
vendor/bin/testbench laraflow:recover --sync   # reason "retry due"; runs it here
```

**7 · Cancellation**

```bash
vendor/bin/testbench laraflow:demo --fail
vendor/bin/testbench laraflow:cancel 7 --reason="customer withdrew the order"
vendor/bin/testbench laraflow:execute 7        # refused: cancelled is terminal
vendor/bin/testbench laraflow:recover          # and invisible to recovery
```

**8 · Operator views**

```bash
vendor/bin/testbench laraflow:list --status=retrying
vendor/bin/testbench laraflow:summary               # counts, and what needs attention
vendor/bin/testbench laraflow:recover --dry-run     # what recovery would do; changes nothing
```

**9 · Correlation, filtering, and paging**

Executions can record where they came from. Run a few with identifiers attached:

```bash
vendor/bin/testbench playground:simulate --count=6 --rate=6 --work=200 --tenants=acme,globex
```

Then find them the way an application would — every one of these filters is served by an index:

```bash
vendor/bin/testbench laraflow:list --tenant=acme
vendor/bin/testbench laraflow:list --correlation=sim-success-001
vendor/bin/testbench laraflow:list --since="-5 minutes" --limit=3   # prints the cursor for the next page
vendor/bin/testbench laraflow:show 1                                # correlation, tenant, duration, attempts
```

`laraflow:list` reports how many of the matching set it showed and prints the command that continues it, so a bounded page never looks like the whole truth.

**10 · Watching it happen**

The demo workflow finishes in milliseconds, which is fine for proving a point and useless for watching one. The playground ships a workflow with real duration and a traffic generator to drive it.

Open two terminals. In the first, start a worker and leave it running:

```bash
vendor/bin/testbench queue:work --sleep=0
```

In the second, turn on retries and deadlines so every scenario is distinguishable, then generate traffic:

```bash
export LARAFLOW_RETRY_MAX_ATTEMPTS=3 LARAFLOW_RETRY_BASE_DELAY=4 LARAFLOW_RETRY_JITTER=0 LARAFLOW_STEP_TIMEOUT=2

vendor/bin/testbench playground:simulate --count=20 --rate=3 --work=300 \
    --mix=success:4,retry:3,fail:2,hang:1
```

Now watch it, in a third terminal or after the generator returns:

```bash
vendor/bin/testbench playground:watch
```

```
  Laraflow · playground                                  2026-10-25 20:08:43

  pending 7   running 1   completed 1   failed 0   retrying 1   cancelled 0
  ────────────────────────────────────────────────────────────────────────
  Id    Workflow           Status     Steps  Age      Detail
  10    SimulatedWorkflow  pending    0/3    3s       queued 3s
  3     SimulatedWorkflow  running    1/3    1s       ChargeCard
  2     SimulatedWorkflow  retrying   1/3    1s       retry due in 3s
  1     SimulatedWorkflow  completed  3/3    1s       took 1.2s

  ────────────────────────────────────────────────────────────────────────
  queue: 7 waiting · 1 reserved  ·  1 retry(s) due — run: testbench laraflow:recover
```

Things worth watching for:

- **Queue depth rising and falling** as the generator outruns the worker and the worker catches up.
- **`retry due in 3s` counting down**, then the footer offering `laraflow:recover` — the contractual delivery path. Stop the worker before a retry comes due and recover it by hand to see that it needs no queue at all.
- **`(overdue)`** beside a running step from the `hang` scenario: it ignored its deadline. Laraflow does not interrupt running PHP, so nothing acts on it while the claim is fresh — kill the worker with Ctrl-C and run `laraflow:recover` to watch the attempt be classified as a timeout.
- **Kill the worker mid-step** at any time. The execution stays `running` with an aging claim; `laraflow:recover` picks it up once it is stale, and the completed steps are skipped.

The generator's scenarios are `success`, `retry` (fails once, heals), `fail` (fails terminally), and `hang` (ignores its deadline); `--mix` weights them. `playground:watch --once` prints a single frame, which is useful in a script.

**11 · Retention**

Nothing is removed until you say so:

```bash
vendor/bin/testbench laraflow:prune     # refuses, exit code 1: no bounds configured
```

A scheduled prune that silently did nothing would be indistinguishable from one that was working, so an unconfigured run is reported as the misconfiguration it is. Give it a bound:

```bash
vendor/bin/testbench laraflow:prune --prune-attempts-after=1 --delete-after=7 --dry-run
```

```
   INFO  Nothing would be pruned: no finished execution is past any configured bound.
```

That is the correct answer, and it is the point: bounds count in days **from when the execution finished**, and everything here finished seconds ago. Nothing you just made is old enough. Age some of it and ask again:

```bash
vendor/bin/testbench tinker --execute="DB::table('laraflow_workflow_executions')
    ->whereNotNull('finished_at')
    ->update(['finished_at' => now()->subDays(30)]);"

vendor/bin/testbench laraflow:prune --prune-attempts-after=1 --delete-after=7 --dry-run
vendor/bin/testbench laraflow:prune --prune-attempts-after=1 --delete-after=7 --force
```

The dry run predicts the real run exactly — compare the two reports. Note what survives: a `failed` execution past the delete bound is still there, because failed work can still be recovered and is only ever removed by `--delete-failed-after`, which you did not pass.

---

The `laraflow:*` commands are registered in any application that installs the package; they are experimental, and documented in [docs/public-api.md](docs/public-api.md). The `playground:*` commands are **not** part of the package — they live in this repository's `workbench/` directory, because a traffic generator has no business being installable into an application.

## Configuration

Publish the configuration file if you need to change defaults:

```bash
php artisan vendor:publish --tag=laraflow-config
```

This creates `config/laraflow.php`:

```php
return [
    'migrations' => [
        // Load migrations from the package (true) or manage published copies (false).
        'load' => env('LARAFLOW_LOAD_MIGRATIONS', true),
    ],

    'execution' => [
        // Seconds before a running execution's claim is considered abandoned
        // and may be reclaimed. Set it above your longest step.
        'stale_after' => env('LARAFLOW_STALE_AFTER', 300),
        'max_error_length' => env('LARAFLOW_MAX_ERROR_LENGTH', 65535),
        // Largest a single input, output, or context snapshot may be, as
        // JSON; null leaves the only limit the database's.
        'max_payload_bytes' => env('LARAFLOW_MAX_PAYLOAD_BYTES'),
    ],

    'retry' => [
        // Default step retry policy. 1 counts the first attempt, so the
        // shipped value means nothing is retried until you opt in.
        'max_attempts' => env('LARAFLOW_RETRY_MAX_ATTEMPTS', 1),
        'base_delay' => env('LARAFLOW_RETRY_BASE_DELAY', 5),
        'multiplier' => env('LARAFLOW_RETRY_MULTIPLIER', 2.0),
        'max_delay' => env('LARAFLOW_RETRY_MAX_DELAY', 300),
        'jitter' => env('LARAFLOW_RETRY_JITTER', 0.2),
        // Deliver due retries via a delayed queue job; laraflow:recover
        // remains the safety net either way.
        'dispatch_delayed' => env('LARAFLOW_RETRY_DISPATCH_DELAYED', true),
    ],

    'timeout' => [
        // Default per-attempt step deadline in seconds; null = none.
        'step' => env('LARAFLOW_STEP_TIMEOUT'),
    ],

    'errors' => [
        // Optional ErrorRedactor class applied to every exception message
        // Laraflow persists, logs, or emits.
        'redactor' => env('LARAFLOW_ERROR_REDACTOR'),
    ],

    'queue' => [
        // null uses your application's default connection and queue.
        'connection' => env('LARAFLOW_QUEUE_CONNECTION'),
        'queue' => env('LARAFLOW_QUEUE'),
        // 1 = the queue does not retry; see docs/queue-and-recovery.md.
        'tries' => env('LARAFLOW_QUEUE_TRIES', 1),
        'backoff' => env('LARAFLOW_QUEUE_BACKOFF', 0),
        // Must stay below execution.stale_after.
        'timeout' => env('LARAFLOW_QUEUE_TIMEOUT', 60),
        'after_commit' => env('LARAFLOW_QUEUE_AFTER_COMMIT', true),
    ],

    'recovery' => [
        'limit' => env('LARAFLOW_RECOVERY_LIMIT', 100),
        // Interrupted executions whose steps reach this many attempts are
        // abandoned by recovery rather than retried forever.
        'abandon_after_attempts' => env('LARAFLOW_RECOVERY_ABANDON_AFTER_ATTEMPTS', 3),
        // null disables recovery of never-dispatched pending executions.
        'pending_after' => env('LARAFLOW_RECOVERY_PENDING_AFTER'),
    ],

    'retention' => [
        // All null: nothing is removed until you say what to keep. Days,
        // counted from when the execution finished. See docs/retention.md.
        'scrub_after_days' => env('LARAFLOW_RETENTION_SCRUB_AFTER_DAYS'),
        'prune_attempts_after_days' => env('LARAFLOW_RETENTION_PRUNE_ATTEMPTS_AFTER_DAYS'),
        'delete_after_days' => env('LARAFLOW_RETENTION_DELETE_AFTER_DAYS'),
        // Failed executions can still be recovered, so they have their own bound.
        'delete_failed_after_days' => env('LARAFLOW_RETENTION_DELETE_FAILED_AFTER_DAYS'),
        'limit' => env('LARAFLOW_RETENTION_LIMIT', 1000),
        'chunk' => env('LARAFLOW_RETENTION_CHUNK', 100),
    ],

    'logging' => [
        // Put the running execution's identity (laraflow.* keys) into
        // Laravel's log context while a step runs.
        'context' => env('LARAFLOW_LOG_CONTEXT', true),
        // One log record per lifecycle event; off because an application
        // already listening to events should not also pay for a line per step.
        'lifecycle' => env('LARAFLOW_LOG_LIFECYCLE', false),
    ],

    'events' => [
        // Lifecycle events never carry input, output, or context. This
        // controls only the exception message on failure events.
        'include_error_messages' => env('LARAFLOW_EVENT_ERROR_MESSAGES', true),
    ],

    'http' => [
        // Defaults every connection starts from; a connector class and then
        // a connection may override them.
        'timeout' => env('LARAFLOW_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('LARAFLOW_HTTP_CONNECT_TIMEOUT', 10),
    ],

    'integrations' => [
        // One entry per connector: the class, the default connection, and
        // one named connection per account. Nothing else is accepted here.
        'github' => [
            'connector' => \Laraflow\Integration\Github\GithubConnector::class,
            'default' => env('LARAFLOW_GITHUB_CONNECTION', 'default'),
            'connections' => [
                'default' => [
                    // Read when a request is built, never held by the connector.
                    'credentials' => [
                        'token' => env('LARAFLOW_GITHUB_TOKEN'),
                    ],
                ],
            ],
        ],
    ],
];
```

Keep credentials in your `.env` file, not in the published config.

## Your first workflow

A workflow is a class that lists its steps in order. Each step is a class implementing `Laraflow\Workflow\Step` and is resolved from the Laravel container, so you can type-hint dependencies in step constructors.

```php
<?php

namespace App\Workflows;

use Laraflow\Workflow\Workflow;

class SyncCustomerWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            LoadCustomer::class,
            SyncWithCrm::class,
            RecordSyncResult::class,
        ];
    }
}
```

Steps communicate through a `WorkflowContext`. A step reads what earlier steps stored, does its work, and stores results for later steps. Whatever a step returns from `handle()` is persisted as that step's output.

```php
<?php

namespace App\Workflows;

use App\Models\Customer;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

class LoadCustomer implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $customer = Customer::findOrFail($context->get('customer_id'));

        $context->set('customer_email', $customer->email);
        $context->set('customer_name', $customer->name);

        // Returned values are persisted as the step output. Keep them JSON-friendly.
        return ['customer_id' => $customer->id];
    }
}
```

```php
class SyncWithCrm implements Step
{
    public function __construct(private CrmClient $crm) {}

    public function handle(WorkflowContext $context): mixed
    {
        $remoteId = $this->crm->upsertContact(
            email: $context->get('customer_email'),
            name: $context->get('customer_name'),
        );

        $context->set('crm_contact_id', $remoteId);

        return ['crm_contact_id' => $remoteId];
    }
}
```

### Running it

Resolve the `WorkflowRunner` from the container and call `run()` with optional input. Execution is synchronous: the call returns a `WorkflowExecution` handle once every step has completed, or throws the step's exception after persisting the failure. Input values seed the context, so steps read them with `$context->get('customer_id')`.

> Once you enable [retries](#retries-timeouts-and-cancellation), a retryable failure returns a non-terminal `retrying` handle instead of throwing. Check `$execution->isRetrying()`, or leave retries off for workflows you run in-process.

```php
use Laraflow\Workflow\WorkflowRunner;

$runner = app(WorkflowRunner::class);

$execution = $runner->run(new SyncCustomerWorkflow(), ['customer_id' => 42]);

$execution->id;            // store or display it
$execution->isCompleted(); // true
$execution->context;       // everything the steps stored
```

Creation and execution are separate when you need them to be:

```php
$execution = $runner->start(new SyncCustomerWorkflow(), ['customer_id' => 42]);
// ...persisted as pending with its full step plan; nothing has run...
$execution = $runner->execute($execution->id, new SyncCustomerWorkflow());
```

### Failure and recovery

If a step throws, the failure (message and exception class) is persisted on both the step and the execution, and the exception is rethrown. Calling `execute()` again on a failed execution **resumes it**: completed steps are skipped, their results are restored into the context from durable state, and the failed step runs again.

```php
try {
    $runner->run(new SyncCustomerWorkflow(), ['customer_id' => 42]);
} catch (\Throwable $e) {
    // Later — after fixing the cause — continue where it stopped:
    $runner->execute($executionId, new SyncCustomerWorkflow());
}
```

The same call recovers executions whose worker died mid-run: a `running` execution whose claim is older than `laraflow.execution.stale_after` (default 300 seconds) can be reclaimed, and the interrupted step runs again. Two workers can never both hold a live execution — claiming is an atomic compare-and-set, and a worker that loses its claim can no longer write durable state.

A step that repeats this way may re-perform external side effects — that is the **at-least-once contract**. Steps can deduplicate using `$context->idempotencyKey()`, which is stable across crashes and retries of the same step. The full contract, state machines, and crash-boundary behaviour are specified in [docs/execution-lifecycle.md](docs/execution-lifecycle.md).

### What gets persisted

`start()` (and therefore `run()`) creates one row in `laraflow_workflow_executions` and — up front, before anything runs — one row per step in `laraflow_step_executions`. Every finished attempt of a step then appends one row to `laraflow_step_attempts`:

| Record | Fields |
|--------|--------|
| Workflow execution | workflow class, status (`pending`, `running`, `completed`, `failed`, `retrying`, `cancelled`), input, context snapshot, error message and class, claim token/time, started/completed/failed timestamps, next retry time, cancellation request/time/reason |
| Step execution | step class, position, status (`pending`, `running`, `completed`, `failed`, `retrying`), attempts, output, error message and class, started/completed/failed timestamps, current attempt's deadline, next retry time |
| Step attempt | attempt number, outcome (`completed`, `failed`, `timed_out`, `interrupted`), error message and class, started/ended timestamps, duration, scheduled retry time |

After every completed step, its output and the full context snapshot are checkpointed in one transaction. Input, outputs, and context values must be JSON-serializable (scalars and arrays); anything else is rejected with a clear exception naming the offending key.

## Retries, timeouts, and cancellation

A failed step is never retried unless you ask. A step — or a whole workflow — can declare a retry policy: attempts, exponential backoff with deterministic jitter, which exceptions qualify, and a per-attempt deadline.

```php
use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\StepPolicy;

class SyncWithCrm implements Step, HasStepPolicy
{
    public function stepPolicy(): StepPolicy
    {
        return new StepPolicy(
            maxAttempts: 5,
            baseDelaySeconds: 10,
            retryOn: [TransportException::class],
            timeoutSeconds: 30,
        );
    }

    // handle() as before...
}
```

When a retryable failure happens, **nothing is thrown and nothing lands in `failed_jobs`**: the failed attempt is recorded in a per-attempt history table, the execution enters a `retrying` state with a durable `next_retry_at`, and the retry is delivered when due — by a delayed queue job, with `laraflow:recover` as the always-on safety net. An execution that will heal itself does not page anyone, so alerting on `WorkflowExecutionFailed` stays meaningful.

Operators can see why a step will retry and when, run it early, or call it off:

```bash
php artisan laraflow:show 42 --attempts   # every attempt: outcome, duration, scheduled retry
php artisan laraflow:execute 42 --now     # don't wait for the backoff
php artisan laraflow:cancel 42 --reason="customer withdrew the order"
```

Cancellation is immediate for work that is waiting and **cooperative** for work that is running — the worker stops at its next step boundary, and the step in flight finishes and checkpoints first.

Timeouts are deadlines, not preemption: Laraflow does not interrupt running PHP. A step reads its own deadline from the context (`$context->remainingSeconds()`) and bounds its own work; an attempt whose worker died *past* its deadline is charged to the retry budget as a timeout, while one that died before it re-runs for free. The full contract — schedules, the three-way timeout distinction, the two attempt bounds, cancellation semantics, and error redaction — is in [docs/retry-and-failure-policy.md](docs/retry-and-failure-policy.md).

## Running on a queue

`WorkflowDispatcher` is the asynchronous counterpart of `run()`. It persists the execution and its step plan, queues a job carrying only the execution id, and returns immediately:

```php
use Laraflow\Queue\WorkflowDispatcher;

$execution = app(WorkflowDispatcher::class)
    ->dispatch(new SyncCustomerWorkflow(), ['customer_id' => 42]);

$execution->id;          // store it, show it, poll it
$execution->isPending(); // true — a worker will run it
```

A worker is an ordinary Laravel worker; there is no Laraflow-specific worker command:

```bash
php artisan queue:work --queue=workflows
```

Note that Laravel's default `sync` connection (when `QUEUE_CONNECTION` is unset) runs the job immediately in the dispatching process, so `dispatch()` returns an already-finished execution. Configure a real queue connection to get asynchronous execution.

The worker reads the workflow class from the execution record, so the queue payload never contains your input, your step data, or a serialized workflow object. Connection and queue come from `laraflow.queue.*` or per-dispatch arguments.

**The queue does not retry by default** (`laraflow.queue.tries` is `1`). One delivery means one execution attempt, so Laravel's retries and Laraflow's step attempts cannot multiply on two unrelated schedules. A failing step is persisted as `failed` and recorded in `failed_jobs`; re-running it is an explicit act. Duplicate deliveries are safe: claiming is an atomic compare-and-set, so a second delivery simply no-ops instead of running the workflow twice or failing the job.

### Recovering interrupted work

A worker that is killed mid-step leaves its execution `running` with an aging claim. Schedule the recovery command to find and retry such work:

```php
// routes/console.php
Schedule::command('laraflow:recover')->everyFiveMinutes()->withoutOverlapping();
```

```bash
php artisan laraflow:recover --dry-run   # report only; changes nothing
php artisan laraflow:recover             # re-dispatch interrupted executions
php artisan laraflow:recover --sync      # run them here instead of queueing
```

The same command also delivers scheduled retries that have come due, making it the safety net behind the queue fast path — and the only delivery path if you have no queue worker. Recovery is bounded: at most `--limit` executions per run; an *interrupted* execution whose steps have reached `--abandon-after` (default 3) is reported as **abandoned** rather than retried, so a crash-looping workflow can never be re-dispatched forever; and an execution whose workflow class no longer exists is reported as **unrecoverable** rather than queued into guaranteed failure. The command exits non-zero when anything was abandoned, unrecoverable, or failed, so a scheduled run surfaces stuck work through your existing alerts. Overriding the attempt bound deliberately is `php artisan laraflow:execute <id>`.

### Lifecycle events

Laraflow fires events for dispatch, execution start, each step's start/completion/failure, scheduled retries, and execution completion/failure/cancellation. Each fires **after** the durable write it describes, and none of them carry workflow input, step output, or context — only identifiers and metadata.

```php
use Laraflow\Events\WorkflowExecutionFailed;

Event::listen(function (WorkflowExecutionFailed $event) {
    Log::error('Workflow failed', [
        'execution' => $event->executionId,
        'workflow' => $event->workflow,
        'error' => $event->errorClass,
    ]);
});
```

Queueing, retry interaction, recovery policy, and event payload safety are specified in full in [docs/queue-and-recovery.md](docs/queue-and-recovery.md).

## Inspecting and correlating executions

The stable way to read executions is the `Laraflow\Workflow\ExecutionRepository` contract, which returns plain value objects. Everything the console shows, it reads through this same contract — no command touches an Eloquent model, and a test keeps it that way.

```php
use Laraflow\Workflow\ExecutionRepository;

$repository = app(ExecutionRepository::class);

$execution = $repository->find($executionId);

$execution->status;        // ExecutionStatus enum
$execution->isFinished();  // completed, failed, or cancelled
$execution->error;         // message of the failing step, if any
$execution->durationMs();  // first start to finish; null while unfinished

foreach ($repository->steps($execution) as $step) {
    echo "{$step->position}: {$step->step} [{$step->status->value}]";
}

foreach ($repository->attemptHistory($execution) as $attempt) {
    echo "{$attempt->attempt}: {$attempt->status->value} in {$attempt->durationMs()} ms";
}
```

### Listing and filtering

Every filter is served from an index, and pages are keyset-paginated so a listing stays correct while rows are being inserted and costs the same on page 1000 as on page 1:

```php
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;

$filter = new ExecutionFilter(
    workflow: SyncCustomerWorkflow::class,
    status: [ExecutionStatus::Failed, ExecutionStatus::Retrying],
    tenantId: 'acme',
    createdAfter: now()->subDay()->toImmutable(),
);

$page = $repository->list($filter, limit: 50);          // ExecutionPage: iterable, countable
$next = $repository->list($filter, 50, $page->nextCursor);
$total = $repository->count($filter);
```

### Correlation

Record where an execution came from, and the identifier follows it everywhere — into steps, onto every lifecycle event, and into Laravel's log context, which propagates to any job a step dispatches:

```php
use Laraflow\Workflow\ExecutionOptions;

$runner->run($workflow, $input, new ExecutionOptions(
    correlationId: $request->header('X-Request-Id'),
    tenantId: (string) $tenant->id,
));
```

Inside a step, `$context->correlationId()` is what you forward to an external provider so their records and yours share one id, and a plain `Log::info()` already carries `laraflow.execution_id`, `laraflow.step`, and `laraflow.correlation_id` without you passing anything. Then:

```bash
php artisan laraflow:list --correlation=req-abc123       # find it
php artisan laraflow:list --tenant=acme --status=failed  # a tenant's failures
php artisan laraflow:summary                             # counts by status; exits non-zero if anything needs attention
```

Events carry `durationMs` (steps and executions) and `queueLagSeconds` (how long an execution waited for a worker, measured from when it became available rather than when it was dispatched, so a retry's own backoff is never counted). A listener turns those into whatever metrics your stack wants; Laraflow ships no vendor integration on purpose. The full contract, including how to correlate with requests, jobs, tenants, and provider calls, is in [docs/inspection-and-observability.md](docs/inspection-and-observability.md).

## Retention

Nothing is removed until you say what to keep. Every bound in `laraflow.retention.*` is null by default, and you schedule the command:

```php
Schedule::command('laraflow:prune --force')->daily()->withoutOverlapping();
```

Three tiers, measured in days since the execution **finished**: scrub payloads of completed and cancelled executions (keeping the record as an audit trail), prune the attempt history (the table that grows under retry policies), and delete. Failed executions are never scrubbed — re-executing one needs its input and context — and are deleted only under their own `delete_failed_after_days`, because discarding recoverable work should be a decision rather than a side effect. Every write re-checks that the execution is still finished, so one a worker reclaimed in between is left alone; deletion leaves no orphans whether or not your database enforces the cascades; and `--dry-run` predicts the real run exactly.

```bash
php artisan laraflow:prune --dry-run                              # what would happen
php artisan laraflow:prune --tenant=acme --scrub-after=1 --force  # a data-deletion request
```

The command exits non-zero when no bound is configured — a scheduled prune that silently does nothing looks exactly like one that is working. The contract, including the trade-off in pruning failed executions' history and an archive-before-prune recipe, is in [docs/retention.md](docs/retention.md).

## Your first connector

Connectors wrap an external service behind a small, testable contract. Laraflow ships with a GitHub connector as a working example.

### Using the bundled GitHub connector

```dotenv
LARAFLOW_GITHUB_TOKEN=ghp_your_token
```

```php
use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorRequest;

$response = app(LaraflowManager::class)
    ->connector('github')
    ->request(new ConnectorRequest('GET', '/repos/laravel/framework'));

if ($response->successful()) {
    $stars = $response->body()['stargazers_count'];
}
```

`ConnectorResponse` exposes `status()`, `headers()`, `body()`, `successful()`, and `failed()`. Connection failures throw `Laraflow\Exceptions\TransportException`.

A connector serves any number of **connections** — one per account. Add a second entry under `connections` and name it when resolving; the same class serves both:

```php
'connections' => [
    'default' => ['credentials' => ['token' => env('LARAFLOW_GITHUB_TOKEN')]],
    'enterprise' => ['credentials' => ['token' => env('GHE_TOKEN')]],
],
```

```php
$laraflow->connector('github', 'enterprise')->request(...);
```

Credentials are resolved by a `CredentialProvider` **when each request is built**, not when the connector is constructed. Rotating a token means changing what the provider returns — the next request uses it, nothing is rebuilt — and the connector object itself never holds one. The default provider reads the `credentials` array above; bind your own to read a vault or a per-tenant store:

```php
$this->app->singleton(CredentialProvider::class, VaultCredentialProvider::class);
```

Every credential is a `Laraflow\Support\Secret`: it prints as `[redacted]` wherever PHP would print it — logs, exception pages, `var_dump`, JSON, serialization — and gives up its value only to `reveal()`.

### Writing your own

Extend `Laraflow\Integration\HttpConnector` and describe the provider: where it lives, which headers it wants, how it authenticates. The base class resolves credentials for each request, authenticates, and sends through the configured transport:

```php
<?php

namespace App\Integrations;

use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Integration\HttpConnector;

class CrmConnector extends HttpConnector
{
    protected function baseUrl(): ?string
    {
        return 'https://crm.example/api/v2';
    }

    protected function headers(): array
    {
        return ['Accept' => 'application/json'];
    }

    protected function authenticator(): Authenticator
    {
        return new BearerTokenAuthenticator('token', 'CRM_TOKEN');
    }
}
```

Register it by naming the class in configuration. It is built through the container with its `Connection` passed as `$connection` and everything else autowired, on first use, once per connection. A connection may override the class's `base_url`, `timeout`, `connect_timeout`, and `headers` — a sandbox host for one account, a slower region for another:

```php
// config/laraflow.php
'integrations' => [
    'crm' => [
        'connector' => \App\Integrations\CrmConnector::class,
        'connections' => [
            'default' => [
                'credentials' => ['token' => env('CRM_TOKEN')],
            ],
            'sandbox' => [
                'base_url' => 'https://sandbox.crm.example/api/v2',
                'timeout' => 60,
                'credentials' => ['token' => env('CRM_SANDBOX_TOKEN')],
            ],
        ],
    ],
],
```

A package registers from its own service provider instead, with the class or a closure receiving the `Connection`:

```php
app(LaraflowManager::class)->register('crm', CrmConnector::class);
```

Requests go through an `HttpTransport`, Laravel's HTTP client by default — so `Http::fake()` works in your tests and the client's middleware applies. Bind another implementation to replace it for every connector, or override `transport()` on one. A connector that is not HTTP at all implements `Laraflow\Integration\Connector` directly: `name()` and `request()`.

Steps can then type-hint `LaraflowManager` and call `->connector('crm')`.

## Current limitations

Laraflow is honest about what it does not yet do. These are the known gaps as of Milestone 4; they are the focus of the next milestones in [ROADMAP.md](ROADMAP.md).

- **Retries and retention are off by default.** `retry.max_attempts` ships as 1 and every `retention.*` bound as null; nothing is retried and nothing is removed until you opt in. With retries on and retention off, the attempt-history table grows without bound.
- **Timeouts do not interrupt running PHP.** A step past its deadline is *classified* as timed out at the recovery boundary and charged to its retry budget, but a genuinely hung process is bounded only by the queue worker's timeout. Cancelling a running execution is likewise cooperative — the step in flight finishes first.
- **Side effects are at-least-once — and retries repeat them on purpose.** If a worker dies between a step's external call and its checkpoint, recovery runs the step again; a retry policy re-runs failing steps deliberately. Use `$context->idempotencyKey()` to deduplicate; the package cannot make arbitrary side effects exactly-once.
- **No workflow versioning.** If a workflow's step list changes, in-flight executions of the old definition can only be finished by the old code; `execute()` rejects a mismatched definition rather than guessing.
- **No per-workflow aggregates.** The query API filters and counts within one scope; it has no `GROUP BY`. `laraflow:summary --workflow=` scopes the same numbers to one workflow, and a dashboard needing a breakdown should count per workflow it knows about.
- **No write-time payload redaction.** Input seeds the context on every `execute()` and context is restored on recovery, so rewriting either at write time would corrupt re-runs. Store identifiers rather than secrets; scrub payloads after the fact with retention; redact error messages with `errors.redactor`.
- **Metrics are events, not an integration.** Durations and queue lag are on the lifecycle events; turning them into Prometheus, StatsD, or Pulse metrics is a listener you write. Recipes are in [docs/inspection-and-observability.md](docs/inspection-and-observability.md).
- **One bundled connector.** The GitHub connector is a prototype. Timeouts, retries, rate limiting, and pagination are not configurable yet (Milestone 5).
- **Schema is not frozen.** Migrations may change in place until the first tagged release; there is no upgrade path between development snapshots (Milestone 6).

## Design principles

**Recovery is part of the model.** Persisting a status is only the beginning. A workflow runtime must define what happens after a process disappears, how completed work is recognised, and what data is needed to continue.

**Be honest about side effects.** A framework cannot generally guarantee that an external API call happened exactly once if a worker crashes before recording its result. Laraflow makes retry and recovery behaviour explicit and gives developers tools to make repeatable operations safe.

**Keep workflows in application code.** Workflows should be versionable, reviewable, and testable with the rest of a Laravel application. No hosted service or visual designer is required to understand what an integration does.

**Grow from a reliable core.** Linear workflows and reliable recovery come before complex branching, large connector catalogs, or a dashboard.

## Compatibility and support

Laraflow follows [semantic versioning](https://semver.org) once tagged. Until `1.0`, minor versions may contain breaking changes and will say so in [CHANGELOG.md](CHANGELOG.md). The supported PHP, Laravel, and database versions, and the policy for dropping them, are in [docs/compatibility.md](docs/compatibility.md). Which classes are public, experimental, or internal is in [docs/public-api.md](docs/public-api.md).

Security issues should be reported privately as described in [SECURITY.md](SECURITY.md).

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, testing, and the kind of changes most likely to be accepted at this stage. The development sequence is in [ROADMAP.md](ROADMAP.md).

## License

Laraflow is open-sourced software licensed under the [MIT license](LICENSE).
