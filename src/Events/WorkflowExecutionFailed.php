<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * A step failure was durably persisted and the execution marked failed.
 *
 * Failed is not terminal: the execution can be re-executed (manually or
 * via recovery), so this event can fire more than once for one execution.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context. `error` is the exception message
 * (truncated to laraflow.execution.max_error_length), or null when
 * laraflow.events.include_error_messages is disabled.
 */
readonly class WorkflowExecutionFailed
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string  $errorClass  Class of the exception the failing step threw.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public string $errorClass,
        public ?string $error,
        public DateTimeImmutable $failedAt,
    ) {}
}
