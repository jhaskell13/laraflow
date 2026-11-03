<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * An execution failed terminally and has steps to undo: the reverse
 * pass is beginning. Fired after the execution is durably
 * `compensating`; carries how many steps will be undone, never their
 * outputs.
 */
readonly class WorkflowCompensationStarted
{
    public function __construct(
        public int $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public int $steps,
        public string $failure,
    ) {}
}
