<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * A step attempt failed retryably and the next attempt was durably
 * scheduled: step and execution are in `retrying` state with
 * `next_retry_at` set and the claim released.
 *
 * Fired after WorkflowStepFailed for the attempt itself. No
 * WorkflowExecutionFailed follows, because the execution has not failed
 * — it is waiting. That event remains terminal-only, so alerting built
 * on it stays meaningful when retries are enabled.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context. `error` is the exception message
 * (truncated to laraflow.execution.max_error_length), or null when
 * laraflow.events.include_error_messages is disabled.
 */
readonly class WorkflowStepRetryScheduled
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string  $step  Step class name.
     * @param  int  $position  Zero-based position in the step plan.
     * @param  int  $attempt  The attempt that just failed, starting at 1.
     * @param  int  $maxAttempts  The policy's total attempt budget.
     * @param  string  $errorClass  Class of the exception the attempt threw.
     * @param  int  $delaySeconds  Backoff applied before the next attempt.
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
        public int $maxAttempts,
        public string $errorClass,
        public ?string $error,
        public DateTimeImmutable $nextRetryAt,
        public int $delaySeconds,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
