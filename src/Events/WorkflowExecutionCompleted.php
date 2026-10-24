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
     * @param  string|null  $correlationId  Application-supplied identifier
     *                                      recorded on the execution, or null if none was supplied.
     * @param  string|null  $tenantId  Application-supplied tenant identifier,
     *                                 or null if none was supplied.
     * @param  int|null  $durationMs  Wall-clock milliseconds from the execution's
     *                                first start to this finish. End-to-end latency, not compute
     *                                time: it includes any time spent failed, cancelled-pending, or
     *                                waiting out a retry backoff. Null when the execution never
     *                                started.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public DateTimeImmutable $completedAt,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
        public ?int $durationMs = null,
    ) {}
}
