<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * The execution was durably marked cancelled — terminal, fired once per
 * execution.
 *
 * For a `running` execution this fires from the owning worker when it
 * honours the request at a step boundary, not when the request is made:
 * like every Laraflow lifecycle event it describes a durable write that
 * has already happened, and until the worker stops, nothing has.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context. `reason` is the free text supplied by
 * whoever requested the cancellation.
 */
readonly class WorkflowExecutionCancelled
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
        public DateTimeImmutable $cancelledAt,
        public ?string $reason = null,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
        public ?int $durationMs = null,
    ) {}
}
