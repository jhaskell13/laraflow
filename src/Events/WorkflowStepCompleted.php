<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * A step completed and its checkpoint (output + context snapshot)
 * committed. Fired strictly after the checkpoint transaction, so a
 * listener can rely on the durable state it describes.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context (see docs/execution-lifecycle.md).
 */
readonly class WorkflowStepCompleted
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
        public DateTimeImmutable $completedAt,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
