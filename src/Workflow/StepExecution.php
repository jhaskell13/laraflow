<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;

/**
 * Read-only handle describing one step within a workflow execution.
 *
 * State changes must go through the mark*() transition methods, which
 * enforce the step state machine:
 *
 *   pending → running → completed
 *   pending → running → failed → running (retry on re-execute)
 *   running → running (interrupted step re-run after worker loss)
 *
 * A step found in `running` during recovery is treated as interrupted:
 * it may already have performed its side effects once, and will run
 * again. This is the at-least-once boundary.
 */
class StepExecution
{
    public function __construct(
        public readonly int|string $id,
        public readonly int|string $executionId,
        public readonly string $step,
        public readonly int $position,
        public StepStatus $status = StepStatus::Pending,
        public int $attempts = 0,
        public readonly mixed $input = null,
        public mixed $output = null,
        public ?string $error = null,
        public ?string $errorClass = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $nextRetryAt = null,
    ) {}

    public function markRunning(DateTimeImmutable $at): void
    {
        $this->assertTransition(StepStatus::Running, [
            StepStatus::Pending,
            StepStatus::Failed,
            StepStatus::Running,
        ]);

        $this->status = StepStatus::Running;
        $this->attempts++;
        $this->startedAt = $at;
    }

    public function markCompleted(mixed $output, DateTimeImmutable $at): void
    {
        $this->assertTransition(StepStatus::Completed, [
            StepStatus::Running,
        ]);

        $this->status = StepStatus::Completed;
        $this->output = $output;
        $this->completedAt = $at;
        $this->error = null;
        $this->errorClass = null;
    }

    public function markFailed(string $error, string $errorClass, DateTimeImmutable $at): void
    {
        $this->assertTransition(StepStatus::Failed, [
            StepStatus::Running,
        ]);

        $this->status = StepStatus::Failed;
        $this->error = $error;
        $this->errorClass = $errorClass;
    }

    public function isPending(): bool
    {
        return $this->status === StepStatus::Pending;
    }

    public function isRunning(): bool
    {
        return $this->status === StepStatus::Running;
    }

    public function isCompleted(): bool
    {
        return $this->status === StepStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === StepStatus::Failed;
    }

    /**
     * @param  StepStatus[]  $allowedFrom
     */
    protected function assertTransition(StepStatus $to, array $allowedFrom): void
    {
        if (! in_array($this->status, $allowedFrom, true)) {
            throw InvalidStateTransitionException::between('step', $this->status, $to);
        }
    }
}
