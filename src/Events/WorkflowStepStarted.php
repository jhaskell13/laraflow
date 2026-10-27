<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A step was durably marked running and is about to execute.
 *
 * Under the at-least-once contract a step can start more than once;
 * `attempt` distinguishes the runs.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context (see docs/execution-lifecycle.md).
 */
readonly class WorkflowStepStarted
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string  $step  Step class name.
     * @param  int  $position  Zero-based position in the step plan.
     * @param  int  $attempt  Attempt number, starting at 1.
     * @param  string|null  $correlationId  Application-supplied identifier
     *                                      recorded on the execution, or null if none was supplied.
     * @param  string|null  $tenantId  Application-supplied tenant identifier,
     *                                 or null if none was supplied.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public string $step,
        public int $position,
        public int $attempt,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
