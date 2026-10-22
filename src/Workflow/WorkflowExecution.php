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
 *   running → running (stale reclaim by another worker)
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
    ) {}

    public function markRunning(DateTimeImmutable $at): void
    {
        $this->assertTransition(ExecutionStatus::Running, [
            ExecutionStatus::Pending,
            ExecutionStatus::Failed,
            ExecutionStatus::Running,
        ]);

        $this->status = ExecutionStatus::Running;
        $this->startedAt ??= $at;
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
