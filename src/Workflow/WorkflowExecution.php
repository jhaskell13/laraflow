<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;
use Laraflow\Support\Duration;

/**
 * Read-only handle describing one persisted workflow execution.
 *
 * State changes must go through the mark*() transition methods, which
 * enforce the execution state machine:
 *
 *   pending → running → completed
 *   pending → running → failed → running (re-execute)
 *   running → retrying → running (scheduled retry, once due)
 *   running → waiting → running (a step's wait, resolved or due)
 *   running → running (stale reclaim by another worker)
 *   pending | running | failed | retrying → cancelled (terminal)
 *
 * Application code should treat all properties as read-only.
 */
class WorkflowExecution
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $workflow,
        public readonly array $input = [],
        public ExecutionStatus $status = ExecutionStatus::Pending,
        public array $context = [],
        public ?string $error = null,
        public ?string $errorClass = null,
        public ?string $claimToken = null,
        public ?DateTimeImmutable $claimedAt = null,
        public readonly ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $failedAt = null,
        public ?DateTimeImmutable $nextRetryAt = null,
        public ?DateTimeImmutable $cancelRequestedAt = null,
        public ?DateTimeImmutable $cancelledAt = null,
        public ?string $cancelReason = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
        public readonly int|string|null $parentExecutionId = null,
        public readonly int|string|null $parentStepExecutionId = null,
        public ?DateTimeImmutable $dispatchedAt = null,
        public ?DateTimeImmutable $availableAt = null,
        public ?DateTimeImmutable $finishedAt = null,
        public ?DateTimeImmutable $payloadsScrubbedAt = null,
        public readonly int $fruitlessClaims = 0,
        protected ?int $persistedDurationMs = null,
    ) {}

    public function markRunning(DateTimeImmutable $at): void
    {
        $this->assertTransition(ExecutionStatus::Running, [
            ExecutionStatus::Pending,
            ExecutionStatus::Failed,
            ExecutionStatus::Retrying,
            ExecutionStatus::Waiting,
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Running;
        $this->startedAt ??= $at;

        // Running consumes the schedule: whatever happens next writes a
        // fresh one, and a stale due time must not linger for the scan.
        $this->nextRetryAt = null;

        // This execution is no longer finished. Leaving a stale
        // finished_at behind would make a re-executed failure look
        // eligible to retention while it is actively running.
        $this->finishedAt = null;
        $this->persistedDurationMs = null;
    }

    public function markCompleted(DateTimeImmutable $at): void
    {
        $this->assertTransition(ExecutionStatus::Completed, [
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Completed;
        $this->completedAt = $at;
        $this->finishedAt = $at;
        $this->persistedDurationMs = Duration::between($this->startedAt, $at);
        $this->error = null;
        $this->errorClass = null;
    }

    public function markFailed(string $error, string $errorClass, DateTimeImmutable $at): void
    {
        $this->assertTransition(ExecutionStatus::Failed, [
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Failed;
        $this->failedAt = $at;
        $this->finishedAt = $at;
        $this->persistedDurationMs = Duration::between($this->startedAt, $at);
        $this->error = $error;
        $this->errorClass = $errorClass;
    }

    /**
     * A step attempt failed and its policy scheduled another. The error
     * is recorded so operators can see what the pending retry is for;
     * `failedAt` stays null because the execution has not failed.
     */
    public function markRetrying(
        string $error,
        string $errorClass,
        DateTimeImmutable $nextRetryAt
    ): void {
        $this->assertTransition(ExecutionStatus::Retrying, [
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Retrying;
        $this->nextRetryAt = $nextRetryAt;
        $this->error = $error;
        $this->errorClass = $errorClass;
    }

    /**
     * A step returned a Wait: park, with next_retry_at as the next
     * instant anything can happen — a timed wait's resume, a bounded
     * signal wait's deadline, or null for an indefinite signal wait.
     * The error columns clear: waiting is not an error state, and a
     * prior retry's diagnosis must not outlive the attempt it described.
     */
    public function markWaiting(?DateTimeImmutable $nextEventAt): void
    {
        $this->assertTransition(ExecutionStatus::Waiting, [
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Waiting;
        $this->nextRetryAt = $nextEventAt;
        $this->error = null;
        $this->errorClass = null;
    }

    public function isWaiting(): bool
    {
        return $this->status === ExecutionStatus::Waiting;
    }

    /**
     * Cancellation is terminal and valid from every non-terminal state.
     * A `running` execution reaches here cooperatively: the owning
     * worker notices the request at a step boundary and transitions.
     */
    public function markCancelled(DateTimeImmutable $at, ?string $reason = null): void
    {
        $this->assertTransition(ExecutionStatus::Cancelled, [
            ExecutionStatus::Pending,
            ExecutionStatus::Running,
            ExecutionStatus::Failed,
            ExecutionStatus::Retrying,
            ExecutionStatus::Waiting,
        ]);

        $this->status = ExecutionStatus::Cancelled;
        $this->cancelledAt = $at;
        $this->finishedAt = $at;
        $this->persistedDurationMs = Duration::between($this->startedAt, $at);
        $this->cancelReason = $reason;

        // A cancelled execution must never be picked up by the scan.
        $this->nextRetryAt = null;
    }

    /**
     * Wall-clock milliseconds from the execution's first start to its
     * finish, or null while it is unfinished (or was cancelled before
     * it ever started).
     *
     * This is **end-to-end latency, not compute time**. `startedAt` is
     * the first start and is preserved across re-executions, so for an
     * execution that failed and was recovered, or that backed off
     * between retries, this span includes the time it spent waiting —
     * which is what an SLA measures and what a "how long did the
     * customer wait?" question means. Per-attempt compute time is on
     * the step events and in the attempt history.
     *
     * Captured at the finishing transition, while both instants are
     * still in memory, and persisted. Recomputing it from the reloaded
     * timestamps would report whole seconds, because that is all the
     * columns hold — and so 0 ms for most executions.
     */
    public function durationMs(): ?int
    {
        return $this->persistedDurationMs
            ?? Duration::between($this->startedAt, $this->finishedAt);
    }

    /**
     * Whether the execution has stopped and recorded `finished_at`.
     * See ExecutionStatus::isFinished() for why this is not "terminal".
     */
    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * Whole seconds the execution waited for a worker: from the most
     * recent time it was made available to the queue until $claimedAt.
     *
     * Null when it was never dispatched (an in-process run) or when
     * $claimedAt precedes availability (an operator running a scheduled
     * retry early) — in neither case did it wait for a worker, and
     * reporting zero would be a measurement, not an absence of one.
     */
    public function queueLagSeconds(DateTimeImmutable $claimedAt): ?int
    {
        if ($this->availableAt === null || $claimedAt < $this->availableAt) {
            return null;
        }

        return $claimedAt->getTimestamp() - $this->availableAt->getTimestamp();
    }

    public function isPending(): bool
    {
        return $this->status === ExecutionStatus::Pending;
    }

    public function isRunning(): bool
    {
        return $this->status === ExecutionStatus::Running;
    }

    public function isCompleted(): bool
    {
        return $this->status === ExecutionStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === ExecutionStatus::Failed;
    }

    public function isRetrying(): bool
    {
        return $this->status === ExecutionStatus::Retrying;
    }

    public function isCancelled(): bool
    {
        return $this->status === ExecutionStatus::Cancelled;
    }

    /**
     * @param  ExecutionStatus[]  $allowedFrom
     */
    protected function assertTransition(ExecutionStatus $to, array $allowedFrom): void
    {
        if (! in_array($this->status, $allowedFrom, true)) {
            throw InvalidStateTransitionException::between('execution', $this->status, $to);
        }
    }
}
