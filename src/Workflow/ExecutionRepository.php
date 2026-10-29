<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;

/**
 * Durable storage for workflow executions.
 *
 * Implementations must satisfy the guarantees documented in
 * docs/execution-lifecycle.md: atomic plan creation, compare-and-set
 * claiming, and transactional, claim-verified checkpoints.
 */
interface ExecutionRepository
{
    /**
     * The most executions list() will ever return in one page, whatever
     * limit the caller asked for. Observability tooling that can ask
     * for an unbounded result is observability tooling that can take an
     * application down; the bound lives in the contract so no caller
     * can be surprised by an implementation that forgot it.
     */
    public const MAX_PAGE_SIZE = 500;

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
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = [],
        ?ExecutionOptions $options = null
    ): WorkflowExecution;

    /**
     * @throws ExecutionNotFoundException
     */
    public function find(
        int|string $id
    ): WorkflowExecution;

    /**
     * Atomically claim an execution for this worker (compare-and-set).
     *
     * Succeeds when the execution is `pending`, `failed`, `retrying`
     * with `next_retry_at` due, `waiting` with a non-null, due
     * `next_retry_at` (or, for both, $ignoreRetrySchedule set — the
     * deliberate operator override), or `running` with a claim older
     * than $staleAfterSeconds. Exactly one concurrent claimant can
     * succeed. The returned handle's `resumedFrom` records the status
     * the claim took the execution from.
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotRunnableException when the execution is completed or cancelled.
     * @throws ExecutionAlreadyClaimedException when another worker holds a fresh claim.
     * @throws RetryNotDueException when the execution is retrying before its due time.
     * @throws ExecutionStillWaitingException when the execution is parked on a wait that is not due.
     */
    public function claim(
        int|string $id,
        string $claimToken,
        int $staleAfterSeconds,
        bool $ignoreRetrySchedule = false
    ): WorkflowExecution;

    /**
     * @return StepExecution[] ordered by position.
     */
    public function steps(
        WorkflowExecution $execution
    ): array;

    /**
     * One page of executions matching $filter, ordered by id (newest
     * first unless the filter says otherwise), paginated by keyset.
     *
     * $limit is clamped to [1, MAX_PAGE_SIZE]; the page reports the
     * limit actually applied. $cursor is the `nextCursor` of a previous
     * page and is opaque to callers; an implementation may reject a
     * cursor it did not issue.
     *
     * Every filter criterion must be served from an index — this is the
     * stable listing API for applications and consoles, and it must stay
     * bounded on a high-volume table.
     *
     * @throws InvalidArgumentException for a malformed cursor.
     */
    public function list(
        ExecutionFilter $filter,
        int $limit = 50,
        ?string $cursor = null
    ): ExecutionPage;

    /**
     * How many executions match $filter. Ordering and pagination do not
     * apply; the count is of the whole matching set.
     */
    public function count(
        ExecutionFilter $filter
    ): int;

    /**
     * Step counts for several executions in one query: how many steps
     * each has, and how many have completed.
     *
     * A listing that shows progress would otherwise call steps() per
     * row. The ids are those of executions the caller already holds;
     * ids with no steps are absent from the result rather than present
     * as zero, so a caller can tell "no plan" from "nothing done".
     *
     * @param  list<int|string>  $ids
     * @return array<string, array{total: int, completed: int, skipped: int}> keyed by execution id.
     */
    public function stepProgress(
        array $ids
    ): array;

    /**
     * Attempt history for every step of one execution in a single
     * query, ordered by step position then attempt number. The same rows
     * attempts() returns per step, without one query per step.
     *
     * @return StepAttempt[]
     */
    public function attemptHistory(
        WorkflowExecution $execution
    ): array;

    /**
     * Executions in `running` state whose claim is older than the cutoff
     * (or missing entirely): the signature a worker leaves when it dies.
     *
     * The cutoff is passed in rather than computed here so implementations
     * stay clock-free; it must use the same `claimed_at <= cutoff`
     * comparison as claim(), so the two mechanisms agree on staleness.
     *
     * @param  string|null  $workflow  Only executions created from this workflow class.
     * @return WorkflowExecution[] ordered by id ascending (oldest first), at most $limit.
     */
    public function stale(
        DateTimeImmutable $claimedBefore,
        int $limit = 100,
        ?string $workflow = null
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
     * @return WorkflowExecution[] ordered by id ascending (oldest first), at most $limit.
     */
    public function pendingBefore(
        DateTimeImmutable $createdBefore,
        int $limit = 100,
        ?string $workflow = null
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
        ?string $tenantId = null
    ): array;

    /**
     * Durably record a signal for an execution waiting on exactly that
     * name: the payload becomes the waiting step's output and the
     * execution becomes due immediately, in one transaction. Refused
     * with SignalRefusedException when the execution is not waiting on
     * that signal — resumed, timed out, finished, or a different name.
     * A second signal before the execution resumes overwrites the
     * payload: last delivery wins, as at-least-once sources require.
     * The caller bounds and serializes the payload (see
     * docs/advanced-workflows.md); recording does not deliver — the
     * queue fast path and laraflow:recover do.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws SignalRefusedException
     * @throws ExecutionNotFoundException
     */
    public function recordSignal(
        int|string $id,
        string $signal,
        array $payload,
        DateTimeImmutable $at
    ): WorkflowExecution;

    /**
     * Waiting executions whose next event — a timed resume or a signal
     * deadline — is at or before the cutoff. Indefinite signal waits
     * (null next_retry_at) never appear; only a signal makes those
     * claimable. Dispatching these is race-safe: the claim still
     * decides which worker proceeds.
     *
     * @param  string|null  $workflow  Only executions created from this workflow class.
     * @return WorkflowExecution[] ordered by next_retry_at ascending, at most $limit.
     */
    public function waitingDue(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null
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
     * @param  list<int|string>  $ids
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
     * @param  list<int|string>  $ids
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
     * @param  list<int|string>  $ids
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
        int|string $id,
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
        int|string $id,
        ?string $reason,
        DateTimeImmutable $at,
        ?DateTimeImmutable $staleBefore = null
    ): WorkflowExecution;

    /**
     * Attempt history for one step, ordered by attempt number ascending.
     *
     * @return StepAttempt[]
     */
    public function attempts(
        StepExecution $step
    ): array;

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
