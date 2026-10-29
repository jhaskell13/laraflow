# Advanced workflows: conditions, waits, and sub-workflows

> This document was written as the design contract for Milestone 7's capability work before any of it was built, and is now the shipped contract for all three: conditional steps, delays and wait states, and sub-workflow composition. The enforcing suites are `tests/Feature/Workflow/ConditionalStepTest.php`, `WaitStateTest.php`, and `SubWorkflowTest.php`. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.

## Shared principles

Every capability here obeys the contracts the core already keeps:

- **The plan stays linear.** `steps()` still returns an ordered list of step classes; the persisted plan and its validation are untouched. A condition decides whether a planned step runs — it never adds, removes, or reorders steps. Fan-out and graph branching remain out of scope.
- **Decisions are durable before they matter.** A skip, a wait, and a child-execution link are each persisted with the same claim-guarded writes as every step transition, so recovery replays recorded decisions rather than re-deciding them.
- **At-least-once is unchanged.** Nothing here narrows the crash windows in [execution-lifecycle.md](execution-lifecycle.md); waits and children add no new ones. The idempotency hooks apply as before.
- **Schema changes are additive**, arrive as new dated migrations under the freeze, and ride the harness in `tests/Feature/Database/UpgradePathTest.php`.
- **Events fire after the durable write they describe** and carry identifiers and metadata only — never payloads.
- **Nothing gains hidden authority.** No new retry authority, no automatic cascade an operator cannot see, every new scan bounded.

## Conditional steps

A step may decide, from the context, that it has nothing to do.

```php
use Laraflow\Workflow\ConditionalStep;
use Laraflow\Workflow\WorkflowContext;

class NotifyAccountManager implements Step, ConditionalStep
{
    public function shouldRun(WorkflowContext $context): bool
    {
        return $context->get('deal_size', 0) >= 50_000;
    }

    public function handle(WorkflowContext $context): mixed { /* ... */ }
}
```

**The condition is consulted at the start of every attempt** — after the cancellation check, before anything is marked running; a step whose previous failure was the condition itself throwing gets its real vote on re-execution. A false answer durably marks the step `skipped` in a claim-guarded write and the runner moves on. A true answer is not recorded; the step simply runs.

**Conditions must be pure functions of the context.** The contract: same context, same answer. The skip is persisted, so a *recorded* decision is never re-evaluated — but a worker that crashes after evaluating and before persisting will evaluate again on recovery, against the identical restored context. A condition that reads the clock, a random source, or external state breaks that window's determinism, and the document says so rather than pretending the window is closed.

**A skipped step consumes nothing and contributes nothing.** No attempt is counted, no attempt-history row is written, no output is recorded, and the context is untouched — later steps must tolerate the absent keys (`$context->has()`). Retry policy never sees a skip; a skip is not a failure. Under `FailurePolicy::Continue`, a false answer reached *after an earlier step has already failed* is not persisted at all — that context is missing the failed step's contribution, so it is not the context the purity premise promises; the step stays pending and the condition votes again when the failure is re-executed with the full context. Two more Continue rules hold for every step, conditional or not: a step that wrote to the context and then failed contributes nothing to the steps after it, in memory as on disk; and a step that failed terminally in an earlier claim is not re-run when the execution resumes after a later step's retry or wait — its budget was spent — though it still decides the outcome, recorded as `StepPreviouslyFailedException`. Only an operator re-executing the *failed* execution re-runs it.

**State machine.** `pending → skipped` is the only way in; `skipped` is terminal for the execution's lifetime and treated exactly like `completed` by recovery: skipped steps are skipped again, from the record, not from re-evaluation. Re-executing a *failed* execution re-runs only non-completed, non-skipped steps.

**Observability.** A new `WorkflowStepSkipped` lifecycle event (identifiers, step, position — fired after the durable skip). The step-progress read gains a `skipped` count alongside `total` and `completed`, so an execution whose steps are all completed-or-skipped reads as done rather than stuck, and the show command prints `skipped` as its own status.

## Delays, schedules, and wait states

**A wait is a step's outcome boundary.** A step that needs to pause returns a `Wait` from `handle()` instead of a value:

```php
public function handle(WorkflowContext $context): mixed
{
    $this->postApprovalRequest($context);          // side effect, runs once

    return Wait::forSignal('manager-approval')     // ...then the execution parks
        ->timeoutAfterSeconds(7 * 86_400);
}
```

Two kinds of wait, one mechanism:

- `Wait::untilSeconds($seconds)` / `Wait::until($instant)` — a timed delay. The execution resumes when the instant arrives.
- `Wait::forSignal($name)` — a named external release: a human approval, a webhook, another system. Resumes when the application delivers the signal. An optional `timeoutAfterSeconds()` bounds the park; a signal wait without one waits indefinitely and says so in the inspection output.

**What persists.** Returning a `Wait` checkpoints everything the step did first — the step row moves to a new `waiting` status and the context snapshot commits in the same transaction, exactly like a completion checkpoint. The execution moves to a new `waiting` status and the claim is **released**: a parked execution owns no worker. The step's `timeout_at` column holds a signal wait's deadline; a new nullable `wait_signal` string column on the step row holds the signal's name; and the executions row's existing `next_retry_at` holds *the next instant anything can happen* — the resume time for a timed wait, the timeout for a signal wait with one, null for an indefinite signal wait. That reuse is deliberate: the `(status, next_retry_at)` index and the recovery scan's shape already serve exactly this query.

**Resuming completes the step.** When the execution is claimed again — the wait came due, or a signal landed — the waiting step transitions `waiting → completed`. Its output is the signal's payload (or null for a timed wait), and for a signal wait the payload is also written into the context under the signal's name, so later steps read it like any other step's contribution. The step's own `handle()` does **not** run again: side effects before the wait happened exactly once (crash windows aside, as ever), and work that belongs after the wait belongs in the next step. This is the sharpest decision in this document, and it is what keeps waits out of the at-least-once window.

**Signals.** `ExecutionRepository` gains a `recordSignal($id, $name, $payload, $at)` primitive, compare-and-set against *waiting on that exact signal name*, matched byte for byte in PHP after the database read — MySQL, MariaDB, and SQL Server compare strings case-insensitively, and the reserved prefix is an authorization boundary: a signal for an execution not waiting on it, or racing a timeout, is refused with a typed exception rather than absorbed. A second delivery of the same signal before the resume overwrites the payload (last delivery wins); after the resume it is refused. The payload is JSON-bounded by the existing payload limit and lands durably in the waiting step's output at signal time. Recording and delivery are separate, as with cancellation: recording marks the execution due now; delivery is the queue fast path when configured (dispatched by the signalling side) and, contractually, the recover command — which gains due-waiting executions as a fourth candidate kind, exempt from the abandonment bound exactly as due retries are. A signal name must be non-empty, at most 255 characters, and may not begin with `laraflow.` in any casing — the reserved prefix; `Wait::forSignal()` and `signal()` refuse otherwise with `InvalidSignalException`.

**Timeout is an ordinary failure.** A signal wait passing its deadline fails the step with a `WaitTimedOutException`, charged to the step's retry policy like any thrown failure — retrying an approval step re-posts the approval request, which is usually the desired meaning; a workflow that wants one park and one deadline disables retries on that step. Classification happens at the recovery boundary, mirroring step timeouts.

**Cancellation.** A waiting execution is unclaimed, so cancellation takes the immediate path — the same compare-and-set that already cancels pending, failed, and retrying executions, extended to waiting. The parked step is left `waiting` in the record, marked by the execution's terminal state.

**Events.** `WorkflowStepWaiting` (step, position, signal name or resume time) after the park's durable write; `WorkflowExecutionSignalled` (signal name only, never payload) after a recorded signal. Resume itself rides the existing started/completed events.

**Retention and recovery.** A waiting execution has no `finished_at`, so retention cannot touch it, by the existing predicates. The stale-claim scan never sees it (no claim). The fruitless-claims bound does not apply to the park/resume cycle: the resume's first act is a step write, which clears the counter.

## Sub-workflows

A step may run another workflow as a child execution and park until it finishes.

```php
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

**Mechanics: composition is a wait.** When the step runs, it creates the child execution — carrying the parent's correlation and tenant identifiers — hands it to `ChildDelivery` (the default binding dispatches onto Laraflow's queue; the sync driver runs the child in-process before the call returns), and returns a `Wait` on the reserved signal `laraflow.child.{childId}`. When the child reaches any resting outcome — completed, failed, cancelled — its final write records that signal on the parent with a payload of the child's identity and status, never its context, and asks delivery to run the parent. The parent step then completes with that outcome as output; a failed or cancelled child fails the parent step with `ChildWorkflowFailedException`, subject to the parent step's retry policy like any failure.

**The park heartbeats.** A child wait does not park indefinitely: the parent schedules itself a check every `stale_after` seconds, and when claimed it consults the child's record directly — a finished child becomes the outcome even if its signal was lost to the park race; a `pending` child (a lost dispatch) or one whose failure predates the current parent attempt is re-delivered and the parent parks again, while running, retrying, and waiting children are left to their own machinery and recovery's bounds; a child whose workflow class no longer exists fails the step as `ChildWorkflowFailedException::workflowMissing`; and a child that no longer exists at all (pruned by retention) fails it as `ChildWorkflowFailedException::missing`. Composition therefore self-heals through `laraflow:recover` with no state that can hang forever.

**Retrying the parent step resumes the same child.** The child row's backlink to the exact parent step is durable before the child is delivered, so a retry finds *that* child and re-delivers it — a failed child is re-executed from its own checkpoints, the package's normal recovery path — rather than creating a sibling. A new child is created only when none exists yet. A *cancelled* child cannot be resumed: the parent step reports the same outcome on every retry until an operator intervenes. One at-least-once window remains, stated rather than hidden: a worker stalled inside the sub-workflow step past `stale_after` and the worker that reclaims it can each create a child before either parks — the zombie's park then fails on the claim and its child's outcome is refused and logged. Creation is not claim-guarded, so this is the same at-least-once contract every side effect has.

**Linkage is schema, not convention.** The executions table gains nullable `parent_execution_id` (indexed) and `parent_step_execution_id` columns, written at child creation. Inspection reads them: `ExecutionFilter` gains a `parentId` criterion, and the show command names a child's parent and what a waiting parent is parked on.

**Cancellation cascades down, visibly.** Cancelling a parent also requests cancellation of its unfinished children — found by the parent index, page by page, each child's own cancellation event firing — both at `cancel()` time and again at the boundary where a cooperative cancellation is honoured, so a child created mid-race is still caught. A child's cancellation signals the parent as any outcome does. Cancelling a *child* alone does not cancel the parent; the parent step fails with the child's outcome and the parent's own policies decide.

**Bounds.** Child creation checks `laraflow.workflows.max_depth` (default 8, counted by walking parent links at creation — a walk bounded by the limit itself), so mutual recursion between workflow classes is refused at create time with `WorkflowDepthExceededException` rather than discovered as an infinite tree.

**Retention across the boundary.** Retention's existing predicates already protect a live pair: a waiting parent is unfinished (untouchable), and a parent waits only on an unfinished child (also untouchable). Deleting a long-finished parent leaves the child's parent columns pointing at a record that no longer exists — recorded as the trade-off, with the parent filter returning nothing rather than erroring; the alternative, refusing to prune parents, would make retention unusable under composition. A child that finishes after its parent was deleted signals no one: a recorded signal for a missing or non-waiting execution is a logged no-op.

## Compatibility notes

- `StepStatus` gains `Skipped` and `Waiting`; `ExecutionStatus` gains `Waiting`. Application code matching exhaustively on these enums, or reading raw status strings, must account for them — a breaking change permitted pre-1.0 and recorded in the changelog with each step that ships.
- The step-progress read's shape gains a `skipped` key.
- `ExecutionRepository` grows: `recordSignal()`, a due-waiting scan, and the parent-linkage columns in its filters. Each lands as a **Breaking** changelog entry in its step.
- New public surface, documented in [public-api.md](public-api.md) as it ships: the conditional-step interface, `Wait`, the sub-workflow step base, three events, two step statuses, one execution status, and the typed exceptions for refused signals, wait timeouts, child failures, and the depth bound.

## Decided here, implemented in steps 7–9

The one-way doors, fixed by this document:

1. A wait is the step's outcome boundary: resuming completes the step; `handle()` never resumes mid-body.
2. Signal payloads are the waiting step's output, land in the context under the signal's name, and are size-bounded at signal time.
3. Wait timeouts are ordinary step failures under the step's retry policy.
4. Composition is the wait mechanism plus two linkage columns; a parent-step retry resumes the existing child rather than spawning a sibling.
5. Conditions live on the step class, must be pure functions of the context, and a skip is durable, attempt-free, and context-neutral.
6. Reuse over new columns: `next_retry_at` is the single "when can anything next happen" instant for retries and waits alike; `timeout_at` holds the wait deadline. Three genuinely new columns across all three features: `wait_signal`, `parent_execution_id`, `parent_step_execution_id`.
