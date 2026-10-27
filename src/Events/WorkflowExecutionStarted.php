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
     * @param  int|null  $queueLagSeconds  Whole seconds between the most recent
     *                                     time the execution was made available to the queue and this
     *                                     claim. Null when the execution was never dispatched (run
     *                                     in-process) or was claimed ahead of its availability (an
     *                                     operator running a scheduled retry early): in neither case
     *                                     did it wait for a worker. For an execution recovered
     *                                     in-process after a lost dispatch, this is how long the work
     *                                     sat undelivered — which is what an operator wants to know.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public DateTimeImmutable $startedAt,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
        public ?int $queueLagSeconds = null,
    ) {}
}
