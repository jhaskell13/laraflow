<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A signal was durably recorded for a waiting execution. The payload is
 * never on the event — it is the waiting step's output, read through
 * the repository once the execution resumes.
 */
readonly class WorkflowExecutionSignalled
{
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public string $signal,
    ) {}
}
