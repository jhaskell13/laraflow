<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A step threw and its failure was durably persisted. Followed by a
 * WorkflowExecutionFailed event for the owning execution.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context. `error` is the exception message
 * (truncated to laraflow.execution.max_error_length), or null when
 * laraflow.events.include_error_messages is disabled.
 */
readonly class WorkflowStepFailed
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string  $step  Step class name.
     * @param  int  $position  Zero-based position in the step plan.
     * @param  int  $attempt  Attempt number, starting at 1.
     * @param  string  $errorClass  Class of the exception the step threw.
     * @param  string|null  $correlationId  Application-supplied identifier
     *                                      recorded on the execution, or null if none was supplied.
     * @param  string|null  $tenantId  Application-supplied tenant identifier,
     *                                 or null if none was supplied.
     * @param  int|null  $durationMs  Wall-clock milliseconds this attempt ran,
     *                                or null when it had no recorded end.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public string $step,
        public int $position,
        public int $attempt,
        public string $errorClass,
        public ?string $error = null,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
        public ?int $durationMs = null,
    ) {}
}
