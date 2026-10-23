<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * Every step completed and the execution was durably marked completed.
 * This is terminal: it fires exactly once per execution.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context (see docs/execution-lifecycle.md).
 */
readonly class WorkflowExecutionCompleted
{
    /**
     * @param  string  $workflow  Workflow class name.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public DateTimeImmutable $completedAt,
    ) {}
}
