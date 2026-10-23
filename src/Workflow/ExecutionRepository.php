<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;

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
     * Create an execution and its complete step plan atomically.
     *
     * Every step is recorded in `pending` state before anything runs, so
     * the persisted plan is a complete snapshot of the workflow definition
     * regardless of when or whether execution happens.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $steps  Ordered step class names.
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = []
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
     * with `next_retry_at` due (or $ignoreRetrySchedule set — the
     * deliberate operator override), or `running` with a claim older
     * than $staleAfterSeconds. Exactly one concurrent claimant can
     * succeed.
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotRunnableException when the execution is completed or cancelled.
     * @throws ExecutionAlreadyClaimedException when another worker holds a fresh claim.
     * @throws RetryNotDueException when the execution is retrying before its due time.
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
     * @return WorkflowExecution[] ordered by next_retry_at ascending (most overdue first), at most $limit.
     */
    public function dueForRetry(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null
    ): array;

    /**
     * Persist the step row and, when provided, append $attempt to the
     * history in the same transaction. Pairing the writes is the point:
     * history that could commit without its step state (or vice versa)
     * would be history that can lie.
     */
    public function saveStep(
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
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotCancellableException when the execution has completed.
     */
    public function requestCancellation(
        int|string $id,
        ?string $reason,
        DateTimeImmutable $at
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
     * Persist a claim-ending transition — completed, failed, or retrying
     * (which waits unclaimed until its due time) — and release the claim,
     * verifying this worker still holds it.
     *
     * @throws ClaimLostException
     */
    public function release(
        WorkflowExecution $execution
    ): void;
}
