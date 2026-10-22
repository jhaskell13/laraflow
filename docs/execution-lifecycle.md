# Execution lifecycle and recovery semantics

This document is the contract for how Laraflow persists, claims, runs, and recovers workflow executions. The recovery test suite (`tests/Feature/Workflow/WorkflowRecoveryTest.php`) exercises each guarantee stated here; if the code and this document disagree, one of them has a bug.

## Runner API

| Method | Behaviour |
|--------|-----------|
| `start(Workflow $workflow, array $input = []): WorkflowExecution` | Validates the input against the serialization contract, then atomically creates the execution (status `pending`) **and one `pending` step row per step in the definition**. Runs nothing. |
| `execute(int\|string $executionId, Workflow $workflow): WorkflowExecution` | Claims the execution, validates the definition against the persisted plan, restores the context, runs every step that is not `completed` in position order, checkpoints after each, and marks the execution terminal. Used for first execution *and* for recovery — there is one code path. |
| `run(Workflow $workflow, array $input = []): WorkflowExecution` | `start()` followed by `execute()` in the same process. |

On step failure, `execute()`/`run()` persist the failure and rethrow the step's original exception unchanged.

## State machines

### Execution

```
pending ──→ running ──→ completed   (terminal)
              │ ▲
              ▼ │
            failed                  (re-executable)

running ──→ running                 (stale reclaim by another worker)
```

### Step

```
pending ──→ running ──→ completed   (terminal)
              │ ▲
              ▼ │
            failed                  (re-run on execute)

running ──→ running                 (interrupted step re-run)
```

Transitions are enforced by `WorkflowExecution::mark*()` and `StepExecution::mark*()`; anything else throws `InvalidStateTransitionException`. `attempts` increments on every step `running` transition. `started_at` records the *first* start and is preserved across re-executions. Completing clears a previous `error`/`error_class`.

**A step found in `running` state during recovery is treated as interrupted**: the worker that owned it died somewhere between marking it running and checkpointing it. Its side effects may or may not have happened. Recovery runs it again. This is the at-least-once boundary (see below).

## The plan snapshot

The full step plan is persisted at `start()`, before anything runs. Consequences:

- A crash at any point leaves a complete, self-describing record: every step of the plan exists as a row, each with its status.
- Recovery never needs the workflow definition to know *what was supposed to happen* — only to supply the code to run it.
- `execute()` validates that the supplied workflow matches the persisted plan — same workflow class, same step classes, same order — and throws `WorkflowDefinitionMismatchException` otherwise. Durable state is never silently reinterpreted by changed code. A step class that no longer exists fails the same way, as a persisted step failure.

There is no workflow-version migration mechanism yet. If a workflow definition must change while executions are in flight, those executions can only be finished by code with the original step list.

## Claiming and concurrency

`execute()` claims the execution with a single compare-and-set `UPDATE`:

- Claimable: status `pending`, `failed`, or `running` with `claimed_at` older than the configured `laraflow.execution.stale_after` (default 300 seconds).
- The update sets `status = running`, a random 32-hex-char `claim_token`, and `claimed_at = now`.
- Zero affected rows means the claim failed; the repository then reports *why*: `ExecutionNotFoundException`, `ExecutionAlreadyClaimedException` (fresh claim held elsewhere), or `ExecutionNotRunnableException` (terminal status).

Because claiming is one guarded `UPDATE`, exactly one of any number of concurrent claimants succeeds on every supported engine, including SQLite (single writer) and MySQL/PostgreSQL/MariaDB (row-locked atomic update). No advisory locks or `SELECT ... FOR UPDATE` are required.

**Claim verification on writes.** Checkpoints and terminal transitions include `WHERE claim_token = <mine>`. If another worker has legitimately reclaimed a stale execution, the old worker's write matches zero rows and it receives `ClaimLostException`; the checkpoint transaction rolls back entirely, so a zombie worker cannot corrupt a reclaimed execution's durable state. Interim step-status writes (`running` markers) are not token-guarded; they are diagnostic and are rewritten by the active owner.

**Worker death.** A dead worker leaves `status = running` with an aging `claimed_at`. After `stale_after` seconds the execution becomes claimable again and `execute()` recovers it. Set `stale_after` comfortably above your longest step; a too-short value makes two live workers fight, which the claim token turns from corruption into a `ClaimLostException` on the loser. (Automatic detection and scheduled re-dispatch of stale executions is Milestone 2; today recovery happens when something calls `execute()`.)

## Checkpoints and context

The context is seeded from the workflow input (`array_merge(input, persisted context)`), handed to each step, and persisted at every checkpoint:

1. Step completes → in one database transaction: the step row is updated to `completed` with its output, and the execution row's `context` column is updated to the full context snapshot, guarded by the claim token.
2. On recovery, the context is rebuilt from `input` + the persisted `context` column — not by re-running steps. Completed steps are skipped and their contribution to the context is exactly what the checkpoint recorded.

Because the step output and context snapshot commit atomically, there is no reachable state where a step is `completed` but its context contribution is missing.

## Persistence boundaries and the at-least-once contract

The persistence boundaries in one step iteration, and what a crash at each one means:

| Crash point | Durable state afterwards | Recovery behaviour |
|---|---|---|
| After `start()`, before `execute()` | Execution `pending`, all steps `pending` | Everything runs normally. |
| After claim, before step marked running | Step `pending` | Step runs normally. |
| After step marked running, before/during `handle()` | Step `running` | Step re-runs. Side effects may not have happened. |
| **After `handle()`, before checkpoint commit** | Step `running` | **Step re-runs. Side effects happened once and will happen again.** |
| After checkpoint commit | Step `completed`, context updated | Step is skipped; later steps see its context. |
| After last checkpoint, before release | All steps `completed`, execution `running` | No steps re-run; execution is marked `completed`. |

The bold row is the at-least-once contract: **Laraflow guarantees each step's checkpoint commits at most once, but the step's side effects can occur more than once** when a worker dies between performing them and checkpointing. No framework can remove this window for arbitrary external calls.

To make repeated side effects safe, steps get an idempotency hook: `WorkflowContext::idempotencyKey()` returns `laraflow:{executionId}:{stepPosition}` — stable across crashes and retries of the same logical step, different for different steps and executions. `executionId()`, `stepPosition()`, and `attempt()` are also exposed for custom schemes. Pass the key to providers that support idempotency keys, or use it to deduplicate in your own tables.

## Serialization contract

Everything durable — workflow input, step output, and every context value — must be JSON-serializable: `null`, `bool`, `int`, finite `float`, `string`, or arrays of those (nested freely).

- `start()` validates the input **before creating anything**; invalid input means no rows are written.
- Step output and the context snapshot are validated after `handle()` returns, **before the checkpoint**; a violation fails the step with `UnserializableValueException`, whose message names the exact path (`output.customer.loaded`) and type.
- Live PHP objects, closures, resources, Eloquent models, and non-finite floats are rejected, never silently coerced. Store identifiers and reload.

Null is a valid step output. Payload size limits are the database's (`json`/`TEXT` columns); a payload-size policy is deliberately deferred to Milestone 4 (retention/pruning).

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
| `running`, fresh claim | `ExecutionAlreadyClaimedException`. |
| `running`, stale claim | Reclaims and continues from durable state. |
| `completed` execution | `ExecutionNotRunnableException`. |
| Unknown id | `ExecutionNotFoundException`. |
| Workflow class or step list differs from the plan | `WorkflowDefinitionMismatchException`. |
| Step class missing/not a `Step` | `WorkflowDefinitionMismatchException`, persisted as the step's failure. |
