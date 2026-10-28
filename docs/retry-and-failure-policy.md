# Retry, timeout, and failure policy

This document is the contract for how Laraflow retries failed steps, schedules and bounds those retries, classifies timeouts, cancels executions, and keeps sensitive error data out of what it retains. It builds on [docs/execution-lifecycle.md](execution-lifecycle.md) (the durability contract) and [docs/queue-and-recovery.md](queue-and-recovery.md) (queueing and recovery).

Everything stated here is exercised by `tests/Feature/Workflow/RetrySchedulingTest.php`, `StepTimeoutTest.php`, `CancellationTest.php`, `FailurePolicyTest.php`, `ErrorRedactionTest.php`, `tests/Feature/Queue/QueueRetryTest.php`, and `tests/Feature/Console/RetryConsoleTest.php`. If the code and this document disagree, one of them has a bug.

## The core idea: a retry is durable state, not a loop

Laraflow never sleeps a worker to wait out a backoff. When a step fails and its policy allows another attempt, the runner persists everything about that retry — the failed attempt in history, the step and execution in a new **`retrying`** state, the due time in `next_retry_at` — releases its claim, and returns. Delivery happens later, by one of two mechanisms ([below](#how-a-due-retry-is-delivered)).

What that buys you:

- **A scheduled retry is not a failure.** `execute()` returns the `retrying` handle instead of throwing; a queue job *succeeds*; nothing lands in `failed_jobs`; `WorkflowExecutionFailed` does not fire. Alerting built on failures stays meaningful once retries are on — an execution that will heal itself does not page anyone.
- **A retry survives anything.** Worker death, deploys, a lost queue — the schedule is a database row, and `laraflow:recover` finds it.
- **`execute()` throws only on terminal failure** — the policy refused the exception, or the budget is spent. Then the Milestone 1/2 contract applies unchanged: the failure is persisted and the step's original exception is rethrown.

**Retries are off by default.** `laraflow.retry.max_attempts` ships as `1` (the first attempt *is* attempt 1), consistent with every other retry authority in Laraflow — `queue.tries` is 1, `recovery.pending_after` is null. Nothing re-runs your side effects until you ask.

> **Synchronous callers:** with retries enabled, `run()`/`execute()` can return a *non-terminal* `retrying` execution rather than throwing. Code relying on "failure throws" should check `$execution->isRetrying()`, or keep `max_attempts` at 1 for workflows it runs in-process.

## Declaring a policy

Resolution is **per step**: the step's own policy, then its workflow's default, then configuration. One slow step can declare a longer budget than its siblings.

```php
use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\StepPolicy;

class SyncWithCrm implements Step, HasStepPolicy
{
    public function stepPolicy(): StepPolicy
    {
        return new StepPolicy(
            maxAttempts: 5,              // total attempts, including the first
            baseDelaySeconds: 10,        // delay after the first failure
            multiplier: 2.0,             // exponential backoff; 1.0 = fixed
            maxDelaySeconds: 300,        // hard cap on any delay
            jitter: 0.2,                 // ±20% spread
            retryOn: [TransportException::class],  // empty = retry everything
            dontRetryOn: [AuthException::class],   // wins over retryOn
            timeoutSeconds: 30,          // per-attempt deadline; null = none
        );
    }

    public function handle(WorkflowContext $context): mixed { /* ... */ }
}
```

A workflow-wide default for steps that declare nothing:

```php
class SyncCustomerWorkflow extends Workflow
{
    public function defaultStepPolicy(): ?StepPolicy
    {
        return new StepPolicy(maxAttempts: 3);
    }
}
```

`StepPolicy` validates itself at construction — `maxAttempts: 0`, a multiplier below 1.0, jitter outside 0–1, a zero timeout — so a misconfigured policy fails at the definition site rather than at 3am.

### The schedule

Delay after a failure on attempt *n* is `baseDelaySeconds × multiplier^(n−1)`, capped at `maxDelaySeconds`, then spread within a ±(`jitter` × delay) window.

**Jitter is deterministic**, derived by hashing `"{executionId}:{stepPosition}"` with the attempt number rather than drawn from randomness. One logical retry always lands on the same instant — reproducible in a test, explainable in production — while different executions still spread apart.

**An exception may know better.** When the step's exception implements `Laraflow\Exceptions\SuggestsRetryDelay` — a connector's `RateLimitedException` carrying the provider's `Retry-After`, Laraflow's own `ThrottledException`, or any application exception that chooses to — the retry is scheduled no sooner than the suggestion, up to `retry.max_suggested_delay` (default 3600 seconds), and never sooner than the policy's own delay. The policy's backoff protects the dependency; the suggestion is the dependency protecting itself, and it is believed within a bound an operator chose. The attempt still counts against the budget.

When the jitter window would cross the ceiling it **slides down to fit** rather than being clipped against it. Clipping would land every upward-jittered execution on exactly `max_delay` — roughly half of them, bunched on one instant, in precisely the situation jitter exists to prevent (many executions that have all backed off to the ceiling together). `max_delay` remains a genuine maximum.

### What is never retried

Regardless of policy, these fail terminally on first occurrence: `UnserializableValueException`, `PayloadTooLargeException`, `WorkflowDefinitionMismatchException`, `InvalidWorkflowDefinitionException`, `InvalidStateTransitionException`, `ClaimLostException`. Each is a deterministic definition/serialization fault or a lost-ownership signal — another attempt could only repeat the outcome, and scheduling one would fill the queue with work that cannot succeed.

### Attempts count every start

A step's `attempts` increments on every `running` transition, including attempts ended by worker death. The budget is therefore a budget on *starts*: a step that keeps killing its worker exhausts it even though it never "failed" in the thrown-exception sense. That is deliberate — see [timeouts](#timeouts) for how worker loss and overruns are told apart.

## The retrying state

```
running ──→ retrying ──→ running     (next attempt, once due)
```

A `retrying` execution (and its `retrying` step) carries `next_retry_at` plus the failed attempt's error and exception class, so operators can see *why* it will retry and *when*. The claim is released; nobody owns it while it waits.

Claiming is **gated by the schedule**: `execute()` before `next_retry_at` throws `RetryNotDueException` naming the due time. Backoff exists to protect the failing dependency, so honouring an eager claim would defeat it. The deliberate override is `laraflow:execute <id> --now`, or `execute($id, $workflow, ignoreRetrySchedule: true)`.

## How a due retry is delivered

Two mechanisms, one guarantee:

1. **The queue fast path** (`laraflow.retry.dispatch_delayed`, default on). When an execution running on a queue worker schedules a retry, the job queues a delayed `ExecuteWorkflowJob` for `next_retry_at`. Punctual, and *never the guarantee* — a failed delayed dispatch is a warning log, not a failed job, because the retry is already durable. It is **always skipped on the `sync` driver**, which ignores `delay()` and would turn backoff into immediate recursion: the whole budget burned in one stack.
2. **The recovery scan** (`laraflow:recover`), which finds `retrying` executions with `next_retry_at <= now` and dispatches them. **This is the contractual pickup**: it needs no queue, and it catches anything the fast path loses. If you use retries, schedule it (you should be scheduling it anyway):

```php
Schedule::command('laraflow:recover')->everyFiveMinutes()->withoutOverlapping();
```

Duplicates stay safe exactly as in Milestone 2 — the claim decides. An early delivery (a duplicate, an imprecise driver, an operator who already ran it) hits the schedule gate and is logged as a no-op, so `failed_jobs` stays reserved for terminal problems and the queue owes no delivery precision.

### Retry events

| Event | Fired when |
|---|---|
| `WorkflowStepFailed` | Every failed attempt, retryable or not. `attempt` distinguishes them. |
| `WorkflowStepRetryScheduled` | A retry was durably scheduled. Carries `attempt`, `maxAttempts`, `errorClass`, `error`, `nextRetryAt`, `delaySeconds`. |
| `WorkflowExecutionFailed` | **Terminal failure only** — never for a scheduled retry. |
| `WorkflowExecutionRetryScheduled` | Recovery selected an execution; `reason` is one of `stale_claim`, `step_timeout`, `retry_due`, `never_dispatched`. |
| `WorkflowExecutionCancelled` | The execution was durably cancelled. Fires once. |

## Timeouts

Laraflow **does not preempt running PHP**. No signals, no partial state, no pretending a `handle()` call can be torn out of the interpreter. `timeoutSeconds` instead works in two honest halves.

**Before the attempt runs**, its deadline is persisted (`timeout_at = started_at + timeoutSeconds`) and exposed to the step:

```php
public function handle(WorkflowContext $context): mixed
{
    $response = Http::timeout($context->remainingSeconds() ?? 30)
        ->get('https://api.example.com/customers');

    // $context->deadline() is the DateTimeImmutable, when you need it.
}
```

A step that bounds itself never times out from Laraflow's point of view. The deadline is written *before* `handle()` executes because a dying worker writes nothing — it has to already be on the row for a recovering worker to judge the dead attempt against it.

**At the recovery boundary**, an interrupted attempt is classified. This is the three-way distinction:

| Situation | Classification | Consequence |
|---|---|---|
| The step threw | **ordinary failure** | Retry policy applies. |
| Step `running`, claim stale, deadline **not** passed (or none) | **worker loss** | Free re-run — even under a one-attempt policy, because worker death is not the step's fault. The dead attempt is closed out in history as `interrupted` (no duration: nothing recorded an end). |
| Step `running`, claim stale, deadline passed | **timeout** | Recorded as `timed_out` with a `StepTimeoutException` naming the missed deadline, and **charged to the retry policy** like any thrown failure: backoff if budget remains, terminal otherwise. The step is not re-run during classification. |

A running step past its deadline whose claim is still **fresh** is a hung-but-alive worker. Laraflow reports it — `laraflow:show` marks the step `(overdue)`, and a later scan that finds the claim stale reports reason `step timeout` — but never acts against a live claim. The hung process itself is bounded only by `queue.timeout`, which is why this ordering matters:

```
timeout.step  <  queue.timeout  <  execution.stale_after
```

The shipped defaults respect it, and `ConfigTest` asserts they do.

## Attempt history

Every finished attempt is appended to `laraflow_step_attempts`: outcome (`completed`, `failed`, `timed_out`, `interrupted`), error and class, start/end, duration, and the retry it scheduled. Rows are written **in the same transaction as the step state change they describe**, so history can never disagree with the step row — a checkpoint that loses its claim rolls back the step write, the context write, and the attempt row together. A unique `(step_execution_id, attempt)` index means a duplicate delivery cannot double-count an attempt the retry budget depends on.

A retry therefore no longer erases the diagnostics of the attempt it replaces:

```bash
php artisan laraflow:show 42 --attempts
```

```
| Step    | Attempt | Outcome   | Started             | Duration | Scheduled retry     | Error            |
| StepOne | 1       | completed | 2026-10-23 22:53:56 | 12 ms    |                     |                  |
| StepTwo | 1       | failed    | 2026-10-23 22:53:56 | 840 ms   | 2026-10-23 22:55:56 | step two blew up |
```

History is append-only, so it is the table that grows under retry policies: one row per finished attempt, `executions × steps × attempts`. It is pruned by `laraflow:prune` under `laraflow.retention.prune_attempts_after_days`, which is null by default — see [retention.md](retention.md). Pruning removes only this table; the step row keeps the latest attempt's status, error, and counter, so a pruned execution re-executes exactly as an unpruned one would.

## Cancellation

`WorkflowRunner::cancel($id, ?$reason)` — or `php artisan laraflow:cancel {id} {--reason=}`:

| State | Behaviour |
|---|---|
| `pending`, `failed`, `retrying` | Cancelled immediately by compare-and-set — the same predicate claiming uses, so it cannot race a worker: whichever `UPDATE` lands first, the other is refused. Any scheduled retry is voided (`next_retry_at` cleared), so the scan will not resurrect it. |
| `running` | **Cooperative.** `cancel_requested_at` is set and the execution keeps running; the owning worker notices at its next step boundary, finishes and checkpoints the step in flight, then cancels and releases the claim. |
| `completed` | `ExecutionNotCancellableException`. |
| `cancelled` | Idempotent no-op — the original cancellation and its event stand. |
| unknown id | `ExecutionNotFoundException`. |

Laraflow never rewrites durable state underneath a live worker, and **cannot interrupt a step mid-flight** — bounding that belongs to the step's own deadline and the queue worker's timeout. Three honest consequences:

- A cancellation requested during the *final* step does not prevent completion: there is no later boundary at which to stop.
- A hung step keeps running until its worker is killed.
- **A request outlives the worker it was meant for.** Nothing clears `cancel_requested_at`, so if that worker dies before reaching a boundary, whichever worker recovers the execution honours the request instead — before running any step. A cancellation is not lost because a process was. There is no "uncancel": the request stands until some worker acts on it.

`cancelled` is terminal: `claim()`, `execute()`, `dispatchExecution()`, and every recovery path refuse it. Step rows are left truthful — completed steps stay completed, pending steps stay pending; nothing is rewritten to pretend work didn't happen, and work completed before the cancellation is kept in the context.

Manual **retry** is unchanged from Milestone 2: `laraflow:execute <id>`, now with `--now` for a scheduled retry. It remains deliberately unbounded — the operator override.

## Transport retries are the inner loop

A connector may repeat one wire request *inside* a single step attempt — for a dropped connection or a momentary 503 — under a `TransportPolicy` (`laraflow.http.retries`, or a connection's `retries`). This is a different thing from the step's retry policy, and the two are kept from multiplying by construction:

| | Transport retry (inner) | Step retry (outer) |
|---|---|---|
| Ships | **Off** (`max_attempts` = 1) | **Off** (`max_attempts` = 1) |
| Holds the worker | Yes — measured in milliseconds | No — durable `retrying` state, worker released |
| Retries | 429, 500, 502, 503, 504, and no-response failures | Whatever the step's policy says |
| Honours `Retry-After` | Up to `max_retry_after` seconds (5); a longer one **returns the response** | Yes, via the exception's suggested delay, up to `retry.max_suggested_delay` |
| Repeats a non-idempotent request | Only on 429 (never processed), or when the caller marked it `asIdempotent()` | Always — the step runs again; use `idempotencyKey()` |

Rate limiting has a proactive side too: a connection's `rate_limit` (`max` per `per` seconds) is a ceiling Laraflow holds itself to, counted in the cache so every worker shares it. A wait up to `max_wait` seconds is served in-process; a longer one fails the request with `ThrottledException` *before anything reaches the provider*, and that exception suggests the wait — so with a step policy the execution simply comes back when a slot is due. Without one it fails, which is the honest outcome: a throttle without a retry turns bursts into failures, and the documentation says so rather than the worker sleeping through them.

So a provider that says "wait 600 seconds" is never waited for in-process. The transport returns the 429, the step calls `throw()` (or the connection sets `throw_on_error`) and fails with `RateLimitedException`, and the step policy schedules a durable retry for when the provider said. A step that enables transport retries without a step policy gets a few quick re-tries and then a normal failure; one that enables both gets the quick re-tries inside each durable attempt, bounded on both axes.

Keep `http.timeout × retries.max_attempts` below the step's deadline, or the attempt will be classified as timed out while a retry is still waiting.

## Two bounds, two jobs

With retry policy in place there are two attempt-related bounds. They are different on purpose:

| Bound | Governs | Enforced by |
|---|---|---|
| `StepPolicy::maxAttempts` | **Failures** — thrown exceptions and timeouts | The runner, durably, via `attempts` |
| `laraflow.recovery.abandon_after_attempts` (default 3) | **Interruptions** — stale claims, lost dispatches; measured in step attempts and, for a fault before the first step, in claims that made no progress | `laraflow:recover`, which abandons rather than re-dispatching |

The due-retry scan is **exempt** from the abandonment bound: a scheduled retry's budget is its policy, and applying the interruption bound would strand every policy whose budget exceeds 3. Conversely the abandonment bound ignores retry policies — it is crash-loop protection, and handing stuck work to an operator is the safe default.

**If your retry budgets exceed 3 and your steps also risk crashing workers, raise `abandon_after_attempts` to match.**

> Renamed in Milestone 3: `recovery.max_attempts` → `recovery.abandon_after_attempts`, `--max-attempts` → `--abandon-after`. With `retry.max_attempts` now existing as the failure budget, two same-named knobs with different scopes invited exactly the wrong assumption.

## Keeping secrets out of what Laraflow retains

Step exception messages are persisted (execution, step, attempt history), logged, and carried in failure events. If they may contain secrets or personal data, configure a redactor — the single chokepoint every retained message passes through:

```php
// config/laraflow.php
'errors' => ['redactor' => \App\Support\StripSecretsRedactor::class],
```

One sink is outside the chokepoint, by design but worth knowing: when the last queue delivery of an execution fails, `ExecuteWorkflowJob` rethrows the step exception so Laravel's failed-job tooling sees it — and **Laravel persists that exception's raw message and stack trace to `failed_jobs` itself**, unredacted. The redactor governs every sink Laraflow writes; it cannot govern that one. If step exceptions may carry secrets, prune or secure `failed_jobs` accordingly.

```php
use Laraflow\Support\ErrorRedactor;

class StripSecretsRedactor implements ErrorRedactor
{
    public function redact(Throwable $exception, string $message): string
    {
        return preg_replace('/tok_[A-Za-z0-9]+/', '[redacted]', $message) ?? '[redacted]';
    }
}
```

- The exception **your application catches is untouched** — redaction governs what Laraflow keeps, not what your code sees.
- A redactor must never throw: it runs on the failure path, where an exception from it would bury the diagnosis it exists to clean up.
- A misconfigured class name fails loudly at resolution. A redactor that silently failed to load would silently leak the data it was configured to strip.
- Console output is operator-facing and is **not** redacted.
- `events.include_error_messages` still independently controls whether events carry a message at all; the redactor controls what that message says.

## Continue-on-failure

By default a failed step stops the execution (`FailurePolicy::Stop`): later steps stay `pending` and run only on a successful re-execution. A workflow of **independent** steps can opt out:

```php
use Laraflow\Workflow\Policy\FailurePolicy;

class NotifyAllChannelsWorkflow extends Workflow
{
    public function failurePolicy(): FailurePolicy
    {
        return FailurePolicy::Continue;
    }
}
```

Under `Continue`, when a step fails **terminally** its failure is persisted and announced, and the remaining steps run anyway. The execution still ends `failed`, recording — and `execute()` rethrowing — the **first** step's exception, so "a failed execution throws" holds. Re-executing skips completed steps and re-runs only the failed ones.

Retry policy takes precedence: a *healable* failure schedules its retry and the run pauses there. Racing ahead past a step that will succeed in thirty seconds would manufacture the very dependent-step problem `Continue` exists to avoid.

Know what you are opting into: later steps see earlier checkpoints but **no output or context contribution from the failed step**. `Continue` is wrong for pipelines where step N+1 reads what step N wrote. A missing step class still stops the run under either policy — that is a deployment problem, not step independence. Compensation and saga patterns remain out of scope (roadmap Milestone 7).

## Configuration summary

```php
'retry' => [
    'max_attempts' => 1,           // 1 = retries off (the shipped default)
    'base_delay' => 5,             // seconds
    'multiplier' => 2.0,
    'max_delay' => 300,
    'jitter' => 0.2,               // fraction, deterministic
    'dispatch_delayed' => true,    // queue fast path for due retries
    'max_suggested_delay' => 3600, // cap on a SuggestsRetryDelay (Retry-After, throttle)
],
'timeout' => [
    'step' => null,                // default per-attempt deadline; null = none
],
'recovery' => [
    'abandon_after_attempts' => 3, // interruption bound (was max_attempts)
],
'errors' => [
    'redactor' => null,            // ErrorRedactor class name, or null
],
```

Every key tolerates a blank environment variable (`LARAFLOW_RETRY_MAX_ATTEMPTS=`) as "unset, use the default" — a stray blank line in `.env` must not become an application that cannot resolve the runner.

## Operational checklist

- Schedule `laraflow:recover`; it is the contractual delivery path for retries, not only a repair tool.
- Keep `timeout.step` < `queue.timeout` < `execution.stale_after`.
- If retry budgets exceed `recovery.abandon_after_attempts`, raise the latter.
- Alert on `WorkflowExecutionFailed`, not `WorkflowStepFailed` — the latter fires per attempt by design.
- Set `laraflow.retention.prune_attempts_after_days` and schedule `laraflow:prune`; with retries enabled the history table grows without it ([retention.md](retention.md)).
- Use `$context->idempotencyKey()` for external calls: retries make repeated side effects **more** likely, not less.
