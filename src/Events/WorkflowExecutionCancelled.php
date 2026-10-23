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
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public DateTimeImmutable $cancelledAt,
        public ?string $reason = null,
    ) {}
}
