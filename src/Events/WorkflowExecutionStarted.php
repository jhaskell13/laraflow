<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * A worker claimed the execution and is about to run its remaining steps.
 *
 * Fired after the claim is durably persisted. This event can fire more
 * than once for one execution: recovery of a failed or interrupted
 * execution claims it again. `startedAt` is always the *first* start.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context (see docs/execution-lifecycle.md).
 */
readonly class WorkflowExecutionStarted
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string|null  $correlationId  Application-supplied identifier
     *                                      recorded on the execution, or null if none was supplied.
     * @param  string|null  $tenantId  Application-supplied tenant identifier,
     *                                 or null if none was supplied.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public DateTimeImmutable $startedAt,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
