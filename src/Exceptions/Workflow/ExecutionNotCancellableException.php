<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use Laraflow\Workflow\ExecutionStatus;

/**
 * Cancellation was requested for an execution that has already
 * finished. Cancelling an already-cancelled execution is not an error —
 * it is an idempotent no-op — so in practice this means `completed`.
 */
class ExecutionNotCancellableException extends WorkflowException
{
    public static function forStatus(int|string $id, ExecutionStatus $status): self
    {
        return new self(
            "Workflow execution [{$id}] cannot be cancelled from status [{$status->value}]: "
            .'it has already finished.'
        );
    }
}
