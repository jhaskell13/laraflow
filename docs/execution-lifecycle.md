# Execution lifecycle and recovery semantics

This document is the contract for how Laraflow persists, claims, runs, and recovers workflow executions. The recovery test suite (`tests/Feature/Workflow/WorkflowRecoveryTest.php`) exercises each guarantee stated here; if the code and this document disagree, one of them has a bug.

## Runner API

The two that run anything (`execute()` and `run()`) do so in the calling process. To run one on a queue worker instead, use `WorkflowDispatcher` ([docs/queue-and-recovery.md](queue-and-recovery.md)); it creates the execution the same way and the worker calls `execute()`, so everything below applies unchanged.

| Method | Behaviour |
|--------|-----------|
| `start(Workflow $workflow, array $input = [], ?ExecutionOptions $options = null): WorkflowExecution` | Validates the input against the serialization contract and the step list against the definition rules (non-empty; every entry an existing class implementing `Step`, else `InvalidWorkflowDefinitionException`), then atomically creates the execution (status `pending`) **and one `pending` step row per step in the definition**. Runs nothing; on validation failure nothing is persisted. `$options` records the correlation and tenant identifiers described in [Correlation identifiers](#correlation-identifiers). |
| `execute(int\|string $executionId, Workflow $workflow, bool $ignoreRetrySchedule = false): WorkflowExecution` | Claims the execution, validates the definition against the persisted plan, restores the context, runs every step that is not `completed` or `skipped` in position order, checkpoints after each, and brings the execution to its resting state — terminal, `retrying`, or `waiting` (a step that returned a `Wait` parks it; see [advanced-workflows.md](advanced-workflows.md)). Used for first execution *and* for recovery — there is one code path. `$ignoreRetrySchedule` claims a `retrying` execution before its due time and resumes a *timed* wait early (the operator override behind `laraflow:execute --now`). |
| `run(Workflow $workflow, array $input = [], ?ExecutionOptions $options = null): WorkflowExecution` | `start()` followed by `execute()` in the same process. |
| `cancel(int\|string $executionId, ?string $reason = null): WorkflowExecution` | Cancels an execution, or requests cooperative cancellation from the worker that owns it. See [retry-and-failure-policy.md](retry-and-failure-policy.md#cancellation). |

On a step failure the step's retry policy decides ([retry-and-failure-policy.md](retry-and-failure-policy.md)). If a retry is allowed, the failed attempt and its schedule are persisted and the `retrying` handle is **returned** — nothing is thrown, because nothing has terminally failed. When the policy refuses, or no policy enables retries (the shipped default), `execute()`/`run()` persist the failure and rethrow the step's original exception unchanged.

## State machines

### Execution

```
pending ──→ running ──→ completed   (terminal)
              │ ▲
              ▼ │
            failed                  (re-executable)

running ──→ retrying ──→ running    (scheduled retry, once next_retry_at is due)
running ──→ waiting  ──→ running    (a step's wait: resolved, due, or heartbeat)
running ──→ running                 (stale reclaim by another worker)

pending | running | failed | retrying | waiting ──→ cancelled   (terminal)
```

### Step

```
pending ──→ running ──→ completed   (terminal)
              │ ▲
              ▼ │
            failed                  (re-run on execute)

pending ──→ skipped                 (conditional step; terminal, replayed from the record)
running ──→ waiting ──→ completed   (wait resolved) | failed | retrying (wait timed out)
running ──→ retrying ──→ running    (scheduled retry)
running ──→ running                 (interrupted step re-run)
```

The `skipped` and `waiting` machinery — conditions, waits, signals, and sub-workflows — is specified in [advanced-workflows.md](advanced-workflows.md); this document's claiming, checkpointing, and recovery rules apply to them unchanged.

Transitions are enforced by `WorkflowExecution::mark*()` and `StepExecution::mark*()`; anything else throws `InvalidStateTransitionException`. `attempts` increments on every step `running` transition. The *execution's* `started_at` records the first start and is preserved across re-executions; a *step's* `started_at` is rewritten on each attempt, so together with `attempts` it describes the most recent run. Completing clears a previous `error`/`error_class`.

Entering `running` and `cancelled` both clear any scheduled `next_retry_at`: a row that is running or terminal must never advertise a due time the recovery scan could act on. Every finished attempt — including the ones a retry replaces — is preserved in `laraflow_step_attempts`; the step row itself always shows only the latest.

**A step found in `running` state during recovery is treated as interrupted**: the worker that owned it died somewhere between marking it running and checkpointing it. Its side effects may or may not have happened. Recovery runs it again. This is the at-least-once boundary (see below).

One exception: if that step had a deadline (`timeout_at`) which has also passed, the attempt is classified as a **timeout** rather than plain worker loss and is charged against the step's retry budget instead of re-running for free. The three-way distinction is specified in [retry-and-failure-policy.md](retry-and-failure-policy.md#timeouts).

## The plan snapshot

The full step plan is persisted at `start()`, before anything runs. Consequences:

- A crash at any point leaves a complete, self-describing record: every step of the plan exists as a row, each with its status.
- Recovery never needs the workflow definition to know *what was supposed to happen* — only to supply the code to run it.
- `execute()` validates that the supplied workflow matches the persisted plan — same workflow class, same step classes, same order — and throws `WorkflowDefinitionMismatchException` otherwise. Durable state is never silently reinterpreted by changed code. A step class that no longer exists fails the same way, as a persisted step failure.

There is no workflow-version migration mechanism yet. If a workflow definition must change while executions are in flight, those executions can only be finished by code with the original step list.

## Claiming and concurrency

`execute()` claims the execution with a single compare-and-set `UPDATE`:

- Claimable: status `pending`, `failed`, `retrying` whose `next_retry_at` is due (or with the `ignoreRetrySchedule` override), `waiting` whose `next_retry_at` is non-null and due — a null instant is an indefinite signal wait, claimable only once a signal sets one (or under the override) — or `running` with `claimed_at` older than the configured `laraflow.execution.stale_after` (default 300 seconds). `completed` and `cancelled` are terminal and never claimable.
- The update sets `status = running`, a random 32-hex-char `claim_token`, `claimed_at = now`, and clears `next_retry_at`.
- Zero affected rows means the claim failed; the repository then reports *why*: `ExecutionNotFoundException`, `ExecutionAlreadyClaimedException` (fresh claim held elsewhere), `RetryNotDueException` (retrying, but not yet due), `ExecutionStillWaitingException` (parked on a wait that is not due — indefinitely, for an unsignalled signal wait), or `ExecutionNotRunnableException` (terminal status).

Cancellation of an unclaimed execution uses the same compare-and-set predicate, so cancelling cannot race a worker claiming it: whichever `UPDATE` lands first, the other is refused.

Because claiming is one guarded `UPDATE`, exactly one of any number of concurrent claimants succeeds on every supported engine, including SQLite (single writer) and MySQL/PostgreSQL/MariaDB (row-locked atomic update). No advisory locks or `SELECT ... FOR UPDATE` are required.

**Claim verification on writes.** Every durable write a worker makes after claiming — step `running` markers, step failures and retry schedules, attempt-history rows, checkpoints, and terminal transitions — is made in a transaction that includes `WHERE claim_token = <mine>` on the executions row. If another worker has legitimately reclaimed a stale execution, the old worker's write matches zero rows and it receives `ClaimLostException`; the transaction rolls back entirely, step row and attempt row included, so a zombie worker cannot corrupt a reclaimed execution's durable state. The step rows carry no claim column of their own: `saveStep()` verifies the claim by touching the executions row in the same transaction, which is what makes a step write by a dead worker fail rather than land.

**Worker death.** A dead worker leaves `status = running` with an aging `claimed_at`. After `stale_after` seconds the execution becomes claimable again and `execute()` recovers it. Set `stale_after` comfortably above your longest step; a too-short value makes two live workers fight, which the claim token turns from corruption into a `ClaimLostException` on the loser. Finding such executions automatically is the job of `laraflow:recover` — see [docs/queue-and-recovery.md](queue-and-recovery.md#recovering-interrupted-work).

**Claims that make no progress.** Every claim increments `fruitless_claims` on the executions row, and it is zeroed by the first step-row write under that claim and by every orderly `release()` — a park, a scheduled retry, a terminal transition — in the same guarded `UPDATE` that verifies the claim, so it costs nothing extra. A heartbeat that claims a waiting execution, consults its child, and parks it again is deliberate work, not a fruitless claim. A worker that faults *between* claiming and its first step write (a listener throwing on `WorkflowExecutionStarted`, a definition that no longer matches the persisted plan) leaves the counter standing, and the step attempt counter — which recovery's abandonment bound otherwise reads — never moves. `laraflow:recover` therefore holds `fruitless_claims` to the same bound as step attempts (`recovery.abandon_after_attempts`), so such an execution is abandoned and reported after that many claims rather than re-dispatched by every run forever. `laraflow:show` surfaces the count when it exceeds one.

## Checkpoints and context

The context is seeded from the workflow input (`array_replace(input, persisted context)` — the persisted context wins key by key, and integer keys survive), handed to each step, and persisted at every checkpoint:

1. Step completes → in one database transaction: the step row is updated to `completed` with its output, the attempt is appended to `laraflow_step_attempts`, and the execution row's `context` column is updated to the full context snapshot, guarded by the claim token.
2. On recovery, the context is rebuilt from `input` + the persisted `context` column — not by re-running steps. Completed steps are skipped and their contribution to the context is exactly what the checkpoint recorded.

Because the step output and context snapshot commit atomically, there is no reachable state where a step is `completed` but its context contribution is missing.

### Limits closed since the Milestone 6 audit

The audit recorded two windows where the claim did not protect everything. Both are closed in Milestone 7 and kept here so the record of the contract's evolution stays with the contract: step rows were last-writer-wins until a dead worker's first checkpoint failed (closed by claim-guarding `saveStep()`, under *Claim verification on writes*), and a deterministic fault between `claim()` and the first step write was re-dispatched by recovery without bound (closed by the `fruitless_claims` counter, under *Claims that make no progress*).

## Persistence boundaries and the at-least-once contract

The persistence boundaries in one step iteration, and what a crash at each one means:

| Crash point | Durable state afterwards | Recovery behaviour |
|---|---|---|
| After `start()`/`dispatch()`, before a worker claims it | Execution `pending`, all steps `pending` | Everything runs normally. A lost queue dispatch is found only if the pending scan is enabled (see [queue-and-recovery.md](queue-and-recovery.md#what-it-looks-for)). |
| After claim, before step marked running | Step `pending` | Step runs normally. |
| After step marked running, before/during `handle()` | Step `running` | Step re-runs. Side effects may not have happened. |
| **After `handle()`, before checkpoint commit** | Step `running` | **Step re-runs. Side effects happened once and will happen again.** |
| After checkpoint commit | Step `completed`, context and attempt history updated | Step is skipped; later steps see its context. |
| After last checkpoint, before release | All steps `completed`, execution `running` | No steps re-run; execution is marked `completed`. |
| After a park's checkpoint, before its release | Step `waiting`, execution `running` with a stale claim | The reclaiming worker finds the wait not due and parks the execution again; nothing re-runs. |
| After a terminal failure's step write, before its release | Step `failed` at its last attempt, execution `running` with a stale claim | The reclaiming worker re-runs the failed step once more before the policy refuses it — one attempt past the budget, side effects included. |
| After a scheduled retry's step write, before its release | Step `retrying` with `next_retry_at`, execution `running` with a stale claim | The reclaiming worker (after `stale_after`) re-runs the step without honouring the backoff; the dependency the backoff protects is hit early when `stale_after` is shorter than the delay. |

The bold row is the at-least-once contract: **Laraflow guarantees each step's checkpoint commits at most once, but the step's side effects can occur more than once** when a worker dies between performing them and checkpointing. No framework can remove this window for arbitrary external calls. A retry policy widens it deliberately — re-running a failed step is the point — which is why idempotency matters more, not less, once retries are enabled.

Each of the re-run rows above also closes out the dead attempt in history, as `interrupted` or — if its deadline had passed — as `timed_out`, which is charged to the retry budget; see [retry-and-failure-policy.md](retry-and-failure-policy.md#timeouts).

To make repeated side effects safe, steps get an idempotency hook: `WorkflowContext::idempotencyKey()` returns `laraflow:{executionId}:{stepPosition}` — stable across crashes and retries of the same logical step, different for different steps and executions. `executionId()`, `stepPosition()`, and `attempt()` are also exposed for custom schemes. Pass the key to providers that support idempotency keys, or use it to deduplicate in your own tables.

## Correlation identifiers

An execution can record two application-supplied identifiers when it is created:

```php
use Laraflow\Workflow\ExecutionOptions;

$runner->run($workflow, $input, new ExecutionOptions(
    correlationId: $request->header('X-Request-Id'),
    tenantId: (string) $tenant->id,
));
```

Laraflow never interprets them. It persists them to indexed columns, exposes them to steps, and carries them on every lifecycle event, so one identifier ties an application's logs, an execution, and an external provider's records together.

- **Validated at construction**, before anything is persisted: trimmed, with a blank value treated as absent (so passing a missing request header is safe); rejected over 255 characters, because silent truncation by the database leaves an identifier that correlates with nothing; and rejected when they contain control characters, because these values reach log records where a newline would let a crafted identifier forge a second line. Violations raise `InvalidExecutionOptionsException`.
- **Read from durable state, never from ambient context.** A step calls `$context->correlationId()` and `$context->tenantId()`. A worker recovering an execution has no request to inherit them from, so they come from the execution record and a re-run step sees the originals.
- **Immutable.** They are set at creation and never rewritten; `execute()` has no options parameter. An execution's identity cannot change under recovery.

## Serialization contract

Everything durable — workflow input, step output, and every context value — must be JSON-serializable: `null`, `bool`, `int`, finite `float`, `string`, or arrays of those (nested freely).

- `start()` validates the input **before creating anything**; invalid input means no rows are written.
- Step output and the context snapshot are validated after `handle()` returns, **before the checkpoint**; a violation fails the step with `UnserializableValueException`, whose message names the exact path (`output.customer.loaded`) and type.
- Live PHP objects, closures, resources, Eloquent models, and non-finite floats are rejected, never silently coerced. Store identifiers and reload.

Null is a valid step output.

### Payload size

`laraflow.execution.max_payload_bytes` bounds how large a single durable payload may be — the workflow input, one step's output, or the context snapshot — measured as the JSON that reaches the column. It is **null by default**, leaving the only limit the database's.

Setting it does not prevent a failure that would otherwise succeed; it changes *where and how* the failure is reported. Without a limit an oversized payload still fails, but as a driver error about a column type or a packet size, raised from inside the checkpoint transaction and hard to attribute to a step. With one, the boundary is checked before the write and the step fails with `PayloadTooLargeException` naming the path, the actual size, and the limit.

- Enforced at the same three points as the type contract: **input** at `start()` (nothing is persisted), and **output** and **context** after `handle()` returns, before the checkpoint.
- Each is measured independently, because each is written to its own column.
- **Never retried.** The same step producing the same output will produce the same size, so it joins the deterministic faults in `StepPolicy::NEVER_RETRY`.
- A context that grows past the limit fails the step that grew it. Earlier checkpoints are untouched and are still skipped on re-execution.

## Failure persistence

When a step throws:

1. The step row records `failed`, the exception class, and the message (truncated to `laraflow.execution.max_error_length`, default 65535 characters).
2. The execution records `failed`, `failed_at`, the same error, and releases its claim.
3. The original exception is rethrown unchanged.

If persisting the failure *itself* fails (storage down), the storage exception is logged via Laravel's logger with full context, and **the original step exception still propagates** — diagnosis is never masked by a secondary failure. In that case the execution is left `running` with its claim; it becomes recoverable through the stale-claim path.

`ClaimLostException` is the one exception the runner never tries to persist a failure for: having lost the claim, any write it made could corrupt the new owner's state.

## Restart semantics summary

| Situation | Behaviour of `execute()` |
|---|---|
| `pending` execution | Runs from the first step. |
| `failed` execution | Claims it, skips `completed` steps, re-runs the failed step (attempts accumulate). |
| `retrying`, due | Claims it and runs the scheduled retry. |
| `retrying`, before `next_retry_at` | `RetryNotDueException` (override with `ignoreRetrySchedule` / `--now`). |
| `waiting`, due (timed resume, signal delivered, deadline, or heartbeat) | Claims it and resolves the wait: completes the step, charges the timeout, or parks again. |
| `waiting`, not due or indefinite | `ExecutionStillWaitingException` (`--now` resumes a timed wait early; a signal wait has nothing to resume with and parks again). |
| `running`, fresh claim | `ExecutionAlreadyClaimedException`. |
| `running`, stale claim | Reclaims and continues from durable state. An interrupted step past its deadline is charged as a timeout rather than re-run free; see [retry-and-failure-policy.md](retry-and-failure-policy.md#timeouts). |
| `completed` or `cancelled` execution | `ExecutionNotRunnableException`. |
| Cancellation requested mid-run | The step in flight finishes and checkpoints; the execution is marked `cancelled` at the next step boundary. |
| Unknown id | `ExecutionNotFoundException`. |
| Any of the above, delivered as a duplicate queue job | The job treats the refusal as a no-op and succeeds; see [queue-and-recovery.md](queue-and-recovery.md#duplicate-deliveries). |
| Workflow class or step list differs from the plan | `WorkflowDefinitionMismatchException`. |
| Step class missing/not a `Step` | `WorkflowDefinitionMismatchException`, persisted as the step's failure. |
