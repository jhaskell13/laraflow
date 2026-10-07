<?php

namespace Laraflow\Workflow;

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
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?\DateTimeImmutable $nextRetryAt = null,
    ) {

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
}