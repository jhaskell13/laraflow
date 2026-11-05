# Using Laraflow

A tour of everything a workflow can do once the first one runs — each section a summary of the contract document it links to.

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

Timeouts are deadlines, not preemption: Laraflow does not interrupt running PHP. A step reads its own deadline from the context (`$context->remainingSeconds()`) and bounds its own work; an attempt whose worker died *past* its deadline is charged to the retry budget as a timeout, while one that died before it re-runs for free. The full contract — schedules, the three-way timeout distinction, the two attempt bounds, cancellation semantics, and error redaction — is in [docs/retry-and-failure-policy.md](retry-and-failure-policy.md).

## Conditions, waits, and sub-workflows

The plan stays a linear list of steps, but a step can decide not to run, park the execution until something happens, or run a whole other workflow. The semantics — what persists, what happens on recovery, what the events say — are one document: [docs/advanced-workflows.md](advanced-workflows.md).

### Conditional steps

A step that implements `ConditionalStep` is asked, from the context, whether it has anything to do:

```php
use Laraflow\Workflow\ConditionalStep;

class NotifyAccountManager implements Step, ConditionalStep
{
    public function shouldRun(WorkflowContext $context): bool
    {
        return $context->get('deal_size', 0) >= 50_000;
    }

    public function handle(WorkflowContext $context): mixed { /* ... */ }
}
```

A false answer durably marks the step `skipped` — no attempt counted, nothing written to the context — and recovery replays the recorded decision rather than asking again. Conditions must be pure functions of the context: same context, same answer.

### Delays, signals, and human approval

A step pauses the execution by returning a `Wait` instead of an output. Everything it did first is checkpointed, the claim is released, and **resuming completes the step** — the method body never resumes mid-function, so work after the wait belongs in the next step.

```php
use Laraflow\Workflow\Wait;

public function handle(WorkflowContext $context): mixed
{
    $this->postApprovalRequest($context);              // runs once

    return Wait::forSignal('manager-approval')         // park until the signal
        ->timeoutAfterSeconds(7 * 86_400);             // or fail after a week
}
```

`Wait::untilSeconds(600)` is the timed form — resume in ten minutes, no signal involved. The application delivers a signal through the runner, from a controller, a webhook, wherever the approval arrives:

```php
app(WorkflowRunner::class)->signal($executionId, 'manager-approval', ['approved_by' => $user->email]);
```

The payload becomes the waiting step's output and lands in the context under the signal's name, so later steps read it like any other step's contribution. Recording is not delivery: the queue fast path handles waits with a known instant, and `laraflow:recover` is the contractual pickup for everything — schedule it, exactly as you already do for retries. A signal wait past its deadline fails the step with `WaitTimedOutException` under the step's own retry policy, and retrying an approval step re-posts the request.

### Sub-workflows

A step extending `SubWorkflowStep` runs another workflow as a linked child execution and parks until it finishes:

```php
use Laraflow\Workflow\SubWorkflowStep;

class SyncSubsidiary extends SubWorkflowStep
{
    public function workflow(WorkflowContext $context): Workflow
    {
        return new SyncCustomerWorkflow;
    }

    public function input(WorkflowContext $context): array
    {
        return ['customer_id' => $context->get('subsidiary_id')];
    }
}
```

The child inherits the parent's correlation and tenant identifiers, runs like any other execution, and its outcome — identity and status, never its context — becomes the parent step's output. A failed child fails the parent step under the step's retry policy, and a retry resumes the *same* child from its own checkpoints rather than starting over. Cancelling a parent cascades to its unfinished children; nesting is bounded by `laraflow.workflows.max_depth`; and the parent checks on its child every `stale_after` seconds, so a lost signal or a lost dispatch heals instead of hanging.

## Parallelism and compensation

Two capabilities that run a workflow wider and unwind it when it fails. Both are composition and step rows rather than new machinery, and both are specified in the same document as the rest: [docs/advanced-workflows.md](advanced-workflows.md).

### Fan-out and fan-in

A step extending `FanOutStep` runs many workflows in parallel as child executions and parks until every one of them has finished:

```php
use Laraflow\Workflow\{FanOut, FanOutStep};

class NotifySubscribers extends FanOutStep
{
    public function members(WorkflowContext $context): iterable
    {
        foreach ($context->get('subscriber_ids') as $id) {
            yield new FanOut(new NotifySubscriber, ['subscriber_id' => $id]);
        }
    }
}
```

A member is a child execution, with the same linkage, depth bound, cancellation cascade, and retention rules a sub-workflow's child has — everything the section above says about a child is true of a member. **The plan is untouched:** `NotifySubscribers` is one class at one position and `steps()` still returns a flat list.

`members()` is read **once** and what it yielded is recorded on the step row before any member is created, so a worker that dies part-way through leaves a remainder the next claim computes rather than a group it builds twice. Members are delivered as they are created; the step then parks on a reserved join signal, which the last member to rest records. The join heartbeats on `stale_after` like a single child's park, and when it wakes it asks for the group's progress in **one indexed query** — so deciding about a thousand members costs exactly what deciding about three does. A lost join signal or a lost member dispatch heals through `laraflow:recover` with no new scan.

**The join waits for every member** (`JoinPolicy::WaitAll`, the only policy that ships). All completed, and the step completes with a summary of the group — total, completed, and the first member ids, never a member's context. Anything else, and the step fails with `FanOutFailedException` naming how many ended in each state — an ordinary step failure under the step's own retry policy. **A retry resumes the members that did not complete** rather than creating a second group; a cancelled member cannot be resumed, so a group containing one reports the same outcome until an operator intervenes. An empty `members()` completes the step at once rather than parking.

Width is bounded by `laraflow.workflows.max_members` (default 1000), refused with `FanOutTooWideException` before anything is created, and depth by `max_depth` as before. Laraflow does not aggregate member outputs — the join describes the group, and reduction is the step that follows.

### Compensation

A step implementing `CompensatableStep` declares how to undo itself. When the execution fails terminally, Laraflow runs those declarations in reverse over the steps that **completed**:

```php
use Laraflow\Workflow\{Compensation, CompensatableStep};

class ChargeCard implements Step, CompensatableStep
{
    public function handle(WorkflowContext $context): mixed
    {
        return ['charge_id' => $this->payments->charge(...)];
    }

    public function compensate(Compensation $compensation): void
    {
        $this->payments->refund($compensation->output()['charge_id']);
    }
}
```

**Opt-in by declaring it.** A workflow whose steps none of them do behaves exactly as it did before, with no policy to set and no rows appended. The undo receives the step's own recorded output — the charge id a refund needs — the context as it now stands, and the failure being compensated.

A pass runs when the failing step's retries are exhausted (and, under `FailurePolicy::Continue`, after the remaining steps have run). It skips the step that failed: a step that threw is in a state only the application knows. The execution's status is `compensating` while the pass runs, and its resting state is `failed` either way — compensation changes what was left behind, not whether the execution failed.

**Each compensation is an ordinary step row**, appended after the plan and linked to the row it undoes, so it has its own attempts, retries, timeout, and attempt history, and appears in `laraflow:show` and the dashboard like any other step. The saga is inspectable because it *is* steps. An undo is retried under **the compensated step's own policy**, and the pass **stops** at the first undo whose retries are exhausted rather than reaching past it to undo earlier work the failed one may have depended on; the rows then record which undos ran, which failed, and which were never reached. An interrupted pass is resumed by the next claim from those same rows.

Compensations must be idempotent for the reason steps must, and `$compensation->context()->remember()` is available inside `compensate()` and is the answer. Neither a fan-out nor a sub-workflow compensates its children: members are separate executions with their own sagas, and reaching into them from the parent would be acting at a distance on records it does not own. A step that wants its members undone says so in its own `compensate()`, using the ids its output recorded.

## Changing a deployed workflow

The full step plan is persisted when an execution is created, and the runner refuses to run an execution whose persisted plan no longer matches the workflow's `steps()`. That refusal is deliberate — durable state must never be quietly reinterpreted by changed code — but it means **a deploy that edits a workflow strands every in-flight execution of it** with `WorkflowDefinitionMismatchException`.

Versioning is the one way past it, and it is opt-in per workflow. Bump `version()` and say what changed, keyed by the version the migration moves *from*:

```php
use Laraflow\Workflow\PlanDraft;
use Laraflow\Workflow\Workflow;

class FulfilOrder extends Workflow
{
    public function steps(): array
    {
        return [ReserveInventory::class, ChargePayment::class, RecordTax::class, SendReceipt::class];
    }

    public function version(): int
    {
        return 2;
    }

    public function planMigrations(): array
    {
        return [
            1 => fn (PlanDraft $plan) => $plan->insertAfter(ChargePayment::class, RecordTax::class),
        ];
    }
}
```

An execution persisted under version 1 is carried forward the next time it is claimed: the declared hops run in turn, under the existing claim, in one transaction, before any step runs. Nothing new happens at recovery time and no new state is introduced.

A migration may rewrite **only the part of the plan the execution has not reached**. A `pending` step row has no output, no attempt history, and nothing in the context, so it can be renumbered, removed, or replaced; every other row is the record of something that happened. Reaching further back is refused with `PlanMigrationRefusedException`, naming the step and the status that froze it. Leave `version()` alone and a changed `steps()` still strands its executions, exactly as before — silence means refuse.

The full contract — what is refused and why, how several versions behind are chained, and how to test a migration — is in [docs/workflow-versioning.md](workflow-versioning.md). Deploy guidance is in [docs/upgrading.md](upgrading.md).

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

Laraflow fires events for dispatch, execution start, each step's start/completion/skip/wait/failure, scheduled retries, recorded signals, and execution completion/failure/cancellation. Each fires **after** the durable write it describes, and none of them carry workflow input, step output, or context — only identifiers and metadata.

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

Queueing, retry interaction, recovery policy, and event payload safety are specified in full in [docs/queue-and-recovery.md](queue-and-recovery.md).

## Scheduled workflows

A workflow declares its cadence on the class, where it is reviewed and versioned with the steps it runs; the configuration lists which classes are live:

```php
class NightlyReconciliation extends Workflow
{
    public function schedule(): ?Schedule
    {
        return Schedule::cron('0 2 * * *')
            ->timezone('Europe/London')
            ->input(fn (DateTimeImmutable $tick): array => [
                'day' => $tick->modify('-1 day')->format('Y-m-d'),
            ]);
    }

    public function steps(): array { /* … */ }
}
```

```php
// config/laraflow.php
'schedules' => ['workflows' => [App\Workflows\NightlyReconciliation::class]],
```

```php
// routes/console.php — every minute, like laraflow:recover
Schedule::command('laraflow:schedule')->everyMinute();
```

A tick is a row with a unique `(schedule, instant)` index, claimed in the same transaction that creates the execution — so **exactly one execution per tick, however many schedulers race it**, with no lock to lose and no leader to elect. Missed ticks follow a declared policy: `skip` (the default) runs only the latest due tick and counts the gap on its row; `catch_up` replays them oldest first, each execution told *its* tick, bounded per run and by a window. `withoutOverlapping()` declines to run beside the previous tick's unfinished execution and records what it waited on; `tenant()` stamps every execution the schedule starts. `laraflow:schedules` lists every schedule with its last tick, lag, and next due instant — and exits non-zero when one is orphaned or stalled, so the same cron that runs your health checks can watch the scheduler itself. The full contract, missed-tick arithmetic and DST behaviour included, is [docs/schedules.md](schedules.md).

## Triggers: signals over HTTP

A workflow parked on `Wait::forSignal('manager-approval')` is resumed from outside — a button, a Slack interaction, a provider's webhook. Triggers are that controller, shipped once and off unless mounted:

```php
Triggers::routes(); // POST {path}/executions/{id}/signals/{signal}
```

Every request to the signed endpoint is verified **before anything is read**: HMAC-SHA256 over the timestamp, execution id, signal name, and body, under `LARAFLOW_TRIGGERS_KEY` (rotation via `LARAFLOW_TRIGGERS_PREVIOUS_KEYS`), within a clock tolerance. Every verification failure is the same `401` — a caller learns nothing from the answer, and there is no `local`-environment bypass. A verified request records the signal through the same `signal()` the console and dashboard use and answers in its words: `202 recorded` with what became of delivery, `404` only after verification, `409` when nothing is waiting on that name. `TriggerSignature::sign()` produces the headers for PHP callers; [docs/triggers.md](triggers.md) gives the three-line `openssl` recipe for everyone else, and `Triggers::register()` mounts named endpoints for providers that sign their own webhooks (Stripe, GitHub), behind your verifier and your mapping of their event to one signal.

## Tenant ceilings

Multi-tenant applications already stamp executions with `tenantId`; ceilings make it load-bearing. `laraflow.tenants.max_concurrent` bounds how many of a tenant's executions may hold a worker at once, `max_claims_per_minute` bounds churn, `overrides` adjusts either per tenant — or bind your own `TenantPolicy` and answer from wherever a tenant's plan lives. Enforcement happens at the claim, the one gate every run passes, so a fresh dispatch, a retry, a resumed wait, and recovery are all bounded by one check. A claim a ceiling turns away is **deferred, not failed**: the status does not move, no attempt is recorded, the row says when delivery is next tried, and the queue job re-pushes itself for that instant without spending a try. The fairness rule is written down in [docs/tenancy.md](tenancy.md): a tenant whose ceiling is below the worker count can never hold every worker, so it cannot starve the rest — held by a test where a tenfold flood buys zero extra delay.

## Inspecting and correlating executions

The stable way to read executions is the `Laraflow\Workflow\ExecutionReader` contract, the read-only half of `ExecutionRepository`, which returns plain value objects. Everything the console shows, it reads through this same contract — no command touches an Eloquent model, and a test keeps it that way.

```php
use Laraflow\Workflow\ExecutionReader;

$repository = app(ExecutionReader::class);

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

From the terminal, `laraflow:list`, `laraflow:show`, and `laraflow:summary` read the same contract; `laraflow:children` lists a sub-workflow's child or a fan-out's members; `laraflow:signal` records a signal (and with `--deliver` hands the execution on); `laraflow:schedules` lists schedules and their health; and `laraflow:cancel` and `laraflow:execute` without an id act on every execution a filter matches — bulk cancel and re-run, oldest first, bounded by `--limit`, with `--dry-run`. Every operating command takes `--json` and prints one document with the human form's exit code. The commands and the JSON shapes are in [docs/inspection-and-observability.md](inspection-and-observability.md#the-console).

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

Events carry `durationMs` (steps and executions) and `queueLagSeconds` (how long an execution waited for a worker, measured from when it became available rather than when it was dispatched, so a retry's own backoff is never counted). A listener turns those into whatever metrics your stack wants; Laraflow ships no vendor integration on purpose. The full contract, including how to correlate with requests, jobs, tenants, and provider calls, is in [docs/inspection-and-observability.md](inspection-and-observability.md).

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

The command exits non-zero when no bound is configured — a scheduled prune that silently does nothing looks exactly like one that is working. The contract, including the trade-off in pruning failed executions' history and an archive-before-prune recipe, is in [docs/retention.md](retention.md).

## The dashboard

Everything the console shows, in a browser: a filterable list of executions, one execution in full with its steps, attempts, waits, and remembered keys, and an overview with counts by status and what needs attention. It reads the same public `ExecutionReader` the commands read — a test holds it there — so it can show nothing your own application could not.

It is **off until you mount it**, and **denied until you say who may**:

```php
// routes/web.php
use Laraflow\Dashboard\Dashboard;

Dashboard::routes();                 // answers at /laraflow

// AppServiceProvider::boot()
Dashboard::auth(fn ($request) => $request->user()?->can('operate-workflows') ?? false);
```

Without `routes()` there are no routes, no assets, and nothing in `route:list`. Without `auth()` the only thing allowed is the `local` environment, or a `viewLaraflow` gate you define — so a dashboard mounted in production without a rule denies everyone, which is the failure that gets noticed rather than the one that leaks.

An operator can also act: execute or recover a failed execution, cancel one, signal a waiting step, and start a workflow — each the browser form of the console command, through the same service, behind your own middleware's CSRF protection. Mount it `read_only` and those routes are never registered at all.

```php
// config/laraflow.php
'dashboard' => [
    'path' => env('LARAFLOW_DASHBOARD_PATH', 'laraflow'),
    'middleware' => ['web'],
    'read_only' => env('LARAFLOW_DASHBOARD_READ_ONLY', false),
    'per_page' => env('LARAFLOW_DASHBOARD_PER_PAGE', 25),
    'refresh' => env('LARAFLOW_DASHBOARD_REFRESH', 10),
],
```

There is **no build step**: one hand-written stylesheet and one script, both served by the package, nothing published, no `npm`, no CDN. Filters apply as you type and pages refresh themselves; with JavaScript off, every page is a full render and the filter form is an ordinary one with a button. The dashboard needs `illuminate/routing`, `illuminate/view`, and `illuminate/session`, which the package *suggests* rather than requires — any Laravel application has all three, and the mount says so by name if it does not.

The contract, including what the dashboard deliberately does not do, is [docs/dashboard.md](dashboard.md).
