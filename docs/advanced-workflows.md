# Advanced workflows: conditions, waits, composition, parallelism, and compensation

> Milestone 7's three capabilities — conditional steps, delays and wait states, and sub-workflow composition — were specified here before any of them was built, and this is their shipped contract; the enforcing suites are `tests/Feature/Workflow/ConditionalStepTest.php`, `WaitStateTest.php`, and `SubWorkflowTest.php`. Milestone 9's two — fan-out/fan-in and compensation — are specified here the same way, before a line of either exists. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.

## Shared principles

Every capability here obeys the contracts the core already keeps:

- **The plan stays linear.** `steps()` still returns an ordered list of step classes, and the persisted plan is still validated against it. A condition decides whether a planned step runs; a fan-out is *one* step whose members are separate executions; a compensation is a row appended after the plan, never inside it. Nothing here adds, removes, or reorders a planned step, and **graph branching remains out of scope** — a plan is a list, and every capability below is something a single position in that list does.
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
- `Wait::forSignal($name)` — a named external release: a human approval, a webhook, another system. Resumes when the application delivers the signal. An optional `timeoutAfterSeconds()` bounds the park; a signal wait without one waits indefinitely and says so in the inspection output. A parked execution, what it waits on, and a form to signal it are all in the opt-in dashboard ([dashboard.md](dashboard.md)), which calls the same `signal()` this document specifies.

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

## Fan-out and fan-in

A step may run many workflows in parallel as child executions and park until all of them finish.

```php
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

**Fan-out is composition, widened.** It introduces no new mechanism: a member is a child execution, exactly as a sub-workflow's is, with the same `parent_execution_id` and `parent_step_execution_id` linkage, the same depth bound, the same cancellation cascade, the same retention predicates. The plan is untouched — `NotifySubscribers` is one class at one position, and `steps()` still returns a flat list. Everything the sub-workflow section above says about a child is true of a member.

The difference is cardinality, and cardinality is what the design has to answer for.

**Members are declared, not discovered.** `members()` returns an iterable of `FanOut` declarations — a workflow and its input — and is called **once**, when the step first runs. The members that result are persisted as child executions before the step parks, so the group is a durable fact rather than something recomputed on every claim. A member list that would vary between calls (reading a table that is still being written, say) is the author's problem to make stable; Laraflow reads it once and records what it got. `members()` is an `iterable` so a large group need not be built in memory at once.

**Creation is chunked and resumable.** Members are created in batches, each batch committed before the next begins, and each carries the parent's correlation and tenant identifiers. A worker that dies part-way through creation leaves the members it had already created; the next claim counts what exists, creates only the remainder, and goes on. Nothing is created twice because the step records how many members it declared before it creates any of them — the count is the durable fact that makes the remainder computable.

**The join is a wait.** Once every member is created and delivered, the step returns a `Wait` on the reserved signal `laraflow.join.{stepExecutionId}`. The last member to reach a resting outcome — completed, failed, or cancelled — records that signal on the parent and asks delivery to run it, exactly as a single child does. The step does not resume on each member's completion; one signal, once, when the group is done.

**The join heartbeats, as a single child's park does.** A fan-out never parks indefinitely: the parent schedules a check every `stale_after` seconds, and when claimed it asks the repository for the group's progress in **one query** — `childProgress()`, counts by status for a parent step, served by an index on `parent_step_execution_id`. A group that is finished becomes the outcome even if its join signal was lost; a group still running parks again. A member whose dispatch was lost (still `pending` past the heartbeat) is re-delivered. The whole group therefore self-heals through `laraflow:recover`, with no new scan and no state that can hang forever.

**`childProgress()` is a deliberate addition to the public contract**, not a shortcut: counting a thousand members by reading them would defeat the purpose, so the contract gains one aggregate with its index, under the Milestone 4 rule that a filter Laraflow cannot bound is a filter it does not expose.

### Partial failure

**The join waits for all members.** When every member has reached a resting outcome, the step's fate is decided:

- Every member completed → the step completes, its output a summary of the group.
- Any member failed or was cancelled → the step fails with `FanOutFailedException`, naming how many members ended in each state and the ids of the first few that did not complete.

`JoinPolicy::WaitAll` is the default and the only policy that ships. `JoinPolicy::FailFast` — cancel the remaining members as soon as one fails — is **deliberately not shipped in Milestone 9**: it is a destructive reaction to a partial failure, it races every member that is already mid-step, and nothing has asked for it yet. The enum exists with one case so that adding the second later is additive rather than a signature change.

Waiting for all is the less surprising rule and never destroys work that was nearly done. A workflow that wants to stop early can cancel the parent, which cascades.

**The step's failure is an ordinary step failure.** It is charged to the step's own retry policy, it fires the same events, it is recorded the same way. A fan-out step is not special once it has failed.

**The output is a summary, never the members' payloads.** On success the step's output is the group's shape: total, completed, and **at most the first hundred** member execution ids. Bounded for two reasons — a thousand ids is a payload rather than a description, and reading every one of them cost a query per page, which is the one way the cost of deciding about a group grew with the group. A member's *context* never crosses the boundary, exactly as a single child's does not: an application that needs a member's result reads it from where that member put it, or lists the members by the parent filter.

### Cancellation and retry

**Cancelling the parent cascades to the members**, through the existing cascade, which already pages through children rather than loading them. At width this matters: the cascade is bounded per page and resumes, and members created mid-cancellation are caught at the cooperative boundary the same way a single child is.

**Retrying a fan-out step resumes the same members.** The members are linked to that exact step row, so a retry finds them, re-delivers the ones that are not finished, and parks again — it does not create a second group. Members that completed stay completed; a failed member is re-executed from its own checkpoints, which is the package's ordinary recovery path. A **cancelled** member cannot be resumed, so a group containing one reports the same outcome on every retry until an operator intervenes — the same rule a single cancelled child follows.

**Deciding about a group costs the same however wide it is.** One claim of a parked join issues the same number of queries at a thousand members as at three — a test asserts the two counts are *equal*, not merely similar, because "roughly constant" is how a linear cost hides. The aggregate does the counting; member rows are read only to re-deliver the few that need it and to sample ids for the summary.

### Bounds

- A `workflows.max_members` setting (default 1000) bounds one group; it arrives in the configuration with the step that reads it. A `members()` that yields more is refused at the step with `FanOutTooWideException` before anything is created, because a group too wide to cancel is a group too wide to run.
- `laraflow.workflows.max_depth` applies unchanged: it is checked once for the group, since every member is at the same depth.
- The summary output is bounded by the existing payload limit, which is why it carries ids and counts rather than results.

### What fan-out is not

- **Not a graph.** Members run in parallel with each other and with nothing else; the plan either side of the fan-out step is as linear as it was.
- **Not a map-reduce.** Laraflow does not aggregate member outputs. The join's output describes the group, and reduction is a step that follows.
- **Not dynamic re-planning.** The member list is read once and recorded. A member cannot add members.

## Compensation

A step may declare how to undo itself. When an execution fails terminally, Laraflow runs those declarations in reverse over the steps that completed.

```php
class ChargeCard implements Step, CompensatableStep
{
    public function handle(WorkflowContext $context): mixed
    {
        return ['charge_id' => $this->payments->charge(...)];
    }

    public function compensate(Compensation $compensation): void
    {
        // The step's own output and the context as it stood are here.
        $this->payments->refund($compensation->output()['charge_id']);
    }
}
```

**Compensation is opt-in by existing.** A step that does not implement `CompensatableStep` has nothing to undo; a workflow whose steps none of them do behaves exactly as it does today. There is no policy to set and no flag to turn on — the declaration *is* the opt-in. This is the same shape as `ConditionalStep`.

**It runs when the execution fails terminally**, which means: the failing step's retry policy is exhausted, and under `FailurePolicy::Continue` the remaining steps have run. Compensation is what happens *instead of* the execution coming to rest as `failed` — not alongside a retry. A retrying execution is still trying to go forward, and undoing work it is about to redo would be wrong.

**The pass runs in reverse over completed steps.** Position by position, highest first, skipping steps that did not complete — a `pending`, `skipped`, or `failed` step did nothing to undo. The failing step itself is **not** compensated: it did not complete, and a step that threw is in a state only the application knows. That is stated rather than guessed at, and it is the reason `compensate()` receives the step's recorded output: a step with no output has nothing to undo.

**Each compensation is a real step row.** It is appended after the plan — positions continue past the last planned step — and carries `compensates_step_execution_id` pointing at the row it undoes. It therefore has its own status, attempts, timeout, next retry, output, and error, and appears in `laraflow:show`, in the attempt history, and in the dashboard like any other step, because it *is* one. Nothing about the saga needs separate machinery to be inspectable.

The planned steps are the rows where `compensates_step_execution_id` is null; that is how plan validation keeps comparing like with like, and how the runner's loop keeps knowing what the plan is.

**A compensation is retryable, under the compensated step's policy.** The step that declared the undo declared how hard to try it. A compensation that fails is retried on that schedule, and when its retries are exhausted the pass **stops**: the execution comes to rest recording which compensations ran, which failed, and which were never reached. It does not skip past a failed compensation to undo earlier work, because the earlier work may be what the failed one depended on.

**The execution's status while this happens is `compensating`**, a new `ExecutionStatus` case. `docs/public-api.md` has always said more cases may be added and that applications should handle unknown cases defensively; this is the first time that has been true. `compensating` is unfinished — it records no `finished_at`, it is claimable, it is visible to recovery, and it appears in `laraflow:summary` and the dashboard's counts. The execution's final resting state is `failed` either way: compensation changes what was left behind, not whether the execution failed.

**Recovery sees a compensating execution** exactly as it sees a running one: a stale claim is reclaimed and the pass resumes from the record, since each compensation's own row says whether it ran. No new scan, and no new window — the pass is a loop over durable rows, checkpointed the same way the forward loop is.

**Compensations must be idempotent**, for the same reason steps must: a worker can die between performing an undo and recording it. `$context->remember()` is available inside `compensate()` and is the answer, with the same semantics [idempotency.md](idempotency.md) gives it.

### Compensation and fan-out

**Compensating a fan-out step does not compensate its members.** The step's own `compensate()` runs, and that is all. Members are separate executions with their own sagas, their own failures, and possibly their own compensations that have already run; undoing them from the parent would be acting at a distance on records the parent does not own.

A fan-out step that wants its members undone says so explicitly, in its own `compensate()`, using the member ids its output recorded. That is more code for the author and far less surprise. There is deliberately **no setting** that turns member compensation on: a flag that silently reaches into a thousand executions is exactly the hidden authority the shared principles forbid.

### Compensation and the other capabilities

- **Conditional steps.** A skipped step did not complete and is not compensated.
- **Waits.** A step that completed through a signal is compensated like any other; its output is the signal's payload.
- **Sub-workflows.** As with fan-out: the parent step's `compensate()` runs; the child is not touched.
- **Plan migration.** A migration may only rewrite the undecided tail, so every completed step is one the current code still describes — which is precisely what compensation needs to resolve `compensate()` against. A plan that migrated and then failed compensates the steps that completed, under the definitions they completed under.
- **Retention.** A compensation row is a step row and follows step rows: scrubbed with its execution's payloads, pruned with its attempts, deleted with its execution.

### Compensation's statuses, exactly

An execution is `compensating` while a pass is running under a claim. An undo whose own retry is scheduled rests it as **`retrying`** — accurately, since that is what it is doing — and the existing due-retry scan delivers it with no new scan and no new window. A pass already under way is recognised from its **rows** (a compensation row that has not completed), not from the status, so a resumed claim continues undoing rather than re-running a plan that is finished with. The failure the execution will rest on is recorded when the pass begins, so a pass spanning claims still ends on the right error, and an undo's own failure never overwrites it.

### What compensation is not

- **Not a transaction.** Nothing is rolled back. Compensations are forward actions that happen to undo, and they can fail.
- **Not automatic for failures an application handles.** A step that catches its own error and returns has not failed, and nothing is compensated.
- **Not reversible.** There is no un-compensate. A compensation that ran, ran.

## Compatibility notes

**From Milestone 7:**

- `StepStatus` gains `Skipped` and `Waiting`; `ExecutionStatus` gains `Waiting`. Application code matching exhaustively on these enums, or reading raw status strings, must account for them — a breaking change permitted pre-1.0 and recorded in the changelog with each step that ships.
- The step-progress read's shape gains a `skipped` key.
- `ExecutionRepository` grows: `recordSignal()`, a due-waiting scan, and the parent-linkage columns in its filters. Each lands as a **Breaking** changelog entry in its step.
- New public surface, documented in [public-api.md](public-api.md) as it ships: the conditional-step interface, `Wait`, the sub-workflow step base, three events, two step statuses, one execution status, and the typed exceptions for refused signals, wait timeouts, child failures, and the depth bound.

**From Milestone 9:**

- `ExecutionStatus` gains `Compensating`, the first case added since that enum promised more might be. It is unfinished, claimable, and visible to recovery and to every count.
- `ExecutionRepository` gains `childProgress()` — one indexed aggregate of a parent step's members — a **Breaking** addition for a custom implementation. Two additive migrations: an index on `parent_step_execution_id`, and `compensates_step_execution_id` on step rows.
- Step rows may now exist that are not plan steps. Anything reading the plan from the step rows must filter to `compensates_step_execution_id IS NULL`; `steps()`-to-plan validation does.
- New public surface: `FanOutStep`, `FanOut`, `JoinPolicy`, `CompensatableStep`, `Compensation`, the fan-out and compensation events, and the typed exceptions for a failed group, a group too wide, and a compensation that could not finish.
- One configuration key, arriving with the step that reads it: `workflows.max_members` (default 1000). Compensation adds none, deliberately.

## Decided here, implemented in steps 7–9 (Milestone 7) and 4–11 (Milestone 9)

The one-way doors, fixed by this document:

1. A wait is the step's outcome boundary: resuming completes the step; `handle()` never resumes mid-body.
2. Signal payloads are the waiting step's output, land in the context under the signal's name, and are size-bounded at signal time.
3. Wait timeouts are ordinary step failures under the step's retry policy.
4. Composition is the wait mechanism plus two linkage columns; a parent-step retry resumes the existing child rather than spawning a sibling.
5. Conditions live on the step class, must be pure functions of the context, and a skip is durable, attempt-free, and context-neutral.
6. Reuse over new columns: `next_retry_at` is the single "when can anything next happen" instant for retries and waits alike; `timeout_at` holds the wait deadline. Three genuinely new columns across all three features: `wait_signal`, `parent_execution_id`, `parent_step_execution_id`.

And for Milestone 9:

7. A fan-out is one plan step whose members are child executions; the plan stays a flat list and graph branching stays out of scope.
8. The member list is read once, recorded, and created in resumable chunks; the declared count is the durable fact that makes a partial creation completable.
9. The join waits for every member. `WaitAll` is the only policy that ships; cancelling siblings on a failure is a destructive reaction nothing has asked for, and the enum is shaped so adding it later is additive.
10. A compensation is an ordinary step row appended after the plan, so it is retryable and inspectable without new machinery; the plan is the rows that compensate nothing.
11. Compensation is opt-in by declaring it, runs only on terminal failure, skips the step that failed, and stops at the first compensation whose retries are exhausted rather than reaching past it.
12. Neither a fan-out nor a sub-workflow compensates its children. Acting at a distance on executions a parent does not own is the hidden authority the shared principles forbid; an author who wants it writes it.
