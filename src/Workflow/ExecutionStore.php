<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Closure;
use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\TenantThrottledException;

/**
 * The storage-driver half of execution storage: the writes, claims, and
 * scans the engine, recovery, and retention are built on.
 *
 * Implementations must satisfy the guarantees documented in
 * docs/execution-lifecycle.md: atomic plan creation, compare-and-set
 * claiming, and transactional, claim-verified checkpoints.
 *
 * Experimental because it is the surface every milestone has widened —
 * park(), createMember(), deferred(), the $availableBefore cutoff — and
 * freezing it at 1.0 would freeze the engine's internals with it. A
 * custom driver implements ExecutionRepository (this plus
 * ExecutionReader) and should expect this half to keep moving until a
 * second driver has proved its shape (docs/compatibility.md).
 * Application code should not call it: WorkflowRunner and
 * WorkflowDispatcher are the public way to start, signal, cancel, and
 * deliver.
 *
 * @experimental
 */
interface ExecutionStore
{
    /**
     * Create an execution and its complete step plan atomically.
     *
     * Every step is recorded in `pending` state before anything runs, so
     * the persisted plan is a complete snapshot of the workflow definition
     * regardless of when or whether execution happens.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $steps  Ordered step class names.
     * @param  ExecutionOptions|null  $options  Application-supplied correlation
     *                                          and tenant identifiers, recorded with the execution. Null
     *                                          records none.
     * @param  int  $planVersion  The workflow's declared plan version, recorded
     *                            so a later deploy can tell which plan this execution is running.
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = [],
        ?ExecutionOptions $options = null,
        int $planVersion = 1
    ): WorkflowExecution;

    /**
     * Replace the undecided tail of an execution's persisted plan and
     * record the plan version it now runs, in one claim-guarded
     * transaction.
     *
     * The rows from $frozenBefore onward are deleted and the steps in
     * $tail inserted in their place, because `(execution, position)` is
     * unique and renumbering in place collides with itself halfway
     * through on every engine. Deleting them is safe precisely because
     * they are undecided: a pending step row has no output, no attempt
     * history, and no context contribution, so nothing refers to it and
     * nothing was learned from it. Every row before $frozenBefore is
     * untouched. See docs/workflow-versioning.md.
     *
     * @param  list<string>  $tail  Ordered step class names from $frozenBefore onward.
     * @return StepExecution[] The execution's full plan after the rewrite, in position order.
     *
     * @throws ClaimLostException when this worker no longer holds the claim.
     */
    public function rewritePlan(
        WorkflowExecution $execution,
        int $frozenBefore,
        array $tail,
        int $planVersion
    ): array;

    /**
     * Atomically claim an execution for this worker (compare-and-set).
     *
     * Succeeds when the execution is `pending`, `failed` with
     * $reexecuteFailed set, `retrying` with `next_retry_at` due,
     * `waiting` with a non-null, due `next_retry_at` (or, for both,
     * $ignoreRetrySchedule set — the deliberate operator override), or
     * `running` with a claim older than $staleAfterSeconds. Exactly one
     * concurrent claimant can succeed. The returned handle's
     * `resumedFrom` records the status the claim took the execution
     * from.
     *
     * `failed` is terminal unless the caller opts in: $reexecuteFailed
     * is the operator's "run this failure again" (laraflow:execute, the
     * dashboard's execute action, WorkflowRunner::execute(), a queued
     * re-run an operator asked for), and a claim without it — every
     * plain queue delivery, every recovery pass — is refused exactly as
     * a completed execution is, so a duplicate delivery can never re-run
     * a terminal failure (docs/queue-and-recovery.md).
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotRunnableException when the execution is completed or cancelled, or failed without $reexecuteFailed.
     * @throws ExecutionAlreadyClaimedException when another worker holds a fresh claim.
     * @throws RetryNotDueException when the execution is retrying before its due time.
     * @throws ExecutionStillWaitingException when the execution is parked on a wait that is not due.
     * @throws TenantThrottledException when the execution's tenant is at a ceiling; the deferral is
     *                                  recorded on the row and nothing else about it changes (docs/tenancy.md).
     */
    public function claim(
        int $id,
        string $claimToken,
        int $staleAfterSeconds,
        bool $ignoreRetrySchedule = false,
        bool $reexecuteFailed = false
    ): WorkflowExecution;

    /**
     * Append a compensation row for a completed step: a step row of its
     * own, at the next free position, linked to the row it undoes.
     *
     * A compensation is an ordinary step, which is what makes a saga
     * retryable and inspectable without new machinery
     * (docs/advanced-workflows.md). The plan is the rows that
     * compensate nothing, so an appended row never joins it.
     *
     * Claim-guarded like every other step write.
     */
    public function appendCompensation(
        WorkflowExecution $execution,
        StepExecution $compensates
    ): StepExecution;

    /**
     * Persist the execution's status alone, keeping the claim.
     *
     * The compensation pass needs the execution durably `compensating`
     * before it undoes anything, without releasing the claim it is
     * working under.
     */
    public function checkpointStatus(
        WorkflowExecution $execution
    ): void;

    /**
     * The newest child of one parent step, or null when it has none —
     * served by the index on parent_step_execution_id. A sub-workflow
     * step has at most one child (retries resume it); this is how the
     * step finds it without reading its siblings.
     */
    public function childOfStep(
        int $parentStepExecutionId
    ): ?WorkflowExecution;

    /**
     * Create member $index of a fan-out exactly once: run $create — the
     * member's creation — in one transaction that holds the parent
     * step row's lock, and only while the step has no more than $index
     * children. Returns what $create returned, or null when member
     * $index already exists because another worker made it first.
     *
     * Members carry no index column; their count *is* the index, which
     * is why the count and the insert must not be separable. Two workers
     * resuming the same partial creation (a stale reclaim racing the
     * original) are serialised on the step row and the second creates
     * nothing. Deliver the member after this returns, never inside
     * $create: the sync queue driver would run it under the lock.
     *
     * @template TMember
     *
     * @param  Closure(): TMember  $create
     * @return TMember|null
     */
    public function createMember(
        int $parentStepExecutionId,
        int $index,
        Closure $create
    ): mixed;

    /**
     * Executions in `running` state whose claim is older than the cutoff
     * (or missing entirely): the signature a worker leaves when it dies.
     *
     * The cutoff is passed in rather than computed here so implementations
     * stay clock-free; it must use the same `claimed_at <= cutoff`
     * comparison as claim(), so the two mechanisms agree on staleness.
     *
     * Every recovery scan — this one, pendingBefore(), dueForRetry(),
     * and waitingDue() — has the same shape: a cutoff, a limit, and the
     * two optional scopes, so a caller that narrows one narrows all.
     * All four take $availableBefore: when given, a row whose last
     * queue push became deliverable (`available_at`) after it is in
     * flight and excluded, so a recovery pass does not re-push work a
     * worker has simply not reached yet. Null — the default, what the
     * attention scan passes, and what recovery passes to stale(), where
     * a dead worker's claim already proves the push was consumed —
     * reports every row regardless.
     *
     * @param  string|null  $workflow  Only executions created from this workflow class.
     * @param  string|null  $tenantId  Only executions for this tenant.
     * @return WorkflowExecution[] ordered by id ascending (oldest first), at most $limit.
     */
    public function stale(
        DateTimeImmutable $claimedBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array;

    /**
     * Executions still in `pending` state created before the cutoff:
     * candidates for a dispatch that was lost before reaching the queue.
     *
     * Pending is also the legitimate state of work created by start() and
     * deliberately not yet executed, which is why callers treat this as
     * opt-in (see laraflow.recovery.pending_after).
     *
     * @param  string|null  $workflow  Only executions created from this workflow class.
     * @param  string|null  $tenantId  Only executions for this tenant.
     * @return WorkflowExecution[] ordered by id ascending (oldest first), at most $limit.
     */
    public function pendingBefore(
        DateTimeImmutable $createdBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array;

    /**
     * Executions in `retrying` state whose next_retry_at is at or before
     * the cutoff: scheduled retries ready to run. Dispatching these is
     * race-safe — the claim still decides which worker proceeds.
     *
     * @param  string|null  $workflow  Only executions created from this workflow class.
     * @param  string|null  $tenantId  Only executions for this tenant.
     * @return WorkflowExecution[] ordered by next_retry_at ascending (most overdue first), at most $limit.
     */
    public function dueForRetry(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array;

    /**
     * Durably record a signal. For an execution waiting on exactly that
     * name, the payload becomes the waiting step's output and the
     * execution becomes due immediately, in one transaction. A second
     * signal before the execution resumes overwrites the payload: last
     * delivery wins, as at-least-once sources require.
     *
     * For an unfinished execution not (yet) parked on the name —
     * pending, running, retrying, or waiting on something else — the
     * signal is kept in the durable signal inbox instead: one entry per
     * execution and name, a later delivery overwriting an earlier one
     * (latest wins), consumed by the park() that waits on that name.
     * Nothing becomes due. Refused with SignalRefusedException when the
     * execution is completed, cancelled, failed, or compensating — no
     * park can follow — when the name is reserved (`laraflow.`: the
     * machinery's own signals are never buffered), or when the execution
     * does not exist. The caller bounds and serializes the payload (see
     * docs/advanced-workflows.md); recording does not deliver — the
     * queue fast path and laraflow:recover do.
     *
     * The receipt says which of the two happened: `buffered` is false
     * when a parked step took the payload, true when the inbox kept it.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws SignalRefusedException
     */
    public function recordSignal(
        int $id,
        string $signal,
        array $payload,
        DateTimeImmutable $at
    ): SignalReceipt;

    /**
     * Park an execution on the wait its claimed step returned, in one
     * claim-guarded transaction: $step (already marked waiting) and the
     * execution's context are written, and then either
     *
     * - $step waits on an application signal the inbox already holds
     *   (see recordSignal()): the buffered payload is removed from the
     *   inbox and written as the step's output — on the row and on
     *   $step — the execution stays `running` under this claim, nothing
     *   is released, and the payload is returned for the caller to
     *   resume the step now; or
     * - otherwise: $execution is marked waiting with $nextEventAt as its
     *   next instant (`next_retry_at`) and released, exactly as
     *   release() does, and null is returned.
     *
     * Nothing outside the transaction ever sees the step waiting on an
     * execution that is still running — the window that used to refuse
     * a signal arriving mid-park.
     *
     * @return array<array-key, mixed>|null The consumed payload, or null when the execution parked.
     *
     * @throws ClaimLostException when this worker no longer holds the claim.
     */
    public function park(
        WorkflowExecution $execution,
        StepExecution $step,
        ?DateTimeImmutable $nextEventAt
    ): ?array;

    /**
     * Executions a tenant ceiling has deferred past $now — their
     * delivery is being held, not their state — within the filter's
     * scope, soonest due first. The attention scan's "what is deferred
     * right now"; served by the deferred_until index.
     *
     * @return list<WorkflowExecution> at most $limit, bounded by MAX_PAGE_SIZE.
     */
    public function deferred(
        ExecutionFilter $filter,
        DateTimeImmutable $now,
        int $limit = 100
    ): array;

    /**
     * Waiting executions whose next event — a timed resume or a signal
     * deadline — is at or before the cutoff. Indefinite signal waits
     * (null next_retry_at) never appear; only a signal makes those
     * claimable. Dispatching these is race-safe: the claim still
     * decides which worker proceeds.
     *
     * @param  string|null  $workflow  Only executions created from this workflow class.
     * @param  string|null  $tenantId  Only executions for this tenant.
     * @return WorkflowExecution[] ordered by next_retry_at ascending, at most $limit.
     */
    public function waitingDue(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array;

    /**
     * Persist the step row and, when provided, append $attempt to the
     * history in the same transaction, verifying this worker still
     * holds the claim on $execution. Pairing the writes is the point:
     * history that could commit without its step state (or vice versa)
     * would be history that can lie. On claim loss nothing is written —
     * a worker that has been reclaimed must not mark a step the new
     * owner is running, nor append an attempt it no longer owns.
     *
     * @throws ClaimLostException
     */
    public function saveStep(
        WorkflowExecution $execution,
        StepExecution $step,
        ?StepAttempt $attempt = null
    ): void;

    /**
     * Persist a completed step together with the execution's context
     * snapshot — and, when provided, the attempt history row — in one
     * transaction, verifying this worker still holds the claim. On claim
     * loss nothing is written, the history row included.
     *
     * @throws ClaimLostException
     */
    public function checkpoint(
        WorkflowExecution $execution,
        StepExecution $step,
        ?StepAttempt $attempt = null
    ): void;

    /**
     * Finished executions eligible for one retention tier, oldest
     * finish first.
     *
     * Implementations must require a non-null `finished_at` at or
     * before $finishedBefore and a status in $statuses, and must apply
     * the tier's own self-limiting predicate — otherwise a scheduled
     * run rescans work it has already done and never reaches anything
     * newer:
     *
     * - `ScrubPayloads`: only executions not already scrubbed.
     * - `PruneAttempts`: only executions that still have attempt rows.
     * - `Delete`: none needed; deleted rows cannot match again.
     *
     * @param  list<ExecutionStatus>  $statuses
     * @param  int  $limit  Hard upper bound on rows returned.
     * @return WorkflowExecution[] ordered by finished_at ascending, at most $limit.
     */
    public function retentionCandidates(
        RetentionTier $tier,
        array $statuses,
        DateTimeImmutable $finishedBefore,
        int $limit,
        ?string $workflow = null,
        ?string $tenantId = null
    ): array;

    /**
     * Clear the input, context snapshot, and step outputs of the given
     * executions, and stamp `payloads_scrubbed_at`.
     *
     * $statuses and $finishedBefore are re-applied as a guard in the
     * same statement: an execution claimed between the scan and this
     * call has `status = running` and a null `finished_at`, so it is
     * silently left alone rather than having its payloads removed
     * underneath a running worker.
     *
     * @param  list<int>  $ids
     * @param  list<ExecutionStatus>  $statuses
     * @return int Executions actually scrubbed; fewer than count($ids) means
     *             the rest no longer qualified.
     */
    public function scrubPayloads(
        array $ids,
        array $statuses,
        DateTimeImmutable $finishedBefore,
        DateTimeImmutable $scrubbedAt
    ): int;

    /**
     * Delete the attempt history of the given executions, under the
     * same guard as scrubPayloads().
     *
     * @param  list<int>  $ids
     * @param  list<ExecutionStatus>  $statuses
     * @return array{executions: int, rows: int} Executions that qualified, and
     *                                           attempt rows removed.
     */
    public function pruneAttempts(
        array $ids,
        array $statuses,
        DateTimeImmutable $finishedBefore
    ): array;

    /**
     * Delete the given executions and everything belonging to them,
     * under the same guard as scrubPayloads().
     *
     * Implementations must leave no orphaned step or attempt rows, and
     * must not rely on the database enforcing the declared cascade:
     * SQLite only does so when the connection asks for it, and an
     * application may not have.
     *
     * @param  list<int>  $ids
     * @param  list<ExecutionStatus>  $statuses
     * @return int Executions actually deleted.
     */
    public function deleteExecutions(
        array $ids,
        array $statuses,
        DateTimeImmutable $finishedBefore
    ): int;

    /**
     * Record that the execution was handed to the queue at $dispatchedAt
     * and asked to become runnable at $availableAt — the same instant
     * for an immediate push, later for a delayed retry. Queue lag is
     * measured from `available_at`, not `dispatched_at`: a retry held
     * back by its own backoff has not been waiting for a worker.
     *
     * Not claim-guarded. These are delivery metadata, not execution
     * state: recovery dispatches a `running` execution deliberately,
     * and the write must succeed whether or not a (dead) worker's claim
     * is still on the row. Each dispatch overwrites the last, so the
     * columns always describe the most recent delivery.
     *
     * @throws ExecutionNotFoundException
     */
    public function recordDispatch(
        int $id,
        DateTimeImmutable $dispatchedAt,
        ?DateTimeImmutable $availableAt = null
    ): void;

    /**
     * Request cancellation, returning the resulting execution state.
     *
     * An execution nobody is running — `pending`, `failed`, `retrying` —
     * is cancelled outright by compare-and-set on its status, so the
     * cancellation cannot race a worker claiming it: either the claim
     * wins and this becomes a request, or this wins and the claim is
     * refused. Any scheduled retry is voided.
     *
     * A `running` execution instead gets `cancel_requested_at` set and
     * stays running: the owning worker honours it at its next step
     * boundary. Laraflow never yanks durable state out from under a live
     * worker, and cannot interrupt a step mid-flight.
     *
     * Cancelling an already-cancelled execution is an idempotent no-op.
     *
     * @param  DateTimeImmutable|null  $staleBefore  When given, a `running` execution
     *                                               whose claim is older than this is treated as unclaimed
     *                                               and cancelled outright — the claim() test, applied so an
     *                                               abandoned execution can be cancelled.
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotCancellableException when the execution has completed.
     */
    public function requestCancellation(
        int $id,
        ?string $reason,
        DateTimeImmutable $at,
        ?DateTimeImmutable $staleBefore = null
    ): WorkflowExecution;

    /**
     * Persist a claim-ending transition — completed, failed, cancelled,
     * waiting, or retrying
     * (which waits unclaimed until its due time) — and release the claim,
     * verifying this worker still holds it.
     *
     * @throws ClaimLostException
     */
    public function release(
        WorkflowExecution $execution
    ): void;
}
