<?php

namespace Laraflow\Workflow;

class WorkflowExecution
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $workflow,
        public readonly array $input = [],
        public ExecutionStatus $status = ExecutionStatus::Pending,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?\DateTimeImmutable $failedAt = null,
    ) {

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
}