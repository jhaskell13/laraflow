<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;

/**
 * Read-only handle describing one persisted workflow execution.
 *
 * State changes must go through the mark*() transition methods, which
 * enforce the execution state machine:
 *
 *   pending → running → completed
 *   pending → running → failed → running (re-execute)
 *   running → retrying → running (scheduled retry, once due)
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
    ) {}

    public function markRunning(DateTimeImmutable $at): void
    {
        $this->assertTransition(ExecutionStatus::Running, [
            ExecutionStatus::Pending,
            ExecutionStatus::Failed,
            ExecutionStatus::Retrying,
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Running;
        $this->startedAt ??= $at;

        // Running consumes the schedule: whatever happens next writes a
        // fresh one, and a stale due time must not linger for the scan.
        $this->nextRetryAt = null;
    }

    public function markCompleted(DateTimeImmutable $at): void
    {
        $this->assertTransition(ExecutionStatus::Completed, [
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Completed;
        $this->completedAt = $at;
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
        ]);

        $this->status = ExecutionStatus::Cancelled;
        $this->cancelledAt = $at;
        $this->cancelReason = $reason;

        // A cancelled execution must never be picked up by the scan.
        $this->nextRetryAt = null;
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
