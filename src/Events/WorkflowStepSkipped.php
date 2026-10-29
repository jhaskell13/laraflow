<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A conditional step's condition answered false and the step was
 * durably skipped: no attempt counted, nothing contributed to the
 * context. Fired after the durable write, like every lifecycle event.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context (see docs/execution-lifecycle.md).
 */
readonly class WorkflowStepSkipped
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string  $step  Step class name.
     * @param  int  $position  Zero-based position in the plan.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public string $step,
        public int $position,
    ) {}
}
